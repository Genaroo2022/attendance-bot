<?php
namespace mod_ortattendance\services;

defined('MOODLE_INTERNAL') || die();

class QueueService {
    
    const PRIORITY_YESTERDAY = 1;
    const PRIORITY_RETROACTIVE = 10;
    
    public static function addMeetings($botId, $meetings, $priority = self::PRIORITY_RETROACTIVE) {
        global $DB;
        
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
        return $DB->get_records('ortattendance_queue', [
            'bot_id' => $botId,
            'processed' => 0
        ], 'priority ASC, timecreated ASC', '*', 0, $limit);
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
}
