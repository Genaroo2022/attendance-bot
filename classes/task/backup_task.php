<?php
namespace mod_ortattendance\task;

use mod_ortattendance\services\BackupService;
use mod_ortattendance\services\QueueService;
use mod_ortattendance\utils\ZoomUtils;
use mod_ortattendance\recollectors\ZoomRecollectorBackup;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../services/BackupService.php');
require_once(__DIR__ . '/../services/QueueService.php');
require_once(__DIR__ . '/../utils/ZoomUtils.php');
require_once(__DIR__ . '/../recollectors/ZoomRecollectorBackup.php');

class backup_task extends \core\task\scheduled_task {
    
    public function get_name() {
        return get_string('backuptask', 'mod_ortattendance');
    }
    
    public function execute() {
        global $DB;
        
        mtrace("Starting ortattendance backup task");
        
        // Get backup limit from settings
        $backupLimit = get_config('mod_ortattendance', 'backup_limit') ?: 10;
        $recollectorType = get_config('mod_ortattendance', 'recollector_type') ?: 'zoom';
        
        mtrace("  Backup limit per run: {$backupLimit}");
        mtrace("  Recollector type: {$recollectorType}");
        
        // Get all instances with backup enabled
        $instances = $DB->get_records('ortattendance', ['backup_recordings' => 1]);
        mtrace("  Found " . count($instances) . " instances with backup enabled");
        
        foreach ($instances as $instance) {
            mtrace("Processing backups for instance {$instance->id} (course {$instance->course})");
            
            try {                
                // For Zoom: Process queued recordings
                $this->processBackupQueue($instance, $backupLimit, $recollectorType);
                
            } catch (\Exception $e) {
                mtrace("Error processing instance {$instance->id}: " . $e->getMessage());
            }
        }
        
        mtrace("Backup task completed");
    }
    
    /**
     * Process pending recordings from the backup queue
     * Works for Zoom
     */
    private function processBackupQueue($instance, $limit, $recollectorType) {
        mtrace("  Processing backup queue...");

        // Get pending recordings using QueueService
        $pending = QueueService::getPendingBackups($instance->id, $limit);

        // Defensive: Ensure pending is array
        if (!is_array($pending)) {
            mtrace("  Warning: getPendingBackups returned non-array");
            $pending = [];
        }

        mtrace("  Found " . count($pending) . " pending recordings");

        $processed = 0;
        $failed = 0;

        foreach ($pending as $item) {
            mtrace("    Processing: {$item->meeting_id} ({$item->meeting_name})");

            try {
                // Get the appropriate backup recollector
                if ($recollectorType === 'zoom') {
                    $backupRecollector = new ZoomRecollectorBackup($instance->course);
                } else {
                    mtrace("      Skipped: Unsupported recollector type: {$recollectorType}");
                    continue;
                }

                // Process the recording (delegates to BackupService)
                $backupRecollector->processRecordings([$item->meeting_id]);

                // Mark as processed using QueueService
                QueueService::markBackupProcessed($item->id);
                $processed++;

                mtrace("      Success");

            } catch (\Exception $e) {
                mtrace("      Error: " . $e->getMessage());
                $failed++;
            }
        }

        mtrace("  Instance {$instance->id}: Processed {$processed}, Failed {$failed}");

        // Report remaining pending backups
        $remaining = QueueService::countPendingBackups($instance->id);
        if ($remaining > 0) {
            mtrace("  {$remaining} recordings still pending for instance {$instance->id}");
        }
    }
}