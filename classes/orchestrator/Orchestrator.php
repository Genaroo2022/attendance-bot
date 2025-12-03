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
use mod_ortattendance\utils\LogLevel;
use mod_ortattendance\services\QueueService;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../persistence/BasePersistence.php');
require_once(__DIR__ . '/../persistence/AttendancePersistence.php');
require_once(__DIR__ . '/../recollectors/BaseRecollector.php');
require_once(__DIR__ . '/../recollectors/ZoomRecollectorData.php');
require_once(__DIR__ . '/../recollectors/ZoomRecollectorBackup.php');
require_once(__DIR__ . '/../utils/ZoomUtils.php');
require_once(__DIR__ . '/../utils/LogLevel.php');
require_once(__DIR__ . '/../services/QueueService.php');

class Orchestrator {

    private $recollector;
    private $backupRecollector;
    private $persistence;
    private $courseId;
    private $courseShortname;
    private $installationId;
    private $checkCamera;
    private $recollectorType;

    public function __construct($courseId, $installationId) {
        global $DB;

        $this->courseId = $courseId;
        $this->installationId = $installationId;

        // Get course shortname for better logging
        $course = $DB->get_record('course', ['id' => $courseId], 'shortname', IGNORE_MISSING);
        $this->courseShortname = $course ? $course->shortname : "course-{$courseId}";

        $this->loadValues();
    }

    /**
     * Get formatted course identifier for logging
     */
    private function getCourseLogPrefix(): string {
        return "[Course {$this->courseId} ({$this->courseShortname})]";
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

        $logPrefix = $this->getCourseLogPrefix();

        // Defensive: Ensure arrays
        if (!is_array($students)) {
            LogLevel::warning('students is not an array, converting to empty array', $logPrefix);
            $students = [];
        }
        if (!is_array($teachers)) {
            LogLevel::warning('teachers is not an array, converting to empty array', $logPrefix);
            $teachers = [];
        }

        LogLevel::info('Students found: ' . count($students), $logPrefix);
        LogLevel::info('Teachers found: ' . count($teachers), $logPrefix);

        $absentStudents = [];

        if (count($students) > 0 || count($teachers) > 0) {
            $absentStudents = $this->persistence->persistStudents($students, $teachers);
        }

        // Defensive: Ensure absentStudents is array
        if (!is_array($absentStudents)) {
            LogLevel::warning('absentStudents is not an array, converting to empty array', $logPrefix);
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

        LogLevel::info("Orchestrator: Using recollector type: {$this->recollectorType}");

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
                LogLevel::debug("Creating ZoomRecollectorData");
                return new ZoomRecollectorData($this->courseId, $this->checkCamera, $this->installationId);

            default:
                LogLevel::warning("Unknown recollector type '{$recollectorType}', defaulting to ZoomRecollectorData");
                return new ZoomRecollectorData($this->courseId, $this->checkCamera, $this->installationId);
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
                LogLevel::debug("Creating ZoomRecollectorBackup");
                return new ZoomRecollectorBackup($this->courseId);

            default:
                LogLevel::warning("Unknown recollector type '{$recollectorType}', defaulting to ZoomRecollectorBackup");
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
        $logPrefix = $this->getCourseLogPrefix();
        LogLevel::info("Queueing recordings for async processing (recollector type: {$this->recollectorType})", $logPrefix);

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
                    LogLevel::info("No Zoom instances found", $logPrefix);
                    return;
                }

                $totalQueued = 0;
                $totalSkipped = 0;
                $totalErrors = 0;

                foreach ($zoomIds as $zoomId) {
                    try {
                        $meetings = $this->recollector->getMeetingsByRecollectorId($zoomId);

                        if (empty($meetings)) {
                            LogLevel::debug("No meetings found for Zoom ID: {$zoomId}", $logPrefix);
                            continue;
                        }

                        // Prepare recordings for queue
                        $recordings = [];
                        foreach ($meetings as $meeting) {
                            if (!isset($meeting->meeting_id)) {
                                LogLevel::warning("Meeting missing meeting_id, skipping", $logPrefix);
                                continue;
                            }

                            $recordings[] = (object)[
                                'meeting_id' => $meeting->meeting_id,
                                'meeting_name' => $meeting->topic ?? 'Unknown Meeting'
                            ];
                        }

                        if (empty($recordings)) {
                            LogLevel::debug("No valid recordings to queue for Zoom ID: {$zoomId}", $logPrefix);
                            continue;
                        }

                        // Add to backup queue
                        $result = QueueService::addRecordingsToBackup($this->installationId, $recordings);
                        $totalQueued += $result['queued'];
                        $totalSkipped += $result['skipped'];

                        LogLevel::info("Zoom ID {$zoomId}: Queued {$result['queued']}, Skipped {$result['skipped']} recordings", $logPrefix);

                    } catch (\Exception $e) {
                        LogLevel::error("Error processing Zoom ID {$zoomId}: " . $e->getMessage(), $logPrefix);
                        $totalErrors++;
                    }
                }

                LogLevel::info("Total queued: {$totalQueued}, Total skipped: {$totalSkipped}, Errors: {$totalErrors}", $logPrefix);

                $pending = QueueService::countPendingBackups($this->installationId);
                LogLevel::info("Total pending backups in queue: {$pending}", $logPrefix);
            }
        } catch (\Exception $e) {
            LogLevel::error("Fatal error in processRecordings: " . $e->getMessage(), $logPrefix);
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
        $logPrefix = $this->getCourseLogPrefix();
        $moduleId = $this->getModuleId($moduleName);

        if (!$moduleId) {
            LogLevel::warning("Module '{$moduleName}' not found", $logPrefix);
            return [];
        }

        $sql = "SELECT * FROM {course_modules} WHERE course = :course AND module = :moduleid AND deletioninprogress = 0";
        $pluginModules = $DB->get_records_sql($sql, array('course' => $courseId, 'moduleid' => $moduleId));

        // Ensure pluginModules is always an array
        if (!is_array($pluginModules)) {
            LogLevel::warning("Query for module instances returned non-array", $logPrefix);
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
        $logPrefix = $this->getCourseLogPrefix();
        $sql = "SELECT id FROM {modules} WHERE name = :module_name";
        $module = $DB->get_record_sql($sql, ['module_name' => $moduleName]);

        if (!$module) {
            LogLevel::warning("Module '{$moduleName}' not found in modules table", $logPrefix);
            return null;
        }

        return $module->id;
    }
}