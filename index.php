<?php
require_once('../../config.php');

$id = required_param('id', PARAM_INT);

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);

require_login($course);
$PAGE->set_pagelayout('incourse');

$PAGE->set_url('/mod/ortattendance/index.php', ['id' => $id]);
$PAGE->set_title($course->shortname . ': ' . get_string('modulenameplural', 'mod_ortattendance'));
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add(get_string('modulenameplural', 'mod_ortattendance'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_ortattendance'));

$instances = get_all_instances_in_course('ortattendance', $course);

if (empty($instances)) {
    notice(get_string('thereareno', 'moodle', get_string('modulenameplural', 'mod_ortattendance')),
           new moodle_url('/course/view.php', ['id' => $course->id]));
    exit;
}

$table = new html_table();
$table->head = [get_string('name'), get_string('description')];
$table->align = ['left', 'left'];

foreach ($instances as $instance) {
    $link = html_writer::link(
        new moodle_url('/mod/ortattendance/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name)
    );
    
    $description = format_module_intro('ortattendance', $instance, $instance->coursemodule);
    
    $table->data[] = [$link, $description];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
