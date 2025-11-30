<?php
/**
 * Base persistence abstract class
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ortattendance\persistence;

defined('MOODLE_INTERNAL') || die();

abstract class BasePersistence {
    abstract public function persistStudents($students, $teachers);
}
