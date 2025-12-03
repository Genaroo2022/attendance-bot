<?php
namespace mod_ortattendance\persistence;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/BasePersistence.php');

class AttendancePersistence extends BasePersistence {

    // CONFIG: Thresholds for irregular meeting detection - easily searchable
    const MIN_MEETING_DURATION = 15;     // CONFIG: minutes - flag meetings shorter than this
    const MIN_PARTICIPANT_COUNT = 5;     // CONFIG: participants - flag meetings with fewer than this

    private $courseId;

    public function __construct($courseId) {
        $this->courseId = $courseId;
    }
    
    public function persistStudents($students, $teachers) {
        global $DB;

        mtrace("Persisting " . count($students) . " students and " . count($teachers) . " teachers");

        try {
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
                try {
                    $meetingId = $participants['meetingid'];
                    mtrace("  Processing session for meeting: {$meetingId}");

                    // Pass groupid to session matching
                    $groupId = $participants['groupid'] ?? null;
                    $sessionId = $this->getOrCreateSession($attendance->id, $meetingId, $participants, $groupId);

                    if (!$sessionId) {
                        mtrace("    ERROR: Could not create session");
                        continue;
                    }

                    // Detect irregular meeting and pass flag to recordAttendance
                    $meeting = $DB->get_record('zoom_meeting_details', ['meeting_id' => $meetingId]);
                    $irregularReason = null;
                    if ($meeting) {
                        $participantCount = count($participants['students']) + count($participants['teachers']);
                        $validation = $this->detectIrregularMeeting($meeting, $participantCount);
                        $irregularReason = $validation['is_irregular'] ? $validation['reason'] : null;
                    }

                    $this->recordAttendance($sessionId, $participants['students'], $participants['teachers'], $irregularReason);
                } catch (\Exception $e) {
                    mtrace("    Error processing session: " . $e->getMessage());
                    // Continue to next session
                    continue;
                }
            }
        } catch (\Exception $e) {
            mtrace("  ERROR in persistStudents: " . $e->getMessage());
            throw $e;
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
        $description = "";
        if (!empty($meeting->topic)) {
            $description = $meeting->topic . " - " . $description;
        }

        // Detect irregular meetings and add flag to description
        $participantCount = $meeting->participants_count ?? 0;
        $validation = $this->detectIrregularMeeting($meeting, $participantCount);
        if ($validation['is_irregular']) {
            $description .= " [{$validation['reason']}]";
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

    private function recordAttendance($sessionId, $students, $teachers, $irregularReason = null) {
        global $DB;

        try {
            $attendanceId = $DB->get_field('attendance_sessions', 'attendanceid', ['id' => $sessionId]);
            if (!$attendanceId) {
                mtrace("      ERROR: Could not get attendance ID for session {$sessionId}");
                return;
            }

            $session = $DB->get_record('attendance_sessions', ['id' => $sessionId], 'sessdate, duration, groupid');

            if (!$session) {
                mtrace("      ERROR: Session {$sessionId} not found");
                return;
            }

            $statuses = $this->getStatuses($attendanceId);

            // Get configuration settings
            $config = $DB->get_record('ortattendance', ['course' => $this->courseId],
                'id, min_percentage, late_tolerance, camera_required');

            if (!$config) {
                mtrace("      ERROR: No ortattendance configuration found for course {$this->courseId}");
                return;
            }
        } catch (\Exception $e) {
            mtrace("      ERROR loading session data: " . $e->getMessage());
            return;
        }

        // Get enrolled users for absent marking
        $enrolledUserIds = [];
        $context = \context_course::instance($this->courseId);

        // FIX: Only get users from the session's group (if session has a group)
        if (!empty($session->groupid)) {
            // Get group members only
            $groupMembers = \groups_get_members($session->groupid, 'u.id');

            // Ensure groupMembers is always an array
            if (!is_array($groupMembers)) {
                mtrace("      Warning: groups_get_members returned non-array for group {$session->groupid}");
                $groupMembers = [];
            }

            foreach ($groupMembers as $user) {
                $enrolledUserIds[$user->id] = true;
            }
            mtrace("      Loaded " . count($enrolledUserIds) . " users from group {$session->groupid}");
        } else {
            // Session has no group - get all enrolled users
            $enrolledUsers = \get_enrolled_users($context, '', 0, 'u.id');

            // Ensure enrolledUsers is always an array
            if (!is_array($enrolledUsers)) {
                mtrace("      Warning: get_enrolled_users returned non-array");
                $enrolledUsers = [];
            }

            foreach ($enrolledUsers as $user) {
                $enrolledUserIds[$user->id] = true;
            }
            mtrace("      Loaded " . count($enrolledUserIds) . " enrolled users (no group)");
        }

        $allParticipants = array_merge($students, $teachers);
        $recorded = 0;
        $failed = 0;

        foreach ($allParticipants as $participant) {
            try {
                // Get user ID from participant (already validated by name in recollector)
                $userId = $participant->getUserId();
                if (empty($userId)) {
                    mtrace("      Warning: No user ID for participant - skipping");
                    continue;
                }

                // Double check we have a valid user ID
                if (empty($userId)) {
                    mtrace("      Warning: Could not determine user ID - skipping");
                    continue;
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

                // Build remark with participant join/leave times
                $baseRemark = '';
                $timePart = '';

                // Check if participant is not absent (times only for present/late)
                $isAbsent = ($statusId == $statuses['absent']);

                // Build time part only for present and late (not absent)
                if (!$isAbsent) {
                    $joinTime = $participant->getJoinTime();
                    $leaveTime = $participant->getLeaveTime();
                    if (!empty($joinTime) && !empty($leaveTime)) {
                        $joinFormatted = date('G:i', $joinTime);
                        $leaveFormatted = date('G:i', $leaveTime);
                        $timePart = " / {$joinFormatted} - {$leaveFormatted}";
                    }
                }

                // Build complete remark
                $baseRemark = 'Ort Attendance' . $timePart;
                if (!empty($irregularReason)) {
                    $baseRemark .= " - {$irregularReason}";
                }

                if ($existingLog) {
                    $existingLog->statusid  = $statusId;
                    $existingLog->remarks   = $baseRemark;
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
                    $log->remarks    = $baseRemark;

                    $DB->insert_record('attendance_log', $log);
                }

                $recorded++;
            } catch (\Exception $e) {
                mtrace("      Warning: Failed to record attendance for participant: " . $e->getMessage());
                $failed++;
            }
        }

        mtrace("      Recorded {$recorded} attendances" . ($failed > 0 ? ", {$failed} failed" : ""));

        // Mark absent students (always run to ensure all enrolled users have records)
        if (!empty($enrolledUserIds)) {
            $this->markAbsentStudents($sessionId, $enrolledUserIds, $statuses['absent']);
        }

        // Mark attendance as taken (update session with lasttaken timestamp)
        try {
            $DB->set_field('attendance_sessions', 'lasttaken', time(), ['id' => $sessionId]);
            $DB->set_field('attendance_sessions', 'lasttakenby', 2, ['id' => $sessionId]);
            mtrace("      ✓ Marked attendance as taken for session {$sessionId}");
        } catch (\Exception $e) {
            mtrace("      Warning: Failed to mark session as taken: " . $e->getMessage());
        }
    }

    private function getStatuses($attendanceId) {
        global $DB;

        try {
            $records = $DB->get_records('attendance_statuses', ['attendanceid' => $attendanceId]);

            // Ensure records is always an array
            if (!is_array($records)) {
                throw new \Exception("Failed to query attendance statuses for attendance ID: {$attendanceId}");
            }

            if (empty($records)) {
                throw new \Exception("No attendance statuses found for attendance ID: {$attendanceId}");
            }

            $statuses = [];
            foreach ($records as $record) {
                $acronym = strtolower($record->acronym);
                if ($acronym == 'p') $statuses['present'] = $record->id;
                elseif ($acronym == 'l') $statuses['late'] = $record->id;
                elseif ($acronym == 'a') $statuses['absent'] = $record->id;
            }

            if (!isset($statuses['present']) || !isset($statuses['absent'])) {
                throw new \Exception("Required statuses (present/absent) not found for attendance ID: {$attendanceId}");
            }

            if (empty($statuses['late'])) {
                $statuses['late'] = $statuses['present'];
            }

            return $statuses;
        } catch (\Exception $e) {
            mtrace("      ERROR loading statuses: " . $e->getMessage());
            throw $e;
        }
    }

    private function determineStatus($participant, $meetingStart, $duration, $config, $statuses) {
        // Safety checks for required data
        if (empty($duration) || $duration <= 0) {
            mtrace("      Warning: Invalid duration ({$duration}), marking as absent");
            return $statuses['absent'];
        }

        $participantDuration = $participant->getDuration();
        if (empty($participantDuration)) {
            mtrace("      Warning: Participant has no duration, marking as absent");
            return $statuses['absent'];
        }

        // Check attendance percentage
        // Note: participant->duration is in MINUTES, $duration is in SECONDS
        $attendedSeconds = $participantDuration * 60; // Convert minutes to seconds
        $attendancePercent = ($attendedSeconds / $duration) * 100;

        if ($attendancePercent < $config->min_percentage) {
            return $statuses['absent'];
        }

        // Check camera requirement
        $hasVideo = $participant->getHasVideo();
        if ($config->camera_required && !$hasVideo) {
            return $statuses['absent'];
        }

        // Check late tolerance
        $joinTime = $participant->getJoinTime();
        if (empty($joinTime)) {
            mtrace("      Warning: Participant has no join time, marking as present");
            return $statuses['present'];
        }

        $lateSeconds = $config->late_tolerance * 60;

        if ($joinTime > ($meetingStart + $lateSeconds)) {
            return $statuses['late'];
        }

        return $statuses['present'];
    }

    private function markAbsentStudents($sessionId, $enrolledUserIds, $presentId) {
        global $DB;

        try {
            // Get absent status
            $attendanceId = $DB->get_field('attendance_sessions', 'attendanceid', ['id' => $sessionId]);
            if (!$attendanceId) {
                mtrace("      WARNING: Could not get attendance ID for session {$sessionId}");
                return;
            }

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
            $failed = 0;

            foreach ($enrolledUserIds as $userId => $ignored) {
                try {
                    if (!$DB->record_exists('attendance_log', ['sessionid' => $sessionId, 'studentid' => $userId])) {
                        $log = new \stdClass();
                        $log->sessionid = $sessionId;
                        $log->studentid = $userId;
                        $log->statusid = $absentId;
                        $log->statusset = '';
                        $log->timetaken = time();
                        $log->takenby = 2;
                        $log->remarks = 'Ort Attendance';

                        $DB->insert_record('attendance_log', $log);
                        $markedAbsent++;
                    }
                } catch (\Exception $e) {
                    mtrace("      Warning: Failed to mark user {$userId} as absent: " . $e->getMessage());
                    $failed++;
                }
            }

            mtrace("      Marked {$markedAbsent} students as absent" . ($failed > 0 ? ", {$failed} failed" : ""));
        } catch (\Exception $e) {
            mtrace("      ERROR in markAbsentStudents: " . $e->getMessage());
        }
    }

    /**
     * Detect if a meeting is irregular (too short or too few participants)
     *
     * @param object $meeting Meeting data from Zoom
     * @param int $participantCount Number of participants in this session
     * @return array ['is_irregular' => bool, 'reason' => string]
     */
    private function detectIrregularMeeting($meeting, $participantCount) {
        $reasons = [];

        // Check duration (with null safety)
        if (!empty($meeting->end_time) && !empty($meeting->start_time)) {
            $durationMinutes = intval(($meeting->end_time - $meeting->start_time) / 60);
            if ($durationMinutes < self::MIN_MEETING_DURATION) {
                $reasons[] = "reunión corta ({$durationMinutes} min)";
            }
        }

        // Check participant count
        if ($participantCount < self::MIN_PARTICIPANT_COUNT) {
            $reasons[] = "pocos participantes ({$participantCount})";
        }

        return [
            'is_irregular' => !empty($reasons),
            'reason' => implode(', ', $reasons)
        ];
    }
}