<?php
namespace mod_ortattendance\models;

defined('MOODLE_INTERNAL') || die();

class AttendanceLog {
    
    public $id;
    public $userId;
    public $courseId;
    public $status;
    public $duration;
    public $hasVideo;
    public $timeCreated;
    
    public function __construct($data = null) {
        if ($data) {
            $this->id = $data->id ?? null;
            $this->userId = $data->userId ?? null;
            $this->courseId = $data->courseId ?? null;
            $this->status = $data->status ?? null;
            $this->duration = $data->duration ?? 0;
            $this->hasVideo = $data->hasVideo ?? false;
            $this->timeCreated = $data->timeCreated ?? time();
        }
    }
}