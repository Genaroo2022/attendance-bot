<?php
namespace mod_ortattendance\persistence;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/BasePersistence.php');

class AttendancePersistence extends BasePersistence {
    
    private $courseId;
    
    public function __construct($courseId) {
        $this->courseId = $courseId;
    }
    
    public function persistStudents($students, $teachers) {
        global $DB;
        
        mtrace("Persisting " . count($students) . " students and " . count($teachers) . " teachers");
        
        // Get attendance instance for this course
        $attendance = $DB->get_record_sql(
            "SELECT a.* FROM {attendance} a
             JOIN {ortattendance} oa ON oa.course = a.course
             WHERE oa.course = :courseid
             LIMIT 1",
            ['courseid' => $this->courseId]
        );
        
        if (!$attendance) {
            mtrace("  ERROR: No attendance activity found for course {$this->courseId}");
            return [];
        }
        
        mtrace("  Using attendance activity: {$attendance->name} (ID: {$attendance->id})");
        
        // Group students by meeting/session
        $sessionGroups = $this->groupBySession($students, $teachers);

        foreach ($sessionGroups as $key => $participants) {
            $meetingId = $participants['meetingid'];
            mtrace("  Processing session for meeting: {$meetingId}");

            // Pass groupid to session matching
            $groupId = $participants['groupid'] ?? null;
            $sessionId = $this->getOrCreateSession($attendance->id, $meetingId, $participants, $groupId);

            if (!$sessionId) {
                mtrace("    ERROR: Could not create session");
                continue;
            }

            $this->recordAttendance($sessionId, $participants['students'], $participants['teachers']);
        }
    }
    
    /**
     * Group students and teachers by meeting ID AND group ID
     * This ensures separate sessions for each group in multi-group meetings
     */
    private function groupBySession($students, $teachers) {
        $groups = [];

        foreach ($students as $student) {
            $meetingId = $student->getMeetingId();
            $groupId = $student->getGroupId() ?? 0;  // Use 0 for no group

            // Key by both meeting and group to separate multi-group meetings
            // Use sprintf to prevent collisions (e.g., "123_4" vs "12_34")
            $key = sprintf('m%s_g%d', $meetingId, $groupId);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'students' => [],
                    'teachers' => [],
                    'date' => $student->getJoinTime(),
                    'groupid' => $groupId,
                    'meetingid' => $meetingId  // Store meeting ID separately
                ];
            }
            $groups[$key]['students'][] = $student;
        }

        foreach ($teachers as $teacher) {
            $meetingId = $teacher->getMeetingId();
            $groupId = $teacher->getGroupId() ?? 0;  // Use 0 for no group

            // Key by both meeting and group
            // Use sprintf to prevent collisions (e.g., "123_4" vs "12_34")
            $key = sprintf('m%s_g%d', $meetingId, $groupId);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'students' => [],
                    'teachers' => [],
                    'date' => $teacher->getJoinTime(),
                    'groupid' => $groupId,
                    'meetingid' => $meetingId  // Store meeting ID separately
                ];
            }
            $groups[$key]['teachers'][] = $teacher;
        }

        return $groups;
    }
    
    /**
     * Find existing teacher-created sessions by date and group, or create new one
     * Search by: groupid + date within ±1 day
     * @param int $attendanceId The attendance activity ID
     * @param string $meetingId The Zoom meeting ID
     * @param array $participants Array with 'date', 'groupid', 'meetingid' keys
     * @param int|null $groupId The group ID (0 for no group, null to auto-detect)
     * @return int|null Session ID if found/created, null on failure
     */
    private function getOrCreateSession($attendanceId, $meetingId, $participants, $groupId = null): ?int {
        global $DB;

        $sessionDate = $participants['date'];
        $dateStart = $sessionDate - 86400;  // 1 day before
        $dateEnd = $sessionDate + 86400;    // 1 day after

        mtrace("    Searching for existing session:");
        mtrace("      Group ID: " . ($groupId ?? 'NULL'));
        mtrace("      Date range: " . date('Y-m-d H:i', $dateStart) . " to " . date('Y-m-d H:i', $dateEnd));

        // Strategy 1: Exact match by groupid + date
        if ($groupId !== null && $groupId !== 0) {
            $sql = "SELECT * FROM {attendance_sessions}
                    WHERE attendanceid = :attendanceid
                    AND groupid = :groupid
                    AND sessdate >= :date_start AND sessdate <= :date_end
                    ORDER BY ABS(sessdate - :target_date) ASC
                    LIMIT 1";

            $existing = $DB->get_record_sql($sql, [
                'attendanceid' => $attendanceId,
                'groupid' => $groupId,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'target_date' => $sessionDate
            ]);

            if ($existing) {
                mtrace("    ✓ Found existing session by group + date: ID={$existing->id}");
                mtrace("      Description: '{$existing->description}'");
                mtrace("      Session date: " . date('Y-m-d H:i', $existing->sessdate));

                // Update description to mark bot processing (with group)
                $this->updateSessionDescription($existing->id, $existing->description, true);

                return $existing->id;
            }
        }

        // Strategy 2: Match by date only (for sessions without group or groupid=0)
        // Find ANY session on the meeting date (closest match)
        $sql = "SELECT * FROM {attendance_sessions}
                WHERE attendanceid = :attendanceid
                AND sessdate >= :date_start AND sessdate <= :date_end
                AND (groupid = 0 OR groupid IS NULL)
                ORDER BY ABS(sessdate - :target_date) ASC
                LIMIT 1";

        $existing = $DB->get_record_sql($sql, [
            'attendanceid' => $attendanceId,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'target_date' => $sessionDate
        ]);

        if ($existing) {
            mtrace("    ⚠️  Found session by date only (no group match): ID={$existing->id}");
            mtrace("      Description: '{$existing->description}'");
            mtrace("      Session date: " . date('Y-m-d H:i', $existing->sessdate));

            // Update description to mark bot processing (without group)
            $this->updateSessionDescription($existing->id, $existing->description, false);

            return $existing->id;
        }

        // NOT FOUND: Create new session automatically
        mtrace("    ❌ NO existing session found");
        mtrace("        Expected group ID: " . ($groupId ?? 'NULL'));
        mtrace("        Search range: " . date('Y-m-d', $dateStart) . " to " . date('Y-m-d', $dateEnd));
        mtrace("        → Creating new session automatically");

        // Create the session
        $newSessionId = $this->createSession($attendanceId, $meetingId, $groupId ?? 0, $sessionDate);

        return $newSessionId;  // Return new session ID or null on failure
    }

    /**
     * Create a new attendance session for the given meeting and group
     * @param int $attendanceId The attendance activity ID
     * @param string $meetingId The Zoom meeting ID
     * @param int $groupId The group ID (0 for no group)
     * @param int $sessionDate Unix timestamp of session start
     * @return int|null The new session ID, or null on failure
     */
    private function createSession($attendanceId, $meetingId, $groupId, $sessionDate): ?int {
        global $DB;

        mtrace("    Creating new session automatically...");

        // Get meeting details from zoom_meeting_details
        $meeting = $DB->get_record('zoom_meeting_details', ['meeting_id' => $meetingId]);

        if (!$meeting) {
            mtrace("      ERROR: Could not find meeting details for meeting ID: {$meetingId}");
            return null;
        }

        // Validate meeting details
        if (empty($meeting->start_time) || empty($meeting->end_time)) {
            mtrace("      ERROR: Meeting has invalid timestamps");
            return null;
        }

        if ($meeting->end_time <= $meeting->start_time) {
            mtrace("      ERROR: Meeting end time is before or equal to start time");
            return null;
        }

        // Calculate actual duration in minutes (Moodle attendance expects minutes, not seconds)
        $actualDurationSeconds = $meeting->end_time - $meeting->start_time;
        $actualDuration = intval($actualDurationSeconds / 60);  // Convert to minutes

        if ($actualDuration <= 0) {
            mtrace("      ERROR: Meeting duration is zero or negative");
            return null;
        }

        // Build description
        $description = "sesion automatica";
        if (!empty($meeting->topic)) {
            $description = $meeting->topic . " - " . $description;
        }

        // Create session record
        $session = new \stdClass();
        $session->attendanceid = $attendanceId;
        $session->groupid = $groupId;
        $session->sessdate = $meeting->start_time;
        $session->duration = $actualDuration;
        $session->description = $description;
        $session->descriptionformat = 0;  // Plain text
        $session->statusset = 0;  // Default status set
        $session->lasttaken = null;
        $session->lasttakenby = 0;
        $session->timemodified = time();
        $session->studentscanmark = 0;
        $session->allowupdatestatus = 0;
        $session->studentsearlyopentime = 0;
        $session->autoassignstatus = 0;
        $session->studentpassword = '';
        $session->subnet = '';
        $session->automark = 0;
        $session->automarkcompleted = 0;
        $session->absenteereport = 1;
        $session->preventsharedip = 0;
        $session->preventsharediptime = null;
        $session->caleventid = 0;
        $session->calendarevent = 0;  // Don't create calendar events
        $session->includeqrcode = 0;
        $session->rotateqrcode = 0;
        $session->rotateqrcodesecret = null;
        $session->automarkcmid = 0;

        try {
            $sessionId = $DB->insert_record('attendance_sessions', $session);
            mtrace("    ✓ Created new session: ID={$sessionId}");
            mtrace("      Group: " . ($groupId ? $groupId : 'None (0)'));
            mtrace("      Date: " . date('Y-m-d H:i', $meeting->start_time));
            mtrace("      Duration: " . $actualDuration . " minutes");
            mtrace("      Description: '{$description}'");
            return $sessionId;
        } catch (\Exception $e) {
            mtrace("      ERROR creating session: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update session description to mark automatic processing
     * @param int $sessionId The session ID
     * @param string $currentDescription Current description text
     * @param bool $hasGroup Whether session has a group (true) or is groupless (false)
     */
    private function updateSessionDescription($sessionId, $currentDescription, $hasGroup) {
        global $DB;

        if ($hasGroup) {
            $marker = 'asistencia de sesion automatica';
        } else {
            $marker = 'sesion no normalizada';
        }

        // Only update if not already marked
        if (stripos($currentDescription, $marker) === false) {
            $newDescription = trim($currentDescription . ' - ' . $marker);
            $DB->set_field('attendance_sessions', 'description', $newDescription, ['id' => $sessionId]);
            mtrace("      Updated description with: '{$marker}'");
        }
    }

    private function recordAttendance($sessionId, $students, $teachers) {
        global $DB;

        $attendanceId = $DB->get_field('attendance_sessions', 'attendanceid', ['id' => $sessionId]);
        $session = $DB->get_record('attendance_sessions', ['id' => $sessionId], 'sessdate, duration, groupid');

        if (!$session) {
            mtrace("      ERROR: Session {$sessionId} not found");
            return;
        }

        $statuses = $this->getStatuses($attendanceId);

        // Get config including use_email_matching setting
        $config = $DB->get_record('ortattendance', ['course' => $this->courseId],
            'min_percentage, late_tolerance, camera_required, use_email_matching');

        if (!$config) {
            mtrace("      ERROR: No ortattendance configuration found for course {$this->courseId}");
            return;
        }

        $useEmailMatching = !empty($config->use_email_matching);

        // Enrolled users by email (only if email matching enabled)
        $enrolledByEmail = [];
        if ($useEmailMatching) {
            $context = \context_course::instance($this->courseId);

            // FIX: Only get users from the session's group (if session has a group)
            if (!empty($session->groupid)) {
                // Get group members only
                $groupMembers = \groups_get_members($session->groupid, 'u.id, u.email');
                foreach ($groupMembers as $user) {
                    $enrolledByEmail[strtolower($user->email)] = $user->id;
                }
                mtrace("      Loaded " . count($enrolledByEmail) . " users from group {$session->groupid}");
            } else {
                // Session has no group - get all enrolled users
                $enrolledUsers = \get_enrolled_users($context, '', 0, 'u.id, u.email');
                foreach ($enrolledUsers as $user) {
                    $enrolledByEmail[strtolower($user->email)] = $user->id;
                }
                mtrace("      Loaded " . count($enrolledByEmail) . " enrolled users (no group)");
            }
        }

        $allParticipants = array_merge($students, $teachers);
        $recorded = 0;

        foreach ($allParticipants as $participant) {

            // Determine user ID
            if ($useEmailMatching) {
                $email = strtolower($participant->getEmail());
                if (!isset($enrolledByEmail[$email])) {
                    mtrace("      Warning: User with email '{$email}' not enrolled in course");
                    continue;
                }
                $userId = $enrolledByEmail[$email];
            } else {
                $userId = $participant->getUserId();
                if (!$userId) {
                    mtrace("      Warning: No user ID for participant '{$participant->getName()}'");
                    continue;
                }
            }

            // Determine status BEFORE writing
            $statusId = $this->determineStatus(
                $participant,
                $session->sessdate,
                $session->duration,
                $config,
                $statuses
            );

            // Obtain existing attendance log (if any)
            $existingLog = $DB->get_record('attendance_log', [
                'sessionid' => $sessionId,
                'studentid' => $userId
            ]);

            if ($existingLog) {
                $existingLog->statusid  = $statusId;
                $existingLog->remarks   = 'Ort Attendance Bot';  // FIX #3: Identify bot
                $existingLog->timetaken = time();
                $existingLog->takenby   = 2;

                $DB->update_record('attendance_log', $existingLog);

            } else {
                $log = new \stdClass();
                $log->sessionid  = $sessionId;
                $log->studentid  = $userId;
                $log->statusid   = $statusId;
                $log->statusset  = '';
                $log->timetaken  = time();
                $log->takenby    = 2;
                $log->remarks    = 'Ort Attendance Bot';  // FIX #3: Identify bot

                $DB->insert_record('attendance_log', $log);
            }

            $recorded++;
        }

        mtrace("      Recorded {$recorded} attendances");

        // Mark absent students only when email matching is used
        if ($useEmailMatching) {
            $this->markAbsentStudents($sessionId, $enrolledByEmail, $statuses['absent']);
        }
    }

    private function getStatuses($attendanceId) {
        global $DB;
        
        $records = $DB->get_records('attendance_statuses', ['attendanceid' => $attendanceId]);
        
        $statuses = [];
        foreach ($records as $record) {
            $acronym = strtolower($record->acronym);
            if ($acronym == 'p') $statuses['present'] = $record->id;
            elseif ($acronym == 'l') $statuses['late'] = $record->id;
            elseif ($acronym == 'a') $statuses['absent'] = $record->id;
        }
        
        if (empty($statuses['late'])) {
            $statuses['late'] = $statuses['present'];
        }
        
        return $statuses;
    }

    private function determineStatus($participant, $meetingStart, $duration, $config, $statuses) {
        // Check attendance percentage
        // Note: participant->duration is in MINUTES, $duration is in SECONDS
        $attendedSeconds = $participant->duration * 60; // Convert minutes to seconds
        $attendancePercent = ($attendedSeconds / $duration) * 100;
        
        if ($attendancePercent < $config->min_percentage) {
            return $statuses['absent'];
        }
        
        // Check camera requirement
        if ($config->camera_required && !$participant->hasVideo) {
            return $statuses['absent'];
        }
        
        // Check late tolerance
        $joinTime = $participant->getJoinTime();  // Already a unix timestamp
        $lateSeconds = $config->late_tolerance * 60;

        if ($joinTime > ($meetingStart + $lateSeconds)) {
            return $statuses['late'];
        }
        
        return $statuses['present'];
    }

    private function markAbsentStudents($sessionId, $enrolledByEmail, $presentId) {
        global $DB;
        
        // Get absent status
        $attendanceId = $DB->get_field('attendance_sessions', 'attendanceid', ['id' => $sessionId]);
        $statuses = $DB->get_records('attendance_statuses', ['attendanceid' => $attendanceId]);
        
        $absentId = null;
        foreach ($statuses as $status) {
            if (strtolower($status->acronym) == 'a') {
                $absentId = $status->id;
                break;
            }
        }
        
        if (!$absentId) {
            mtrace("      WARNING: No 'Absent' status found");
            return;
        }
        
        $markedAbsent = 0;
        
        foreach ($enrolledByEmail as $email => $userId) {
            if (!$DB->record_exists('attendance_log', ['sessionid' => $sessionId, 'studentid' => $userId])) {
                $log = new \stdClass();
                $log->sessionid = $sessionId;
                $log->studentid = $userId;
                $log->statusid = $absentId;
                $log->statusset = '';
                $log->timetaken = time();
                $log->takenby = 2;
                $log->remarks = 'Ort Attendance Bot';  // FIX #3: Identify bot
                
                $DB->insert_record('attendance_log', $log);
                $markedAbsent++;
            }
        }
        
        mtrace("      Marked {$markedAbsent} students as absent");
    }
}