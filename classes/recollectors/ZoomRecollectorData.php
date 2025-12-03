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
use mod_ortattendance\utils\LogLevel;
use mod_ortattendance\services\BackupService;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/BaseRecollector.php');
require_once(__DIR__ . '/../utils/StudentAttendance.php');
require_once(__DIR__ . '/../utils/TeacherAttendance.php');
require_once(__DIR__ . '/../utils/RoleUtils.php');
require_once(__DIR__ . '/../utils/LogLevel.php');
require_once(__DIR__ . '/../services/BackupService.php');

class ZoomRecollectorData extends BaseRecollector {

    private $courseId;
    private $checkCamera;
    private $courseShortname;

    public function __construct($courseId, $checkCamera) {
        global $DB;

        $this->courseId = $courseId;
        $this->checkCamera = $checkCamera;

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

    // ==================== ATTENDANCE COLLECTION FUNCTIONALITY ====================

    public function getStudentsByCourseId(): array {
        global $DB;

        $logPrefix = $this->getCourseLogPrefix();

        try {
            // NEW: Get installation config for daily chunking
            $config = $DB->get_record('ortattendance', ['course' => $this->courseId],
                'last_processed_date, start_date, end_date');

            if (!$config) {
                throw new \Exception("Ortattendance configuration not found for course {$this->courseId}");
            }
        } catch (\Exception $e) {
            mtrace("  {$logPrefix} Error fetching ortattendance config: " . $e->getMessage());
            throw $e;
        }

        // Determine next day to process
        if ($config->last_processed_date === null) {
            // First run: start from beginning
            if (empty($config->start_date)) {
                throw new \Exception("Configuration start_date is not set for course {$this->courseId}");
            }
            $targetDate = $config->start_date;
        } else {
            $targetDate = $config->last_processed_date + 86400; // Next day (86400 = 24 hours)
        }

        // Check if caught up to today
        $today = strtotime('today');
        if ($targetDate >= $today) {
            mtrace("    {$logPrefix} Already caught up to today!");
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

        mtrace("    {$logPrefix} Processing single day: {$formattedDate}");

        // Process zoom instances for THIS DAY ONLY
        $zoomIds = $this->getAllInstanceByModuleName('zoom', $this->courseId);
        $students = [];
        $teachers = [];

        foreach ($zoomIds as $zoomId) {
            try {
                // Get Zoom activity name for better logging
                $zoom = $DB->get_record('zoom', ['id' => $zoomId], 'name', IGNORE_MISSING);
                $zoomName = $zoom ? $zoom->name : "Zoom-{$zoomId}";

                // Get meetings for THIS DAY ONLY
                // Handle both INT (Unix timestamp) and TEXT (DATETIME) formats
                $targetDateStr = date('Y-m-d', $targetDate);
                $targetDateEndStr = date('Y-m-d', $targetDateEnd);

                $sql = "SELECT * FROM {zoom_meeting_details}
                        WHERE zoomid = :zoomid
                        AND (
                            (start_time >= :target_date AND start_time < :target_date_end)
                            OR (start_time >= :target_date_str AND start_time < :target_date_end_str)
                        )
                        AND duration > 1
                        AND participants_count > 1";

                $meetings = $DB->get_records_sql($sql, [
                    'zoomid' => $zoomId,
                    'target_date' => $targetDate,
                    'target_date_end' => $targetDateEnd,
                    'target_date_str' => $targetDateStr,
                    'target_date_end_str' => $targetDateEndStr
                ]);

                // Ensure meetings is always an array
                if (!is_array($meetings)) {
                    mtrace("      {$logPrefix} Warning: Query returned non-array for zoom {$zoomId} ({$zoomName})");
                    continue;
                }

                if (empty($meetings)) {
                    continue;
                }

                mtrace("      {$logPrefix} Zoom activity '{$zoomName}' (ID:{$zoomId}): " . count($meetings) . " meetings");

                // Process meetings from this day
                $detailsId = array_values(array_map(function($record) { return $record->id; }, $meetings));

                try {
                    $participants = $this->getStudentsByMeetingId($detailsId, $this->checkCamera);

                    // Validate participants structure
                    if (!is_array($participants) || !isset($participants['students']) || !isset($participants['teachers'])) {
                        mtrace("      {$logPrefix} WARNING: getStudentsByMeetingId returned invalid data. Skipping.");
                        continue;
                    }

                    $students = array_merge($students, $participants['students']);
                    $teachers = array_merge($teachers, $participants['teachers']);
                } catch (\Exception $e) {
                    mtrace("      {$logPrefix} Error processing zoom {$zoomId} ({$zoomName}): " . $e->getMessage());
                    throw $e;
                }
            } catch (\Exception $e) {
                mtrace("      {$logPrefix} Error querying meetings for zoom {$zoomId}: " . $e->getMessage());
                // Continue to next zoom instance
                continue;
            }
        }

        mtrace("      {$logPrefix} Total: " . count($students) . " students, " . count($teachers) . " teachers");

        // Update last_processed_date for this day
        try {
            $DB->set_field('ortattendance', 'last_processed_date', $targetDate, ['course' => $this->courseId]);
        } catch (\Exception $e) {
            mtrace("      {$logPrefix} Warning: Failed to update last_processed_date: " . $e->getMessage());
            // Non-fatal, continue processing
        }

        return [
            'students' => $students,
            'teachers' => $teachers,
            'caught_up' => false,
            'processed_date' => $formattedDate
        ];
    }
    
    private function getStudentsByMeetingId($meetingIds, bool $checkCamera): array {
        $logPrefix = $this->getCourseLogPrefix();

        if (empty($meetingIds)) {
            mtrace("  {$logPrefix} Warning: getStudentsByMeetingId called with empty array");
            return ['students' => [], 'teachers' => []];
        }

        mtrace("  {$logPrefix} getStudentsByMeetingId: Processing " . count($meetingIds) . " meeting detail IDs");

        try {
            if (count($meetingIds) > 1) {
                mtrace("  {$logPrefix} Using getAttendanceDataByMultipleDetails for " . count($meetingIds) . " meetings");
                $attendanceData = $this->getAttendanceDataByMultipleDetails($meetingIds);
            } else {
                mtrace("  {$logPrefix} Using getAttendanceData for single meeting: " . $meetingIds[0]);
                $attendanceData = $this->getAttendanceData($meetingIds[0]);
            }

            mtrace("  {$logPrefix} Retrieved " . count($attendanceData) . " participant records from database");

            // Validate attendanceData is a proper array
            if (!is_array($attendanceData)) {
                mtrace("  {$logPrefix} Warning: attendanceData is not an array, converting to empty array");
                $attendanceData = [];
            }

            if ($checkCamera) {
                mtrace("  {$logPrefix} Checking camera status...");
                $cameraOnUserIds = $this->getUsersWithCameraOn($meetingIds);
                mtrace("  {$logPrefix} Found " . count($cameraOnUserIds) . " users with camera on");
            } else {
                $cameraOnUserIds = [];
            }

            $students = [];
            $teachers = [];

            foreach ($attendanceData as $participant) {
                // Defensive checks for participant object properties
                $name = $participant->name ?? 'Unknown';
                $email = $participant->user_email ?? 'no-email';
                $zoomUserId = $participant->zoom_userid ?? null;

                mtrace("    {$logPrefix} Processing participant: name='{$name}', email='{$email}', zoom_userid={$zoomUserId}");

                // Validate user by name and email (prioritize name-based matching)
                // Pass email as optional filter for disambiguation
                $userId = $this->validateUserByName($name, $email);

                if (!$userId) {
                    mtrace("      {$logPrefix} Warning: Could not match user with name '{$name}' and email '{$email}'");
                    continue;
                }

                mtrace("      {$logPrefix} Matched to Moodle user ID: {$userId}");

                $participant->userid = $userId; // Update with validated Moodle user ID
                $hasVideo = !$checkCamera || in_array($userId, $cameraOnUserIds);

                if (RoleUtils::isTeacher($userId, $this->courseId)) {
                    mtrace("      {$logPrefix} User is a teacher");
                    $teachers[] = new TeacherAttendance($participant, $hasVideo);
                } else {
                    mtrace("      {$logPrefix} User is a student");
                    $students[] = new StudentAttendance($participant, $hasVideo);
                }
            }

            mtrace("  {$logPrefix} Final count: " . count($students) . " students, " . count($teachers) . " teachers");

            return ['students' => $students, 'teachers' => $teachers];
        } catch (\Exception $e) {
            mtrace("  {$logPrefix} ERROR in getStudentsByMeetingId: " . $e->getMessage());
            mtrace("  {$logPrefix} Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    /**
     * Validate and match Zoom participant to Moodle user by name and optionally by email
     * Priority: name-based matching first, then email as optional filter
     *
     * @param string $zoomName The participant's name from Zoom
     * @param string|null $zoomEmail The participant's email from Zoom (optional)
     * @return int|null Moodle user ID if found, null otherwise
     */
    private function validateUserByName($zoomName, $zoomEmail = null): ?int {
        global $DB;

        $logPrefix = $this->getCourseLogPrefix();

        if (empty($zoomName)) {
            mtrace("      {$logPrefix} Warning: Empty zoom name, cannot validate user");
            return null;
        }

        // Clean email (handle 'no-email' placeholder)
        $hasValidEmail = !empty($zoomEmail) && $zoomEmail !== 'no-email';

        mtrace("      {$logPrefix} Searching for user: name='{$zoomName}', email='" . ($hasValidEmail ? $zoomEmail : 'none') . "'");

        // Strategy 1: Try full name match (lastname firstname or firstname lastname)
        if (strpos($zoomName, ' ') !== false) {
            $nameParts = explode(' ', $zoomName);

            // Try: LASTNAME FIRSTNAME (common in Zoom data: "ROSENFELD DAMIAN")
            if (count($nameParts) >= 2) {
                $possibleLastName = $nameParts[0];
                $possibleFirstName = implode(' ', array_slice($nameParts, 1));

                $sql = "SELECT id, email FROM {user}
                        WHERE LOWER(lastname) = LOWER(:lastname)
                        AND LOWER(firstname) = LOWER(:firstname)
                        AND deleted = 0";

                $users = $DB->get_records_sql($sql, [
                    'lastname' => $possibleLastName,
                    'firstname' => $possibleFirstName
                ]);

                if (!empty($users)) {
                    // If email is provided, filter by email
                    if ($hasValidEmail) {
                        foreach ($users as $user) {
                            if (strtolower($user->email) === strtolower($zoomEmail)) {
                                mtrace("      {$logPrefix} ✓ Matched by lastname+firstname+email: user ID {$user->id}");
                                return $user->id;
                            }
                        }
                    } else {
                        // No email filter, return first match
                        $user = reset($users);
                        mtrace("      {$logPrefix} ✓ Matched by lastname+firstname: user ID {$user->id}");
                        return $user->id;
                    }
                }
            }

            // Try: FIRSTNAME LASTNAME
            if (count($nameParts) >= 2) {
                $possibleFirstName = $nameParts[0];
                $possibleLastName = implode(' ', array_slice($nameParts, 1));

                $sql = "SELECT id, email FROM {user}
                        WHERE LOWER(firstname) = LOWER(:firstname)
                        AND LOWER(lastname) = LOWER(:lastname)
                        AND deleted = 0";

                $users = $DB->get_records_sql($sql, [
                    'firstname' => $possibleFirstName,
                    'lastname' => $possibleLastName
                ]);

                if (!empty($users)) {
                    if ($hasValidEmail) {
                        foreach ($users as $user) {
                            if (strtolower($user->email) === strtolower($zoomEmail)) {
                                mtrace("      {$logPrefix} ✓ Matched by firstname+lastname+email: user ID {$user->id}");
                                return $user->id;
                            }
                        }
                    } else {
                        $user = reset($users);
                        mtrace("      {$logPrefix} ✓ Matched by firstname+lastname: user ID {$user->id}");
                        return $user->id;
                    }
                }
            }
        }

        // Strategy 2: Try lastname-only match
        $sql = "SELECT id, email FROM {user}
                WHERE LOWER(lastname) = LOWER(:name)
                AND deleted = 0";
        $users = $DB->get_records_sql($sql, ['name' => $zoomName]);

        if (!empty($users)) {
            if ($hasValidEmail) {
                foreach ($users as $user) {
                    if (strtolower($user->email) === strtolower($zoomEmail)) {
                        mtrace("      {$logPrefix} ✓ Matched by lastname+email: user ID {$user->id}");
                        return $user->id;
                    }
                }
            } else {
                $user = reset($users);
                mtrace("      {$logPrefix} ✓ Matched by lastname: user ID {$user->id}");
                return $user->id;
            }
        }

        // Strategy 3: Try firstname-only match
        $sql = "SELECT id, email FROM {user}
                WHERE LOWER(firstname) = LOWER(:name)
                AND deleted = 0";
        $users = $DB->get_records_sql($sql, ['name' => $zoomName]);

        if (!empty($users)) {
            if ($hasValidEmail) {
                foreach ($users as $user) {
                    if (strtolower($user->email) === strtolower($zoomEmail)) {
                        mtrace("      {$logPrefix} ✓ Matched by firstname+email: user ID {$user->id}");
                        return $user->id;
                    }
                }
            } else {
                $user = reset($users);
                mtrace("      {$logPrefix} ✓ Matched by firstname: user ID {$user->id}");
                return $user->id;
            }
        }

        // Strategy 4: If email is provided, try email-only match
        if ($hasValidEmail) {
            $user = $DB->get_record('user', ['email' => $zoomEmail, 'deleted' => 0]);
            if ($user && !empty($user->id)) {
                mtrace("      {$logPrefix} ✓ Matched by email only: user ID {$user->id}");
                return $user->id;
            }
        }

        // No match found
        mtrace("      {$logPrefix} ✗ No match found for '{$zoomName}'");
        return null;
    }

    public function getMeetingsByRecollectorId($recollectorId): array {
        global $DB;

        $logPrefix = $this->getCourseLogPrefix();

        // Get Zoom activity name for better logging
        $zoom = $DB->get_record('zoom', ['id' => $recollectorId], 'name', IGNORE_MISSING);
        $zoomName = $zoom ? $zoom->name : "Zoom-{$recollectorId}";

        mtrace("    {$logPrefix} Querying meetings for Zoom activity '{$zoomName}' (ID:{$recollectorId})");

        try {
            // Get bot configuration for date and time range
            $config = $DB->get_record('ortattendance', ['course' => $this->courseId],
                'start_date, end_date, start_time, end_time', MUST_EXIST);

            if (!$config) {
                throw new \Exception("Configuration not found for course {$this->courseId}");
            }

            $startDate = $config->start_date; // Unix timestamp for start date
            $endDate = $config->end_date;     // Unix timestamp for end date
            $classStartTime = $config->start_time; // seconds since midnight
            $classFinishTime = $config->end_time; // seconds since midnight

            mtrace("    {$logPrefix} Date range: " . date('Y-m-d', $startDate) . " to " . date('Y-m-d', $endDate));
            mtrace("    {$logPrefix} Time range: " . gmdate('H:i', $classStartTime) . " to " . gmdate('H:i', $classFinishTime));

            // Query all meetings within the date range and time window
            // Match meetings that fall within the daily class time window
            $sql = "SELECT * FROM {zoom_meeting_details}
                    WHERE zoomid = :zoomid
                    AND start_time >= :start_date
                    AND start_time <= :end_date
                    AND duration > 1
                    AND participants_count > 1";

            $results = $DB->get_records_sql($sql, [
                'zoomid' => $recollectorId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            // Ensure we always return an array (DB can return null/false on error)
            if (!is_array($results)) {
                mtrace("    {$logPrefix} Warning: Query returned non-array result for zoomid {$recollectorId}");
                return [];
            }

            $results = array_values($results); // Re-index array

            mtrace("    {$logPrefix} Query returned " . count($results) . " meetings");

            if (!empty($results)) {
                foreach ($results as $meeting) {
                    mtrace("      {$logPrefix} - meeting_id: '{$meeting->meeting_id}' (stored as integer)");
                    mtrace("        {$logPrefix}   topic: '{$meeting->topic}'");
                    mtrace("        {$logPrefix}   details_id: {$meeting->id}");
                    mtrace("        {$logPrefix}   start: " . date('Y-m-d H:i:s', $meeting->start_time));
                }
            }

            return $results;
        } catch (\Exception $e) {
            mtrace("    {$logPrefix} Error querying meetings: " . $e->getMessage());
            return [];
        }
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

    $logPrefix = $this->getCourseLogPrefix();
    LogLevel::debug("Getting attendance data for detailId: $detailId", $logPrefix);

    try {
        // Get tolerance from config
        $config = $DB->get_record('ortattendance', ['course' => $this->courseId], 'late_tolerance');

        if (!$config) {
            mtrace("  {$logPrefix} WARNING: No ortattendance config found for course {$this->courseId}, using default tolerance");
            $lateTolerance = 10;
        } else {
            $lateTolerance = $config->late_tolerance ?? 10;
        }

        if ($lateTolerance == 0) {
            $lateTolerance = 1000000000; // No tolerance means accept any join time
        }

        // Query with LEFT JOIN for groups (includes non-grouped users)
        // This allows us to capture all participants regardless of group membership
        $sql = "SELECT
                    zmp.userid as zoom_userid,
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
                LEFT JOIN {groups_members} gm ON zmp.userid = gm.userid
                LEFT JOIN {groups} g ON gm.groupid = g.id AND z.course = g.courseid
                WHERE zmp.detailsid = :details_id
                GROUP BY zmp.userid, zmp.name, zmp.user_email,
                         zmd.meeting_id, zmd.start_time, zmd.end_time, gm.groupid";

        $results = $DB->get_records_sql($sql, [
            'details_id' => $detailId,
            'minutes_of_tolerance' => $lateTolerance
        ]);

        // Ensure we always return an array (DB can return null/false on error)
        if (!is_array($results)) {
            mtrace("  {$logPrefix} Warning: Query returned non-array result for detailId $detailId");
            $results = [];
        }

        LogLevel::debug("Found " . count($results) . " participants", $logPrefix);

        return $results;
    } catch (\Exception $e) {
        mtrace("  {$logPrefix} Error in getAttendanceData for detailId $detailId: " . $e->getMessage());
        throw $e;
    }
}

    private function getAttendanceDataByMultipleDetails($detailsIds): array {
    global $DB;

    $logPrefix = $this->getCourseLogPrefix();
    LogLevel::debug("Getting attendance data for multiple details: " . count($detailsIds), $logPrefix);

    // Get tolerance from config
    $config = $DB->get_record('ortattendance', ['course' => $this->courseId], 'late_tolerance');

    if (!$config) {
        mtrace("  {$logPrefix} WARNING: No ortattendance config found for course {$this->courseId}, using default tolerance");
        $lateTolerance = 10;
    } else {
        $lateTolerance = $config->late_tolerance ?? 10;
    }

    if ($lateTolerance == 0) {
        $lateTolerance = 1000000000;
    }

    // Use get_in_or_equal for safe IN clause parameter binding
    list($inSql, $inParams) = $DB->get_in_or_equal($detailsIds, SQL_PARAMS_NAMED, 'detailsid');

    // Query with LEFT JOIN for groups (includes non-grouped users)
    // This allows us to capture all participants regardless of group membership
    $sql = "SELECT
                zmp.userid as zoom_userid,
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
            LEFT JOIN {groups_members} gm ON zmp.userid = gm.userid
            LEFT JOIN {groups} g ON gm.groupid = g.id AND z.course = g.courseid
            WHERE zmp.detailsid $inSql
            GROUP BY zmp.userid, zmp.name, zmp.user_email, zmd.meeting_id,
                     zmd.start_time, zmd.end_time, gm.groupid";

    // Merge parameters
    $params = array_merge(['minutes_of_tolerance' => $lateTolerance], $inParams);
    $results = $DB->get_records_sql($sql, $params);

    // Ensure we always return an array (DB can return null/false on error)
    if (!is_array($results)) {
        mtrace("  {$logPrefix} Warning: Query returned non-array result for multiple details");
        $results = [];
    }

    LogLevel::debug("Found " . count($results) . " participant records across meetings", $logPrefix);

    return $results;
}

    private function getUsersWithCameraOn($meetingIds): array {
        global $DB;

        $logPrefix = $this->getCourseLogPrefix();

        // NOTE: Camera checking is currently disabled because the zoom_meeting_participants
        // table does not have a 'has_video' field in the current Zoom module version.
        // This functionality was removed from the Zoom module.
        //
        // WORKAROUND: Return ALL participant user IDs to treat everyone as having camera on.
        // This prevents camera requirement from blocking attendance processing.
        //
        // If Zoom module adds this field back in the future, uncomment the code below:
        //
        // list($inSql, $params) = $DB->get_in_or_equal($meetingIds, SQL_PARAMS_NAMED);
        // $sql = "SELECT DISTINCT zmp.userid FROM {zoom_meeting_participants} zmp
        //         WHERE zmp.detailsid $inSql AND zmp.has_video = 1";
        // $records = $DB->get_records_sql($sql, $params);
        // return array_keys($records);

        mtrace("  {$logPrefix} WARNING: Camera checking is disabled (zoom_meeting_participants.has_video field does not exist)");
        mtrace("  {$logPrefix} WORKAROUND: Treating all participants as having camera ON");

        // Get all user IDs for participants in these meetings
        list($inSql, $params) = $DB->get_in_or_equal($meetingIds, SQL_PARAMS_NAMED);
        $sql = "SELECT DISTINCT zmp.userid
                FROM {zoom_meeting_participants} zmp
                WHERE zmp.detailsid $inSql
                AND zmp.userid IS NOT NULL";

        $records = $DB->get_records_sql($sql, $params);

        // Ensure records is always an array
        if (!is_array($records)) {
            mtrace("  {$logPrefix} Warning: Camera check query returned non-array");
            return [];
        }

        return array_keys($records);
    }
    
    private function getAllInstanceByModuleName($moduleName, $courseId): array {
        global $DB;

        $logPrefix = $this->getCourseLogPrefix();
        $moduleId = $this->getModuleId($moduleName);

        if (!$moduleId) {
            mtrace("  {$logPrefix} Warning: Module '{$moduleName}' not found");
            return [];
        }

        $sql = "SELECT * FROM {course_modules} WHERE course = :course AND module = :moduleid AND deletioninprogress = 0";
        $pluginModules = $DB->get_records_sql($sql, array('course' => $courseId, 'moduleid' => $moduleId));

        // Ensure pluginModules is always an array
        if (!is_array($pluginModules)) {
            mtrace("  {$logPrefix} Warning: Query for module instances returned non-array");
            return [];
        }

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