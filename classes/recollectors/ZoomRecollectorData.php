<?php
/**
 * Zoom Recollector - Handles both attendance collection and recording management
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ortattendance\recollectors;

use mod_ortattendance\utils\StudentAttendance;
use mod_ortattendance\utils\TeacherAttendance;
use mod_ortattendance\utils\RoleUtils;
use mod_ortattendance\services\BackupService;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/BaseRecollector.php');
require_once(__DIR__ . '/../utils/StudentAttendance.php');
require_once(__DIR__ . '/../utils/TeacherAttendance.php');
require_once(__DIR__ . '/../utils/RoleUtils.php');
require_once(__DIR__ . '/../services/BackupService.php');

class ZoomRecollectorData extends BaseRecollector {

    private $courseId;
    private $checkCamera;

    public function __construct($courseId, $checkCamera) {
        $this->courseId = $courseId;
        $this->checkCamera = $checkCamera;
    }

    // ==================== ATTENDANCE COLLECTION FUNCTIONALITY ====================

    public function getStudentsByCourseId(): array {
        global $DB;

        // NEW: Get installation config for daily chunking
        $config = $DB->get_record('ortattendance', ['course' => $this->courseId],
            'last_processed_date, start_date, end_date');

        if (!$config) {
            throw new \Exception("Ortattendance configuration not found for course {$this->courseId}");
        }

        // Determine next day to process
        if ($config->last_processed_date === null) {
            $targetDate = $config->start_date; // First run: start from beginning
        } else {
            $targetDate = $config->last_processed_date + 86400; // Next day (86400 = 24 hours)
        }

        // Check if caught up to today
        $today = strtotime('today');
        if ($targetDate >= $today) {
            mtrace("    Already caught up to today!");
            return [
                'students' => [],
                'teachers' => [],
                'caught_up' => true,
                'processed_date' => null
            ];
        }

        // Calculate end of target day
        $targetDateEnd = $targetDate + 86400;
        $formattedDate = date('Y-m-d', $targetDate);

        mtrace("    Processing single day: {$formattedDate}");

        // Process zoom instances for THIS DAY ONLY
        $zoomIds = $this->getAllInstanceByModuleName('zoom', $this->courseId);
        $students = [];
        $teachers = [];

        foreach ($zoomIds as $zoomId) {
            // Get meetings for THIS DAY ONLY
            $sql = "SELECT * FROM {zoom_meeting_details}
                    WHERE zoomid = :zoomid
                    AND start_time >= :target_date
                    AND start_time < :target_date_end
                    AND duration > 1
                    AND participants_count > 1";

            $meetings = $DB->get_records_sql($sql, [
                'zoomid' => $zoomId,
                'target_date' => $targetDate,
                'target_date_end' => $targetDateEnd
            ]);

            if (empty($meetings)) {
                continue;
            }

            mtrace("      Zoom {$zoomId}: " . count($meetings) . " meetings");

            // Process meetings from this day
            $detailsId = array_values(array_map(function($record) { return $record->id; }, $meetings));

            try {
                $participants = $this->getStudentsByMeetingId($detailsId, $this->checkCamera);
                $students = array_merge($students, $participants['students']);
                $teachers = array_merge($teachers, $participants['teachers']);
            } catch (\Exception $e) {
                mtrace("      Error processing zoom {$zoomId}: " . $e->getMessage());
                throw $e;
            }
        }

        mtrace("      Total: " . count($students) . " students, " . count($teachers) . " teachers");

        // Update last_processed_date for this day
        $DB->set_field('ortattendance', 'last_processed_date', $targetDate, ['course' => $this->courseId]);

        return [
            'students' => $students,
            'teachers' => $teachers,
            'caught_up' => false,
            'processed_date' => $formattedDate
        ];
    }
    
    private function getStudentsByMeetingId($meetingIds, bool $checkCamera): array {
        if (empty($meetingIds)) {
            mtrace("  Warning: getStudentsByMeetingId called with empty array");
            return ['students' => [], 'teachers' => []];
        }
        
        mtrace("  getStudentsByMeetingId: Processing " . count($meetingIds) . " meeting detail IDs");
        
        try {
            if (count($meetingIds) > 1) {
                mtrace("  Using getAttendanceDataByMultipleDetails for " . count($meetingIds) . " meetings");
                $attendanceData = $this->getAttendanceDataByMultipleDetails($meetingIds);
            } else {
                mtrace("  Using getAttendanceData for single meeting: " . $meetingIds[0]);
                $attendanceData = $this->getAttendanceData($meetingIds[0]);
            }

            mtrace("  Retrieved " . count($attendanceData) . " participant records from database");

            if ($checkCamera) {
                mtrace("  Checking camera status...");
                $cameraOnUserIds = $this->getUsersWithCameraOn($meetingIds);
                mtrace("  Found " . count($cameraOnUserIds) . " users with camera on");
            } else {
                $cameraOnUserIds = [];
            }

            $students = [];
            $teachers = [];
            
            foreach ($attendanceData as $participant) {
                mtrace("    Processing participant: name='{$participant->name}', email='{$participant->user_email}', userid={$participant->userid}");
                
                // Validate user by name (case-insensitive)
                $userId = $this->validateUserByName($participant->name, $participant->userid);
                
                if (!$userId) {
                    mtrace("      Warning: Could not match user with name '{$participant->name}'");
                    continue;
                }
                
                mtrace("      Matched to Moodle user ID: {$userId}");
                
                $participant->userid = $userId; // Update with validated Moodle user ID
                $hasVideo = !$checkCamera || in_array($userId, $cameraOnUserIds);
                
                if (RoleUtils::isTeacher($userId, $this->courseId)) {
                    mtrace("      User is a teacher");
                    $teachers[] = new TeacherAttendance($participant, $hasVideo);
                } else {
                    mtrace("      User is a student");
                    $students[] = new StudentAttendance($participant, $hasVideo);
                }
            }
            
            mtrace("  Final count: " . count($students) . " students, " . count($teachers) . " teachers");
            
            return ['students' => $students, 'teachers' => $teachers];
        } catch (\Exception $e) {
            mtrace("  ERROR in getStudentsByMeetingId: " . $e->getMessage());
            mtrace("  Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    /**
     * Validate and match Zoom participant to Moodle user by name (case-insensitive)
     */
    private function validateUserByName($zoomName, $fallbackUserId): ?int {
        global $DB;
        
        if (empty($zoomName)) {
            return $fallbackUserId;
        }
        
        // Try exact firstname match
        $user = $DB->get_record('user', ['firstname' => $zoomName, 'deleted' => 0]);
        if ($user) {
            return $user->id;
        }
        
        // Try case-insensitive firstname match
        $sql = "SELECT id FROM {user} 
                WHERE LOWER(firstname) = LOWER(:name) 
                AND deleted = 0 
                LIMIT 1";
        $user = $DB->get_record_sql($sql, ['name' => $zoomName]);
        if ($user) {
            return $user->id;
        }
        
        // Try case-insensitive lastname match
        $sql = "SELECT id FROM {user} 
                WHERE LOWER(lastname) = LOWER(:name) 
                AND deleted = 0 
                LIMIT 1";
        $user = $DB->get_record_sql($sql, ['name' => $zoomName]);
        if ($user) {
            return $user->id;
        }
        
        // Try full name match (firstname lastname)
        if (strpos($zoomName, ' ') !== false) {
            list($firstName, $lastName) = explode(' ', $zoomName, 2);
            $sql = "SELECT id FROM {user} 
                    WHERE LOWER(firstname) = LOWER(:firstname) 
                    AND LOWER(lastname) = LOWER(:lastname) 
                    AND deleted = 0 
                    LIMIT 1";
            $user = $DB->get_record_sql($sql, 
                ['firstname' => $firstName, 'lastname' => $lastName]
            );
            if ($user) {
                return $user->id;
            }
        }
        
        // Fallback to provided userid if no name match
        return $fallbackUserId;
    }

    public function getMeetingsByZoomId($zoomId): array {
        global $DB;
        
        mtrace("    Querying meetings for zoomid: {$zoomId}");
        
        // Get bot configuration for date and time range
        $config = $DB->get_record('ortattendance', ['course' => $this->courseId], 
            'start_date, end_date, start_time, end_time', MUST_EXIST);
        
        $startDate = $config->start_date; // Unix timestamp for start date
        $endDate = $config->end_date;     // Unix timestamp for end date
        $classStartTime = $config->start_time; // seconds since midnight
        $classFinishTime = $config->end_time; // seconds since midnight
        
        mtrace("    Date range: " . date('Y-m-d', $startDate) . " to " . date('Y-m-d', $endDate));
        mtrace("    Time range: " . gmdate('H:i', $classStartTime) . " to " . gmdate('H:i', $classFinishTime));
        
        // Query all meetings within the date range and time window
        // Match meetings that fall within the daily class time window
        $sql = "SELECT * FROM {zoom_meeting_details} 
                WHERE zoomid = :zoomid 
                AND start_time >= :start_date
                AND start_time <= :end_date
                AND duration > 1 
                AND participants_count > 1";
        
        $results = $DB->get_records_sql($sql, [
            'zoomid' => $zoomId, 
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);
        
        $results = array_values($results); // Re-index array
        
        mtrace("    Query returned " . count($results) . " meetings");
        
        if (!empty($results)) {
            foreach ($results as $meeting) {
                mtrace("      - meeting_id: '{$meeting->meeting_id}' (stored as integer)");
                mtrace("        topic: '{$meeting->topic}'");
                mtrace("        details_id: {$meeting->id}");
                mtrace("        start: " . date('Y-m-d H:i:s', $meeting->start_time));
            }
        }
        
        return $results;
    }
    
    /**
     * Helper function to get timestamp for a specific time of day
     * From attendancebot utilities.php
     */
    private function getTime($baseTimestamp, $secondsFromMidnight): int {
        $date = new \DateTime();  // Use global namespace
        $date->setTimestamp($baseTimestamp);
        $date->setTime(0, 0, 0); // Set to midnight
        $date->modify("+{$secondsFromMidnight} seconds");
        return $date->getTimestamp();
    }

    private function getAttendanceData($detailId): array {
    global $DB;
    
    mtrace("  Debug: Getting attendance data for detailId: $detailId");
    
    try {
        // Get tolerance from config
        $config = $DB->get_record('ortattendance', ['course' => $this->courseId], 'late_tolerance');

        if (!$config) {
            mtrace("  WARNING: No ortattendance config found for course {$this->courseId}, using default tolerance");
            $lateTolerance = 10;
        } else {
            $lateTolerance = $config->late_tolerance ?? 10;
        }

        if ($lateTolerance == 0) {
            $lateTolerance = 1000000000; // No tolerance means accept any join time
        }
        
        // Query with INNER JOIN for groups (excludes non-grouped users)
        $sql = "SELECT
                    zmp.userid,
                    zmp.name,
                    zmp.user_email,
                    MIN(zmp.join_time) AS join_time,
                    MAX(zmp.leave_time) AS leave_time,
                    gm.groupid,
                    zmd.meeting_id,
                    zmd.start_time,
                    zmd.end_time,
                    SUM(zmp.duration) AS duration,
                    (SUM(zmp.duration) * 100.0 / NULLIF(zmd.end_time - zmd.start_time, 0)) AS attendance_percentage,
                    CASE
                        WHEN MIN(zmp.join_time) > zmd.start_time + (:minutes_of_tolerance * 60)
                        THEN 1
                        ELSE 0
                    END AS is_late
                FROM {zoom_meeting_participants} zmp
                JOIN {zoom_meeting_details} zmd ON zmp.detailsid = zmd.id
                JOIN {zoom} z ON zmd.zoomid = z.id
                JOIN {groups_members} gm ON zmp.userid = gm.userid
                JOIN {groups} g ON gm.groupid = g.id AND z.course = g.courseid
                WHERE zmp.detailsid = :details_id
                GROUP BY zmp.userid, zmp.name, zmp.user_email,
                         zmd.meeting_id, zmd.start_time, zmd.end_time, gm.groupid";
        
        $results = $DB->get_records_sql($sql, [
            'details_id' => $detailId,
            'minutes_of_tolerance' => $lateTolerance
        ]);
        
        mtrace("  Debug: Found " . count($results) . " participants");
        
        return $results;
    } catch (\Exception $e) {
        mtrace("  Error in getAttendanceData for detailId $detailId: " . $e->getMessage());
        throw $e;
    }
}

    private function getAttendanceDataByMultipleDetails($detailsIds): array {
    global $DB;
    
    mtrace("  Debug: Getting attendance data for multiple details: " . count($detailsIds));
    
    // Get tolerance from config
    $config = $DB->get_record('ortattendance', ['course' => $this->courseId], 'late_tolerance');

    if (!$config) {
        mtrace("  WARNING: No ortattendance config found for course {$this->courseId}, using default tolerance");
        $lateTolerance = 10;
    } else {
        $lateTolerance = $config->late_tolerance ?? 10;
    }

    if ($lateTolerance == 0) {
        $lateTolerance = 1000000000;
    }
    
    // Use get_in_or_equal for safe IN clause parameter binding
    list($inSql, $inParams) = $DB->get_in_or_equal($detailsIds, SQL_PARAMS_NAMED, 'detailsid');

    // Query with optional group membership (INNER JOIN to exclude non-grouped users)
    $sql = "SELECT
                zmp.userid,
                zmp.name,
                zmp.user_email,
                gm.groupid,
                zmd.meeting_id,
                zmd.start_time,
                zmd.end_time,
                MIN(zmp.join_time) as join_time,
                MAX(zmp.leave_time) as leave_time,
                SUM(zmp.duration) as duration,
                (SUM(zmp.duration) * 100.0 / NULLIF(zmd.end_time - zmd.start_time, 0)) AS attendance_percentage,
                CASE
                    WHEN MIN(zmp.join_time) > zmd.start_time + (:minutes_of_tolerance * 60)
                    THEN 1
                    ELSE 0
                END AS is_late
            FROM {zoom_meeting_participants} zmp
            JOIN {zoom_meeting_details} zmd ON zmp.detailsid = zmd.id
            JOIN {zoom} z ON zmd.zoomid = z.id
            JOIN {groups_members} gm ON zmp.userid = gm.userid
            JOIN {groups} g ON gm.groupid = g.id AND z.course = g.courseid
            WHERE zmp.detailsid $inSql
            GROUP BY zmp.userid, zmp.name, zmp.user_email, zmd.meeting_id,
                     zmd.start_time, zmd.end_time, gm.groupid";

    // Merge parameters
    $params = array_merge(['minutes_of_tolerance' => $lateTolerance], $inParams);
    $results = $DB->get_records_sql($sql, $params);
    
    mtrace("  Debug: Found " . count($results) . " participant records across meetings");
    
    return $results;
}

    private function getUsersWithCameraOn($meetingIds): array {
        global $DB;
        
        list($inSql, $params) = $DB->get_in_or_equal($meetingIds, SQL_PARAMS_NAMED);
        
        $sql = "SELECT DISTINCT zmp.userid FROM {zoom_meeting_participants} zmp
                WHERE zmp.detailsid $inSql AND zmp.has_video = 1";
                
        $records = $DB->get_records_sql($sql, $params);
        return array_keys($records);
    }
    
    private function getAllInstanceByModuleName($moduleName, $courseId): array {
        global $DB;
        $moduleId = $this->getModuleId($moduleName);
        $sql = "SELECT * FROM {course_modules} WHERE course = :course AND module = :moduleid AND deletioninprogress = 0";
        $pluginModules = $DB->get_records_sql($sql, array('course' => $courseId, 'moduleid' => $moduleId));
        $instancesId = [];
        foreach ($pluginModules as $module) {
            $instancesId[] = $module->instance;
        }
        return $instancesId;
    }
    
    private function getModuleId($moduleName): ?int {
        global $DB;
        $sql = "SELECT id FROM {modules} WHERE name = :module_name";
        $module = $DB->get_record_sql($sql, ['module_name' => $moduleName]);
        return $module ? $module->id : null;
    }
    
    /**
     * Get the name of this recollector
     * 
     * @return string Recollector name
     */
    public static function getName() {
        return 'Zoom Recollector Data';
    }
    
    /**
     * Get the type identifier of this recollector
     * 
     * @return string Recollector type
     */
    public static function getType() {
        return 'zoom_data';
    }
}