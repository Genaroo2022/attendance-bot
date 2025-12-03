<?php
/**
 * Plugin version and other meta-data
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_ortattendance';
$plugin->version = 2025120311;
$plugin->requires = 2022112800;
$plugin->release = 'PRF-2025C2-YA-A-2';
$plugin->maturity = MATURITY_STABLE;
$plugin->dependencies = [
    'mod_attendance' => ANY_VERSION,
    'mod_zoom' => ANY_VERSION,
];