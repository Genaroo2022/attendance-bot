<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_ortattendance_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2025120306) {
        $table = new xmldb_table('ortattendance');

        // Add keep_local_files field
        $field = new xmldb_field('keep_local_files', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'delete_from_source');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add last_processed_date field for daily chunking
        $field = new xmldb_field('last_processed_date', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'camera_required');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add processing_status field for state tracking
        $field = new xmldb_field('processing_status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'idle', 'last_processed_date');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Drop deprecated tables that are no longer used
        $deprecatedTable = new xmldb_table('ortattendance_camera');
        if ($dbman->table_exists($deprecatedTable)) {
            $dbman->drop_table($deprecatedTable);
        }

        $deprecatedTable = new xmldb_table('ortattendance_cleanup');
        if ($dbman->table_exists($deprecatedTable)) {
            $dbman->drop_table($deprecatedTable);
        }

        $field = new xmldb_field('use_email_matching');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2025120306, 'ortattendance');
    }

    if ($oldversion < 2025120311) {
        // ensures no duplicates and removes any orphaned/misconfigured instances

        mtrace('Cleaning up all ortattendance instances...');

        $all_instances = $DB->get_records('ortattendance', [], '', 'id');
        $count = count($all_instances);

        if ($count > 0) {
            mtrace("Found {$count} ortattendance instance(s) - deleting all instances");

            foreach ($all_instances as $instance) {
                mtrace("  Deleting ortattendance instance ID {$instance->id}");

                // Also delete related course module entries
                $DB->delete_records_select('course_modules',
                    "instance = :instance AND module = (SELECT id FROM {modules} WHERE name = 'ortattendance')",
                    ['instance' => $instance->id]);

                // Delete the instance
                $DB->delete_records('ortattendance', ['id' => $instance->id]);
            }

            mtrace('All ortattendance instances deleted successfully');
        } else {
            mtrace('No ortattendance instances found');
        }

        // Add UNIQUE index on course field to prevent future duplicates
        $table = new xmldb_table('ortattendance');
        $index = new xmldb_index('idx_course_unique', XMLDB_INDEX_UNIQUE, ['course']);

        // Drop the old non-unique index if it exists
        $old_index = new xmldb_index('idx_course', XMLDB_INDEX_NOTUNIQUE, ['course']);
        if ($dbman->index_exists($table, $old_index)) {
            mtrace('Dropping old non-unique course index...');
            $dbman->drop_index($table, $old_index);
        }

        // Add the new unique index
        if (!$dbman->index_exists($table, $index)) {
            mtrace('Adding UNIQUE constraint on ortattendance.course field...');
            $dbman->add_index($table, $index);
            mtrace('UNIQUE constraint added successfully');
        }

        upgrade_mod_savepoint(true, 2025120311, 'ortattendance');
    }

    return true;
}
