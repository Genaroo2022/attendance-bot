<?php
/**
 * Plugin settings
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Custom setting class for text input with unit conversion
 * Displays value in one unit (e.g., minutes) but stores in another (e.g., seconds)
 */
if (!class_exists('admin_setting_configtext_with_conversion')) {
    class admin_setting_configtext_with_conversion extends admin_setting_configtext {
    /** @var int Conversion multiplier */
    private $multiplier;

    /**
     * Constructor
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param mixed $defaultsetting
     * @param mixed $paramtype
     * @param int $multiplier Multiplier to convert display value to stored value
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $paramtype = PARAM_RAW, $multiplier = 1) {
        $this->multiplier = $multiplier;
        parent::__construct($name, $visiblename, $description, $defaultsetting, $paramtype);
    }

    /**
     * Return the setting - convert from stored value to display value
     *
     * @return mixed returns config if successful else null
     */
    public function get_setting() {
        $result = parent::get_setting();
        if ($result !== null && $this->multiplier != 1) {
            // Convert from seconds to minutes (divide)
            $result = intval($result / $this->multiplier);
        }
        return $result;
    }

    /**
     * Store the setting - convert from display value to stored value
     *
     * @param mixed $data
     * @return string empty string if ok, error message otherwise
     */
    public function write_setting($data) {
        if ($this->multiplier != 1 && $data !== '') {
            // Convert from minutes to seconds (multiply)
            $data = intval($data) * $this->multiplier;
        }
        return parent::write_setting($data);
    }
    }
}

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

    // ==================== DAILY CHUNKING CONFIGURATION ====================
    $settings->add(new admin_setting_heading('ortattendance/chunkingheading',
        get_string('chunkingsettings', 'mod_ortattendance'),
        get_string('chunkingsettings_desc', 'mod_ortattendance')));

    // Maximum days to process per task run
    $settings->add(new admin_setting_configtext('mod_ortattendance/max_days_per_run',
        get_string('maxdaysperrun', 'mod_ortattendance'),
        get_string('maxdaysperrun_desc', 'mod_ortattendance'),
        '90',
        PARAM_INT));

    // Maximum execution time (minutes) before graceful exit - stored as seconds
    $settings->add(new admin_setting_configtext_with_conversion('mod_ortattendance/max_execution_time',
        get_string('maxexecutiontime', 'mod_ortattendance'),
        get_string('maxexecutiontime_desc', 'mod_ortattendance'),
        '50',
        PARAM_INT,
        60)); // Multiply by 60 to convert minutes to seconds

    // ==================== RECORDING BACKUP CONFIGURATION ====================
    $localdir = $CFG->dataroot.'/ortattendance_recordings';
    $settings->add(new admin_setting_heading('ortattendance/backupheading',
        get_string('backupsettings', 'mod_ortattendance'),
        get_string('backupsettings_desc', 'mod_ortattendance') . '<br><strong>' . get_string('localdirectory', 'mod_ortattendance') . ':</strong> ' . $localdir));

    // Backup download limit per task run
    $settings->add(new admin_setting_configtext('mod_ortattendance/backup_limit',
        get_string('backuplimit', 'mod_ortattendance'),
        get_string('backuplimit_desc', 'mod_ortattendance'),
        '10',
        PARAM_INT));
}
