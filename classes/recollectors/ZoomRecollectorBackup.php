<?php
/**
 * Zoom Recollector Backup - Handles recording management and backup operations
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ortattendance\recollectors;

use mod_ortattendance\services\BackupService;
use mod_ortattendance\utils\LogLevel;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/BaseRecollector.php');
require_once(__DIR__ . '/../services/BackupService.php');
require_once(__DIR__ . '/../utils/LogLevel.php');

class ZoomRecollectorBackup extends BaseRecollector {

    private $courseId;
    private $courseShortname;

    public function __construct($courseId) {
        global $DB;

        $this->courseId = $courseId;

        // Get course shortname for better logging
        $course = $DB->get_record('course', ['id' => $courseId], 'shortname', IGNORE_MISSING);
        $this->courseShortname = $course ? $course->shortname : "course-{$courseId}";
    }

    /**
     * Get formatted course identifier for logging
     */
    private function getCourseLogPrefix(): string {
        return "[Course {$this->courseId} ({$this->courseShortname})]";
    }

    // ==================== RECORDING MANAGEMENT FUNCTIONALITY ====================
    
    /**
     * Process recordings for meetings
     *
     * @param array $meetingIds Array of meeting IDs to process
     * @return void
     */
    public function processRecordings($meetingIds = []): void {
        global $DB;

        $logPrefix = $this->getCourseLogPrefix();

        if (empty($meetingIds)) {
            LogLevel::warning("No meeting IDs provided for recording processing", $logPrefix);
            return;
        }

        foreach ($meetingIds as $meetingId) {
            $config = $DB->get_record('ortattendance', ['course' => $this->courseId], '*');

            if (!$config) {
                LogLevel::warning("No ortattendance config found", $logPrefix);
                continue;
            }

            if (!$config->backup_recordings) {
                continue;
            }

            $meetingDetails = $this->getMeetingDetails($meetingId);
            if (!$meetingDetails) {
                LogLevel::warning("Meeting details not found for meeting ID: {$meetingId}", $logPrefix);
                continue;
            }

            LogLevel::info("Processing recording for meeting: {$meetingDetails->name}", $logPrefix);

            $result = BackupService::processRecording(
                $meetingId,
                $meetingDetails->name,
                $meetingDetails->start_time,
                $this->courseId,
                (bool)$config->delete_from_source
            );

            if ($result['success']) {
                LogLevel::info("Success: Backed up to {$result['localPath']}", $logPrefix);
            } else {
                LogLevel::error("Backup failed: {$result['error']}", $logPrefix);
            }
        }
    }
    
    /**
     * Get meeting details from database
     *
     * @param string $meetingId Meeting ID
     * @return object|false Meeting details or false if not found
     */
    private function getMeetingDetails($meetingId): object|false {
        global $DB;

        $logPrefix = $this->getCourseLogPrefix();

        try {
            $sql = "SELECT * FROM {zoom_meeting_details} WHERE meeting_id = :meetingid LIMIT 1";
            $meeting = $DB->get_record_sql($sql, ['meetingid' => $meetingId]);

            if ($meeting) {
                // Create object with name property for compatibility
                $details = new \stdClass();
                $details->name = $meeting->topic ?? 'Unknown Meeting';
                $details->start_time = $meeting->start_time ?? time();
                $details->meeting_id = $meeting->meeting_id;
                return $details;
            }

            return false;
        } catch (\Exception $e) {
            LogLevel::error("Error fetching meeting details for {$meetingId}: " . $e->getMessage(), $logPrefix);
            return false;
        }
    }

    /**
     * Get the name of this recollector
     *
     * @return string Recollector name
     */
    public static function getName(): string {
        return 'Zoom Recollector Backup';
    }
    
    /**
     * Get the type identifier of this recollector
     *
     * @return string Recollector type
     */
    public static function getType(): string {
        return 'zoom_backup';
    }
}