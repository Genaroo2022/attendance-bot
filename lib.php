<?php
defined('MOODLE_INTERNAL') || die();

function ortattendance_add_instance($data, $mform = null) {
    global $DB;
    
    $data->timecreated = time();
    $data->timemodified = time();
    
    $data->start_time = ($data->start_hour * 3600) + ($data->start_minute * 60);
    $data->end_time = ($data->end_hour * 3600) + ($data->end_minute * 60);
    
    $data->id = $DB->insert_record('ortattendance', $data);
    
    return $data->id;
}

function ortattendance_update_instance($data, $mform = null) {
    global $DB;
    
    $data->id = $data->instance;
    $data->timemodified = time();
    
    $data->start_time = ($data->start_hour * 3600) + ($data->start_minute * 60);
    $data->end_time = ($data->end_hour * 3600) + ($data->end_minute * 60);
    
    return $DB->update_record('ortattendance', $data);
}

function ortattendance_delete_instance($id) {
    global $DB;

    if (!$instance = $DB->get_record('ortattendance', ['id' => $id])) {
        return false;
    }

    $DB->delete_records('ortattendance_queue', ['bot_id' => $id]);
    $DB->delete_records('ortattendance_backup', ['bot_id' => $id]);
    $DB->delete_records('ortattendance', ['id' => $id]);

    return true;
}

function ortattendance_supports($feature) {
    switch($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        default:
            return null;
    }
}