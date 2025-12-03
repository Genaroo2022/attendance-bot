<?php
namespace mod_ortattendance\task;

use mod_ortattendance\orchestrator\Orchestrator;
use mod_ortattendance\utils\LogLevel;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../orchestrator/Orchestrator.php');
require_once(__DIR__ . '/../utils/LogLevel.php');

class scheduler_task extends \core\task\scheduled_task {
    
    public function get_name(): string {
        return get_string('schedulertask', 'mod_ortattendance');
    }
    
    public function execute(): void {
        global $DB;

        LogLevel::info("Starting ortattendance scheduler task");

        // Get active instances
        $instances = $this->get_active_installations();

        if (empty($instances)) {
            LogLevel::info("No active installations found. Exiting.");
            return;
        }

        LogLevel::info("Found " . count($instances) . " active installations");

        foreach ($instances as $instance) {
            // Get course shortname for context
            $course = $DB->get_record('course', ['id' => $instance->course], 'shortname', IGNORE_MISSING);
            $courseShortname = $course ? $course->shortname : "course-{$instance->course}";
            $instanceContext = "[Instance {$instance->id} Course {$instance->course} ({$courseShortname})]";

            LogLevel::info("Processing instance {$instance->id} for course {$instance->course}", $instanceContext);

            try {
                // Create orchestrator
                $orchestrator = new Orchestrator($instance->course, $instance->id);

                // NEW: Daily chunking loop - process multiple days per run
                $maxDaysPerRun = get_config('mod_ortattendance', 'max_days_per_run') ?: 30;
                $maxExecutionTime = get_config('mod_ortattendance', 'max_execution_time') ?: 3000; // Default 50 minutes (stored in seconds)
                $startTime = time();
                $daysProcessed = 0;

                $maxExecutionMinutes = round($maxExecutionTime / 60);
                LogLevel::info("Starting daily chunking loop (max: {$maxDaysPerRun} days, timeout: {$maxExecutionMinutes} minutes)", $instanceContext);

                while ($daysProcessed < $maxDaysPerRun) {
                    // Check timeout - exit gracefully if approaching limit
                    $elapsedTime = time() - $startTime;
                    if ($elapsedTime > $maxExecutionTime) {
                        LogLevel::warning("Approaching timeout after processing {$daysProcessed} days ({$elapsedTime}s elapsed)", $instanceContext);
                        LogLevel::info("Exiting gracefully. Will resume on next run.", $instanceContext);
                        break;
                    }

                    // Process next day
                    LogLevel::debug("Processing day " . ($daysProcessed + 1) . "...", $instanceContext);

                    try {
                        $result = $orchestrator->process();
                    } catch (\Exception $e) {
                        LogLevel::error("Error processing day: " . $e->getMessage(), $instanceContext);
                        LogLevel::info("Attempting to skip problematic day and continue...", $instanceContext);

                        // Try to advance the last_processed_date to skip this day
                        try {
                            $config = $DB->get_record('ortattendance', ['id' => $instance->id], 'last_processed_date, start_date');
                            if ($config) {
                                $skipDate = $config->last_processed_date ? $config->last_processed_date + 86400 : $config->start_date;
                                $DB->set_field('ortattendance', 'last_processed_date', $skipDate, ['id' => $instance->id]);
                                LogLevel::info("Skipped date: " . date('Y-m-d', $skipDate), $instanceContext);
                                $daysProcessed++;
                                continue; // Try next day
                            }
                        } catch (\Exception $skipError) {
                            LogLevel::error("Could not skip day: " . $skipError->getMessage(), $instanceContext);
                        }
                        throw $e; // Re-throw if we couldn't skip
                    }

                    // Validate result
                    if (!is_array($result)) {
                        LogLevel::error("process() returned invalid result. Stopping.", $instanceContext);
                        break;
                    }

                    // Check result
                    if ($result['completed']) {
                        $daysProcessed++;
                        $processedDate = $result['date'] ?? 'unknown';
                        $absentCount = $result['absent_count'] ?? 0;
                        LogLevel::info("Completed: {$processedDate} ({$daysProcessed}/{$maxDaysPerRun} days, {$absentCount} absent)", $instanceContext);
                    }

                    // Check if caught up or no more data
                    if ($result['caught_up']) {
                        LogLevel::info("Caught up to today! Processed {$daysProcessed} days total.", $instanceContext);
                        break;
                    }

                    if ($result['no_more_data']) {
                        LogLevel::info("No more meetings to process.", $instanceContext);
                        break;
                    }

                    // Brief pause between days to avoid overwhelming database
                    usleep(100000); // 0.1 second pause
                }

                LogLevel::info("Daily chunking complete: {$daysProcessed} days processed", $instanceContext);

                // Process recordings if enabled (original functionality)
                if ($instance->backup_recordings) {
                    LogLevel::debug("Processing recordings...", $instanceContext);
                    $orchestrator->processRecordings();
                    LogLevel::info("Recordings queued for backup_task", $instanceContext);
                }

            } catch (\Exception $e) {
                LogLevel::error("Error processing instance {$instance->id}: " . $e->getMessage(), $instanceContext);

                // Update status to error (check if field exists first)
                $dbman = $DB->get_manager();
                $table = new \xmldb_table('ortattendance');
                $field = new \xmldb_field('processing_status');
                if ($dbman->field_exists($table, $field)) {
                    $DB->set_field('ortattendance', 'processing_status', 'error', ['id' => $instance->id]);
                }
            }
        }
        
        LogLevel::info("Scheduler task completed");
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
            LogLevel::warning("Database query for active installations returned non-array, using empty array");
            return [];
        }

        return $results;
    }
}