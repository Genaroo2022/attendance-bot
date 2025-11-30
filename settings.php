<?php
/**
 * Plugin settings
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    
    // ==================== RECOLLECTOR CONFIGURATION ====================
    $settings->add(new admin_setting_heading('ortattendance/recollectorheading',
        get_string('recollectorsettings', 'mod_ortattendance'),
        get_string('recollectorsettings_desc', 'mod_ortattendance')));
    
    // Recollector type selector
    $recollectorOptions = [
        'zoom' => get_string('recollector_zoom', 'mod_ortattendance')
        //add future recolector here
    ];
    
    $settings->add(new admin_setting_configselect('mod_ortattendance/recollector_type',
        get_string('recollectortype', 'mod_ortattendance'),
        get_string('recollectortype_desc', 'mod_ortattendance'),
        'zoom',
        $recollectorOptions));
        
    // ==================== ZOOM CONFIGURATION ====================
    $settings->add(new admin_setting_heading('ortattendance/zoomconfig',
        get_string('zoomconfig', 'mod_ortattendance'),
        get_string('zoomconfig_desc', 'mod_ortattendance')));
    
    // ==================== RECORDING BACKUP CONFIGURATION ====================
    $settings->add(new admin_setting_heading('ortattendance/backupheading',
        get_string('backupsettings', 'mod_ortattendance'),
        get_string('backupsettings_desc', 'mod_ortattendance')));
    
    // Local directory for storing recordings
    $settings->add(new admin_setting_configtext('mod_ortattendance/local_directory',
        get_string('localdirectory', 'mod_ortattendance'),
        get_string('localdirectory_desc', 'mod_ortattendance'),
        $CFG->dataroot.'/ortattendance_recordings',
        PARAM_TEXT));
    
    // Backup download limit per task run
    $settings->add(new admin_setting_configtext('mod_ortattendance/backup_limit',
        get_string('backuplimit', 'mod_ortattendance'),
        get_string('backuplimit_desc', 'mod_ortattendance'),
        '10',
        PARAM_INT));
    
    // Maximum file size for backup (in MB)
    $settings->add(new admin_setting_configtext('mod_ortattendance/max_file_size',
        get_string('maxfilesize', 'mod_ortattendance'),
        get_string('maxfilesize_desc', 'mod_ortattendance'),
        '2048',
        PARAM_INT));

    // ==================== DAILY CHUNKING CONFIGURATION ====================
    $settings->add(new admin_setting_heading('ortattendance/chunkingheading',
        get_string('chunkingsettings', 'mod_ortattendance'),
        get_string('chunkingsettings_desc', 'mod_ortattendance')));

    // Maximum days to process per task run
    $settings->add(new admin_setting_configtext('mod_ortattendance/max_days_per_run',
        get_string('maxdaysperrun', 'mod_ortattendance'),
        get_string('maxdaysperrun_desc', 'mod_ortattendance'),
        '30',
        PARAM_INT));

    // Maximum execution time (seconds) before graceful exit
    $settings->add(new admin_setting_configtext('mod_ortattendance/max_execution_time',
        get_string('maxexecutiontime', 'mod_ortattendance'),
        get_string('maxexecutiontime_desc', 'mod_ortattendance'),
        '3000',
        PARAM_INT));
}
