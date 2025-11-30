<?php
namespace mod_ortattendance\utils;

defined('MOODLE_INTERNAL') || die();

class TeacherAttendance {

    public $userId;
    public $email;
    private $name;
    private $groupId;
    public $duration;
    public $hasVideo;
    public $joinTime;
    public $leaveTime;
    public $meetingId;

    public function __construct($participant, $hasVideo) {
        $this->userId = $participant->userid;
        $this->email = $participant->user_email;
        $this->name = $participant->name ?? 'Unknown';
        $this->groupId = $participant->groupid ?? 0;  // Always use 0 for no group, never null
        $this->duration = $participant->duration ?? 0;
        $this->hasVideo = $hasVideo;
        $this->joinTime = $participant->join_time ?? 0;
        $this->leaveTime = $participant->leave_time ?? 0;
        $this->meetingId = $participant->meeting_id ?? '';
    }
    
    public function getEmail() {
        return $this->email;
    }

    public function getUserId() {
        return $this->userId;
    }
    
    public function getMeetingId() {
        return $this->meetingId;
    }
    
    public function getJoinTime() {
        return $this->joinTime;
    }

    public function getGroupId() {
        return $this->groupId;
    }

    public function getName() {
        return $this->name;
    }
}