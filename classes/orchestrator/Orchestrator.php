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
use mod_ortattendance\services\QueueService;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../persistence/BasePersistence.php');
require_once(__DIR__ . '/../persistence/AttendancePersistence.php');
require_once(__DIR__ . '/../recollectors/BaseRecollector.php');
require_once(__DIR__ . '/../recollectors/ZoomRecollectorData.php');
require_once(__DIR__ . '/../recollectors/ZoomRecollectorBackup.php');
require_once(__DIR__ . '/../utils/ZoomUtils.php');
require_once(__DIR__ . '/../services/QueueService.php');

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

        // Validate data structure
        if (!is_array($data)) {
            throw new \Exception("Recollector returned invalid data (not an array)");
        }

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

        // Validate required keys exist
        if (!isset($data['students']) || !isset($data['teachers'])) {
            throw new \Exception("Recollector returned incomplete data (missing students or teachers)");
        }

        $students = $data['students'];
        $teachers = $data['teachers'];

        // Defensive: Ensure arrays
        if (!is_array($students)) {
            mtrace('    WARNING: students is not an array, converting to empty array');
            $students = [];
        }
        if (!is_array($teachers)) {
            mtrace('    WARNING: teachers is not an array, converting to empty array');
            $teachers = [];
        }

        mtrace('    Students found: ' . count($students));
        mtrace('    Teachers found: ' . count($teachers));

        $absentStudents = [];

        if (count($students) > 0 || count($teachers) > 0) {
            $absentStudents = $this->persistence->persistStudents($students, $teachers);
        }

        // Defensive: Ensure absentStudents is array
        if (!is_array($absentStudents)) {
            mtrace('    WARNING: absentStudents is not an array, converting to empty array');
            $absentStudents = [];
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
     * Queue recordings for async processing (instead of processing synchronously)
     *
     * @return void
     */
    public function processRecordings(): void {
        mtrace("Orchestrator: Queueing recordings for async processing (recollector type: {$this->recollectorType})");

        try {
            // For local recollector, still process synchronously
            if ($this->recollectorType === 'local') {
                $this->backupRecollector->processRecordings();
                return;
            }

            // For Zoom: Queue recordings for async processing via backup_task
            if ($this->backupRecollector) {
                $zoomIds = $this->getAllInstanceByModuleName('zoom', $this->courseId);

                if (empty($zoomIds)) {
                    mtrace("  No Zoom instances found for course {$this->courseId}");
                    return;
                }

                $totalQueued = 0;
                $totalSkipped = 0;
                $totalErrors = 0;

                foreach ($zoomIds as $zoomId) {
                    try {
                        $meetings = $this->recollector->getMeetingsByRecollectorId($zoomId);

                        if (empty($meetings)) {
                            mtrace("  No meetings found for Zoom ID: {$zoomId}");
                            continue;
                        }

                        // Prepare recordings for queue
                        $recordings = [];
                        foreach ($meetings as $meeting) {
                            if (!isset($meeting->meeting_id)) {
                                mtrace("  Warning: Meeting missing meeting_id, skipping");
                                continue;
                            }

                            $recordings[] = (object)[
                                'meeting_id' => $meeting->meeting_id,
                                'meeting_name' => $meeting->topic ?? 'Unknown Meeting'
                            ];
                        }

                        if (empty($recordings)) {
                            mtrace("  No valid recordings to queue for Zoom ID: {$zoomId}");
                            continue;
                        }

                        // Add to backup queue
                        $result = QueueService::addRecordingsToBackup($this->installationId, $recordings);
                        $totalQueued += $result['queued'];
                        $totalSkipped += $result['skipped'];

                        mtrace("  Zoom ID {$zoomId}: Queued {$result['queued']}, Skipped {$result['skipped']} recordings");

                    } catch (\Exception $e) {
                        mtrace("  Error processing Zoom ID {$zoomId}: " . $e->getMessage());
                        $totalErrors++;
                    }
                }

                mtrace("Orchestrator: Total queued: {$totalQueued}, Total skipped: {$totalSkipped}, Errors: {$totalErrors}");

                $pending = QueueService::countPendingBackups($this->installationId);
                mtrace("Orchestrator: Total pending backups in queue: {$pending}");
            }
        } catch (\Exception $e) {
            mtrace("Orchestrator: Fatal error in processRecordings: " . $e->getMessage());
            throw $e;
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
        
        if (!$moduleId) {
            mtrace("  Warning: Module '{$moduleName}' not found");
            return [];
        }
        
        $sql = "SELECT * FROM {course_modules} WHERE course = :course AND module = :moduleid AND deletioninprogress = 0";
        $pluginModules = $DB->get_records_sql($sql, array('course' => $courseId, 'moduleid' => $moduleId));
        
        // Ensure pluginModules is always an array
        if (!is_array($pluginModules)) {
            mtrace("  Warning: Query for module instances returned non-array");
            return [];
        }
        
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