<?php
namespace mod_ortattendance\utils;

defined('MOODLE_INTERNAL') || die();

class Statuses {
    
    const PRESENT = 'P';
    const ABSENT = 'A';
    const LATE = 'L';
    const EXCUSED = 'E';
    
    public static function getStatusByDuration($duration, $totalDuration, $minPercentage) {
        $percentage = ($duration / $totalDuration) * 100;
        
        if ($percentage >= $minPercentage) {
            return self::PRESENT;
        } elseif ($percentage > 0) {
            return self::LATE;
        } else {
            return self::ABSENT;
        }
    }
}
