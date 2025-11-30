<?php
namespace mod_ortattendance\task;

use mod_ortattendance\orchestrator\Orchestrator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../orchestrator/Orchestrator.php');

class meeting_processor_task extends \core\task\adhoc_task {

    public function execute(): void {
        $data = $this->get_custom_data();
        
        if (!isset($data->instanceId) || !isset($data->courseId)) {
            mtrace("Error: Missing required data");
            return;
        }
        
        mtrace("Processing meeting for instance {$data->instanceId}");
        
        try {
            $orchestrator = new Orchestrator($data->courseId, $data->instanceId);
            $absentStudents = $orchestrator->process();
            
            mtrace("Processing completed. Absent students: " . count($absentStudents));
            
        } catch (\Exception $e) {
            mtrace("Error: " . $e->getMessage());
            throw $e;
        }
    }
}
