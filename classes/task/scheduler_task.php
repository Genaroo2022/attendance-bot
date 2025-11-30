<?php
namespace mod_ortattendance\task;

use mod_ortattendance\orchestrator\Orchestrator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../orchestrator/Orchestrator.php');

class scheduler_task extends \core\task\scheduled_task {
    
    public function get_name(): string {
        return get_string('schedulertask', 'mod_ortattendance');
    }
    
    public function execute(): void {
        global $DB;
        
        mtrace("Starting ortattendance scheduler task");
        
        // Get active instances
        $instances = $this->get_active_installations();

        if (empty($instances)) {
            mtrace("No active installations found. Exiting.");
            return;
        }

        mtrace("Found " . count($instances) . " active installations");

        foreach ($instances as $instance) {
            mtrace("Processing instance {$instance->id} for course {$instance->course}");

            try {
                // Create orchestrator
                $orchestrator = new Orchestrator($instance->course, $instance->id);

                // NEW: Daily chunking loop - process multiple days per run
                $maxDaysPerRun = get_config('mod_ortattendance', 'max_days_per_run') ?: 30;
                $maxExecutionTime = get_config('mod_ortattendance', 'max_execution_time') ?: 3000; // Default 50 minutes (stored in seconds)
                $startTime = time();
                $daysProcessed = 0;

                $maxExecutionMinutes = round($maxExecutionTime / 60);
                mtrace("  Starting daily chunking loop (max: {$maxDaysPerRun} days, timeout: {$maxExecutionMinutes} minutes)");

                while ($daysProcessed < $maxDaysPerRun) {
                    // Check timeout - exit gracefully if approaching limit
                    $elapsedTime = time() - $startTime;
                    if ($elapsedTime > $maxExecutionTime) {
                        mtrace("  ⚠ Approaching timeout after processing {$daysProcessed} days ({$elapsedTime}s elapsed)");
                        mtrace("  Exiting gracefully. Will resume on next run.");
                        break;
                    }

                    // Process next day
                    mtrace("  Processing day " . ($daysProcessed + 1) . "...");

                    try {
                        $result = $orchestrator->process();
                    } catch (\Exception $e) {
                        mtrace("  ✗ Error processing day: " . $e->getMessage());
                        mtrace("  Attempting to skip problematic day and continue...");
                        
                        // Try to advance the last_processed_date to skip this day
                        try {
                            $config = $DB->get_record('ortattendance', ['id' => $instance->id], 'last_processed_date, start_date');
                            if ($config) {
                                $skipDate = $config->last_processed_date ? $config->last_processed_date + 86400 : $config->start_date;
                                $DB->set_field('ortattendance', 'last_processed_date', $skipDate, ['id' => $instance->id]);
                                mtrace("  Skipped date: " . date('Y-m-d', $skipDate));
                                $daysProcessed++;
                                continue; // Try next day
                            }
                        } catch (\Exception $skipError) {
                            mtrace("  Could not skip day: " . $skipError->getMessage());
                        }
                        throw $e; // Re-throw if we couldn't skip
                    }

                    // Validate result
                    if (!is_array($result)) {
                        mtrace("  ✗ Error: process() returned invalid result. Stopping.");
                        break;
                    }

                    // Check result
                    if ($result['completed']) {
                        $daysProcessed++;
                        $processedDate = $result['date'] ?? 'unknown';
                        $absentCount = $result['absent_count'] ?? 0;
                        mtrace("    ✓ Completed: {$processedDate} ({$daysProcessed}/{$maxDaysPerRun} days, {$absentCount} absent)");
                    }

                    // Check if caught up or no more data
                    if ($result['caught_up']) {
                        mtrace("  ✓ Caught up to today! Processed {$daysProcessed} days total.");
                        break;
                    }

                    if ($result['no_more_data']) {
                        mtrace("  ℹ No more meetings to process.");
                        break;
                    }

                    // Brief pause between days to avoid overwhelming database
                    usleep(100000); // 0.1 second pause
                }

                mtrace("  Daily chunking complete: {$daysProcessed} days processed");

                // Process recordings if enabled (original functionality)
                if ($instance->backup_recordings) {
                    mtrace("  Processing recordings...");
                    $orchestrator->processRecordings();
                    mtrace("  Recordings queued for backup_task");
                }

            } catch (\Exception $e) {
                mtrace("  ✗ Error processing instance {$instance->id}: " . $e->getMessage());

                // Update status to error (check if field exists first)
                $dbman = $DB->get_manager();
                $table = new \xmldb_table('ortattendance');
                $field = new \xmldb_field('processing_status');
                if ($dbman->field_exists($table, $field)) {
                    $DB->set_field('ortattendance', 'processing_status', 'error', ['id' => $instance->id]);
                }
            }
        }
        
        mtrace("Scheduler task completed");
    }
    
    /**
     * Get all active ortattendance installations
     *
     * @return array Array of active installation records
     */
    private function get_active_installations(): array {
        global $DB;

        $now = time();

        // Get instances within their configured date range
        $sql = "SELECT ab.*
                FROM {ortattendance} ab
                JOIN {course_modules} cm ON cm.instance = ab.id
                JOIN {modules} m ON m.id = cm.module AND m.name = 'ortattendance'
                WHERE cm.deletioninprogress = 0";

        $results = $DB->get_records_sql($sql);

        // Defensive: Ensure we always return an array
        if (!is_array($results)) {
            mtrace("Warning: Database query for active installations returned non-array, using empty array");
            return [];
        }

        return $results;
    }
}