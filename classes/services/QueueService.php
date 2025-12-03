<?php
namespace mod_ortattendance\services;

use mod_ortattendance\utils\LogLevel;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../utils/LogLevel.php');

class QueueService {
    
    const PRIORITY_YESTERDAY = 1;
    const PRIORITY_RETROACTIVE = 10;
    
    public static function addMeetings($botId, $meetings, $priority = self::PRIORITY_RETROACTIVE) {
        global $DB;

        $context = "[BotID {$botId}]";

        // Defensive: Validate input is array
        if (!is_array($meetings)) {
            LogLevel::warning("QueueService::addMeetings called with non-array parameter", $context);
            return ['queued' => 0, 'skipped' => 0];
        }

        $queued = 0;
        $skipped = 0;

        foreach ($meetings as $meeting) {
            $uuid = $meeting->uuid ?? $meeting['uuid'] ?? null;
            
            if (!$uuid) {
                $skipped++;
                continue;
            }
            
            if ($DB->record_exists('ortattendance_queue', ['bot_id' => $botId, 'uuid' => $uuid])) {
                $skipped++;
                continue;
            }
            
            $record = new \stdClass();
            $record->bot_id = $botId;
            $record->uuid = $uuid;
            $record->priority = $priority;
            $record->processed = 0;
            $record->error = null;
            $record->timecreated = time();
            
            $DB->insert_record('ortattendance_queue', $record);
            $queued++;
        }
        
        return ['queued' => $queued, 'skipped' => $skipped];
    }
    
    public static function getPending($botId, $limit = 10) {
        global $DB;

        $context = "[BotID {$botId}]";
        $results = $DB->get_records('ortattendance_queue', [
            'bot_id' => $botId,
            'processed' => 0
        ], 'priority ASC, timecreated ASC', '*', 0, $limit);

        // Defensive: Ensure we always return an array
        if (!is_array($results)) {
            LogLevel::warning("QueueService::getPending query returned non-array", $context);
            return [];
        }

        return $results;
    }
    
    public static function markProcessed($queueId) {
        global $DB;
        $DB->set_field('ortattendance_queue', 'processed', 1, ['id' => $queueId]);
    }
    
    public static function markError($queueId, $error) {
        global $DB;
        $DB->set_field('ortattendance_queue', 'error', $error, ['id' => $queueId]);
    }
    
    public static function countPending($botId) {
        global $DB;
        return $DB->count_records('ortattendance_queue', [
            'bot_id' => $botId,
            'processed' => 0
        ]);
    }

    // ==================== BACKUP QUEUE METHODS ====================

    /**
     * Add recordings to backup queue
     *
     * @param int $botId The ortattendance instance ID
     * @param array $recordings Array of recording objects with meeting_id and meeting_name
     * @return array Array with 'queued' and 'skipped' counts
     */
    public static function addRecordingsToBackup($botId, $recordings) {
        global $DB;

        $context = "[BotID {$botId}]";

        // Defensive: Validate input is array
        if (!is_array($recordings)) {
            LogLevel::warning("QueueService::addRecordingsToBackup called with non-array parameter", $context);
            return ['queued' => 0, 'skipped' => 0];
        }

        $queued = 0;
        $skipped = 0;

        foreach ($recordings as $recording) {
            try {
                $meetingId = $recording->meeting_id ?? $recording['meeting_id'] ?? null;
                $meetingName = $recording->meeting_name ?? $recording['meeting_name'] ?? null;

                if (!$meetingId) {
                    $skipped++;
                    continue;
                }

                // Skip if already queued
                if ($DB->record_exists('ortattendance_backup', ['bot_id' => $botId, 'meeting_id' => $meetingId])) {
                    $skipped++;
                    continue;
                }

                $record = new \stdClass();
                $record->bot_id = $botId;
                $record->meeting_id = $meetingId;
                $record->meeting_name = $meetingName;
                $record->processed = 0;
                $record->timecreated = time();

                $DB->insert_record('ortattendance_backup', $record);
                $queued++;
            } catch (\Exception $e) {
                LogLevel::error("Failed to queue recording {$meetingId}: " . $e->getMessage(), $context);
                $skipped++;
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Get pending backup recordings
     *
     * @param int $botId The ortattendance instance ID
     * @param int $limit Maximum number of records to return
     * @return array Array of pending backup records
     */
    public static function getPendingBackups($botId, $limit = 10) {
        global $DB;

        $context = "[BotID {$botId}]";
        $results = $DB->get_records('ortattendance_backup', [
            'bot_id' => $botId,
            'processed' => 0
        ], 'timecreated ASC', '*', 0, $limit);

        // Defensive: Ensure we always return an array
        if (!is_array($results)) {
            LogLevel::warning("QueueService::getPendingBackups query returned non-array", $context);
            return [];
        }

        return $results;
    }

    /**
     * Mark a backup recording as processed
     *
     * @param int $backupId The backup queue record ID
     */
    public static function markBackupProcessed($backupId) {
        global $DB;
        $DB->set_field('ortattendance_backup', 'processed', 1, ['id' => $backupId]);
    }

    /**
     * Count pending backup recordings
     *
     * @param int $botId The ortattendance instance ID
     * @return int Number of pending backups
     */
    public static function countPendingBackups($botId) {
        global $DB;
        return $DB->count_records('ortattendance_backup', [
            'bot_id' => $botId,
            'processed' => 0
        ]);
    }
}
