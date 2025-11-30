<?php
/**
 * Base recollector abstract class
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ortattendance\recollectors;

defined('MOODLE_INTERNAL') || die();

abstract class BaseRecollector {
    
    /**
     * Get students and teachers from recollector source
     * Implemented by: ZoomRecollectorData
     * Not needed by: ZoomRecollectorBackup
     */
    public function getStudentsByCourseId() {
        throw new \Exception(get_class($this) . ' does not implement getStudentsByCourseId()');
    }
    
    /**
     * Process recordings from queue
     * Implemented by: ZoomRecollectorBackup
     * Not needed by: ZoomRecollectorData
     */
    public function processRecordings($meetingIds = []) {
        throw new \Exception(get_class($this) . ' does not implement processRecordings()');
    }
    
    /**
     * Get name of recollector
     */
    abstract public static function getName();
    
    /**
     * Get type identifier
     */
    abstract public static function getType();
}