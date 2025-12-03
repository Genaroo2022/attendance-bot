<?php
/**
 * LogLevel utility - Provides standardized logging with levels
 *
 * @package     mod_ortattendance
 * @copyright   2025 Your Organization
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_ortattendance\utils;

defined('MOODLE_INTERNAL') || die();

class LogLevel {
    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';

    /**
     * Generic log method with level and optional context
     *
     * @param string $level Log level (DEBUG, INFO, WARNING, ERROR)
     * @param string $message Log message
     * @param string $context Optional context prefix (e.g., "[Course 123 (MATH-101)]")
     */
    public static function log($level, $message, $context = '') {
        $prefix = $context ? "{$context} " : '';
        mtrace("{$prefix}[{$level}] {$message}");
    }

    /**
     * Log debug message - for detailed processing steps
     *
     * @param string $message Debug message
     * @param string $context Optional context prefix
     */
    public static function debug($message, $context = '') {
        self::log(self::DEBUG, $message, $context);
    }

    /**
     * Log info message - for normal operation flow
     *
     * @param string $message Info message
     * @param string $context Optional context prefix
     */
    public static function info($message, $context = '') {
        self::log(self::INFO, $message, $context);
    }

    /**
     * Log warning message - for non-fatal issues
     *
     * @param string $message Warning message
     * @param string $context Optional context prefix
     */
    public static function warning($message, $context = '') {
        self::log(self::WARNING, $message, $context);
    }

    /**
     * Log error message - for exceptions and critical failures
     *
     * @param string $message Error message
     * @param string $context Optional context prefix
     */
    public static function error($message, $context = '') {
        self::log(self::ERROR, $message, $context);
    }
}
