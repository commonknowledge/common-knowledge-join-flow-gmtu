<?php
/**
 * Logger utilities for GMTU join flow extensions.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Get the join block log instance if available.
 *
 * @since 1.1.0
 *
 * @return object|null The log instance or null if not available.
 */
function get_log() {
    global $joinBlockLog;
    return !empty($joinBlockLog) ? $joinBlockLog : null;
}

/**
 * Log an info message if logging is available.
 *
 * @since 1.1.0
 *
 * @param string $message The message to log.
 * @return void
 */
function log_info($message) {
    $log = get_log();
    if ($log) {
        $log->info($message);
    }
}

/**
 * Log a warning message if logging is available.
 *
 * @since 1.1.0
 *
 * @param string $message The message to log.
 * @return void
 */
function log_warning($message) {
    $log = get_log();
    if ($log) {
        $log->warning($message);
    }
}

