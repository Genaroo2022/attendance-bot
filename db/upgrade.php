<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_ortattendance_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2025112401) {
    $table = new xmldb_table('ortattendance');
    $field = new xmldb_field('keep_local_files', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'delete_from_source');
    
    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }
    
    upgrade_mod_savepoint(true, 2025112401, 'ortattendance');
}

    if ($oldversion < 2025112401) {
        $table = new xmldb_table('ortattendance');
        $field = new xmldb_field('use_email_matching', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'camera_required');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2025112401, 'ortattendance');
    }

    if ($oldversion < 2025112401) {
        $table = new xmldb_table('ortattendance');

        // Add last_processed_date field for daily chunking
        $field = new xmldb_field('last_processed_date', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'use_email_matching');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add processing_status field for state tracking
        $field = new xmldb_field('processing_status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'idle', 'last_processed_date');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2025112401, 'ortattendance');
    }

    if ($oldversion < 2025112401) {
        // Drop deprecated tables that are no longer used
        $table = new xmldb_table('ortattendance_camera');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        $table = new xmldb_table('ortattendance_cleanup');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        upgrade_mod_savepoint(true, 2025112401, 'ortattendance');
    }

    return true;
}