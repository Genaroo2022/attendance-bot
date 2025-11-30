<?php
/**
 * Orchestrator - Coordinates attendance collection and recording management
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ortattendance\orchestrator;

use mod_ortattendance\persistence\BasePersistence;
use mod_ortattendance\persistence\AttendancePersistence;
use mod_ortattendance\recollectors\BaseRecollector;
use mod_ortattendance\recollectors\ZoomRecollectorData;
use mod_ortattendance\recollectors\ZoomRecollectorBackup;
use mod_ortattendance\utils\ZoomUtils;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../persistence/BasePersistence.php');
require_once(__DIR__ . '/../persistence/AttendancePersistence.php');
require_once(__DIR__ . '/../recollectors/BaseRecollector.php');
require_once(__DIR__ . '/../recollectors/ZoomRecollectorData.php');
require_once(__DIR__ . '/../recollectors/ZoomRecollectorBackup.php');
require_once(__DIR__ . '/../utils/ZoomUtils.php');

class Orchestrator {

    private $recollector;
    private $backupRecollector;
    private $persistence;
    private $courseId;
    private $installationId;
    private $checkCamera;
    private $recollectorType;

    public function __construct($courseId, $installationId) {
        $this->courseId = $courseId;
        $this->installationId = $installationId;
        $this->loadValues();
    }

    public function process(): array {
        $data = $this->recollector->getStudentsByCourseId();

        // NEW: Check if caught up (no more data to process)
        if (isset($data['caught_up']) && $data['caught_up']) {
            return [
                'completed' => false,
                'caught_up' => true,
                'no_more_data' => true,
                'date' => null,
                'absent_count' => 0
            ];
        }

        $students = $data['students'];
        $teachers = $data['teachers'];

        mtrace('    Students found: ' . count($students));
        mtrace('    Teachers found: ' . count($teachers));

        $absentStudents = [];

        if (count($students) > 0 || count($teachers) > 0) {
            $absentStudents = $this->persistence->persistStudents($students, $teachers);
        }

        return [
            'completed' => true,
            'caught_up' => $data['caught_up'] ?? false,
            'no_more_data' => false,
            'date' => $data['processed_date'] ?? null,
            'absent_count' => count($absentStudents)
        ];
    }

    private function loadValues(): void {
        global $DB;
        $installation = $DB->get_record("ortattendance", array("id" => $this->installationId), '*');

        if (!$installation) {
            throw new \moodle_exception('ortattendance_not_found', 'mod_ortattendance',
                '', $this->installationId, "Ortattendance installation with ID {$this->installationId} not found");
        }

        $this->checkCamera = (bool) $installation->camera_required;
        
        // Get recollector type from settings
        $this->recollectorType = get_config('mod_ortattendance', 'recollector_type') ?: 'zoom';
        
        mtrace("Orchestrator: Using recollector type: {$this->recollectorType}");
        
        $this->recollector = $this->dataRecollectorFactory($this->recollectorType);
        $this->backupRecollector = $this->backupRecollectorFactory($this->recollectorType);
        $this->persistence = $this->persistenceFactory('attendance');
    }

    /**
     * Factory method to create data recollector instance
     * 
     * @param string $recollectorType Type of recollector ('zoom')
     * @return BaseRecollector Data recollector instance
     */
    private function dataRecollectorFactory($recollectorType): BaseRecollector {
        switch ($recollectorType) {
            case "zoom":
                mtrace("  Creating ZoomRecollectorData");
                return new ZoomRecollectorData($this->courseId, $this->checkCamera);

            default:
                mtrace("  Unknown recollector type '{$recollectorType}', defaulting to ZoomRecollectorData");
                return new ZoomRecollectorData($this->courseId, $this->checkCamera);
        }
    }

    /**
     * Factory method to create backup recollector instance
     * 
     * @param string $recollectorType Type of recollector ('zoom')
     * @return BaseRecollector Backup recollector instance
     */
    private function backupRecollectorFactory($recollectorType): BaseRecollector {
        switch ($recollectorType) {
            case "zoom":
                mtrace("  Creating ZoomRecollectorBackup");
                return new ZoomRecollectorBackup($this->courseId);

            default:
                mtrace("  Unknown recollector type '{$recollectorType}', defaulting to ZoomRecollectorBackup");
                return new ZoomRecollectorBackup($this->courseId);
        }
    }

    /**
     * Factory method to create persistence instance
     * 
     * @param string $persistenceType Type of persistence
     * @return BasePersistence Persistence instance
     */
    private function persistenceFactory($persistenceType): BasePersistence {
        switch ($persistenceType) {
            case "attendance":
                return new AttendancePersistence($this->courseId);
            default:
                return new AttendancePersistence($this->courseId);
        }
    }

    /**
     * Process recordings for meetings
     * 
     * @return void
     */
    public function processRecordings(): void {
        mtrace("Orchestrator: Processing recordings with recollector type: {$this->recollectorType}");
        
        // ZoomRecollector still needs meeting IDs from database
        if ($this->recollectorType === 'local') {
            $this->backupRecollector->processRecordings();
        } else if ($this->backupRecollector) {
            // Use backup recollector for recordings
            $zoomIds = $this->getAllInstanceByModuleName('zoom', $this->courseId);
            
            foreach ($zoomIds as $zoomId) {
                $meetings = $this->recollector->getMeetingsByZoomId($zoomId); 
                $meetingIds = array_values(array_map(fn($r) => $r->meeting_id, $meetings)); 
                $this->backupRecollector->processRecordings($meetingIds);
            }
        }
    }
    
    /**
     * Get all instances of a module by name
     *
     * @param string $moduleName Module name
     * @param int $courseId Course ID
     * @return array Array of instance IDs
     */
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
    
    /**
     * Get module ID by name
     *
     * @param string $moduleName Module name
     * @return int|null Module ID or null
     */
    private function getModuleId($moduleName): ?int {
        global $DB;
        $sql = "SELECT id FROM {modules} WHERE name = :module_name";
        $module = $DB->get_record_sql($sql, ['module_name' => $moduleName]);

        if (!$module) {
            mtrace("WARNING: Module '{$moduleName}' not found in modules table");
            return null;
        }

        return $module->id;
    }
}