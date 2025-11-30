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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/BaseRecollector.php');
require_once(__DIR__ . '/../services/BackupService.php');

class ZoomRecollectorBackup extends BaseRecollector {

    private $courseId;

    public function __construct($courseId) {
        $this->courseId = $courseId;
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
        
        if (empty($meetingIds)) {
            mtrace("ZoomRecollectorBackup: No meeting IDs provided for recording processing");
            return;
        }
        
        foreach ($meetingIds as $meetingId) {
            $config = $DB->get_record('ortattendance', ['course' => $this->courseId], '*');

            if (!$config) {
                mtrace("WARNING: No ortattendance config found for course {$this->courseId}");
                continue;
            }

            if (!$config->backup_recordings) {
                continue;
            }

            $meetingDetails = $this->getMeetingDetails($meetingId);
            if (!$meetingDetails) {
                mtrace("WARNING: Meeting details not found for meeting ID: {$meetingId}");
                continue;
            }
            
            mtrace("Processing recording for meeting: {$meetingDetails->name}");
            
            $result = BackupService::processRecording(
                $meetingId,
                $meetingDetails->name,
                $meetingDetails->start_time,
                $this->courseId,
                (bool)$config->delete_from_source
            );
            
            if ($result['success']) {
                mtrace("  Success: Backed up to {$result['localPath']}");
            } else {
                mtrace("  Error: {$result['error']}");
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
        $sql = "SELECT * FROM {zoom_meeting_details} WHERE meeting_id = :meetingid LIMIT 1";
        $meeting = $DB->get_record_sql($sql, ['meetingid' => $meetingId]);
        
        if ($meeting) {
            // Create object with name property for compatibility
            $details = new \stdClass();
            $details->name = $meeting->topic;
            $details->start_time = $meeting->start_time;
            $details->meeting_id = $meeting->meeting_id;
            return $details;
        }
        
        return false;
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