<?php
/**
 * PHPUnit bootstrap for GMTU Join Flow tests.
 *
 * Loads Brain Monkey, defines WordPress constants, and includes source files.
 */

// Composer autoloader (loads Brain Monkey, Mockery, PHPUnit polyfills)
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define WordPress constants the plugin expects
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Load all source files in dependency order.
require_once dirname(__DIR__) . '/src/Logger.php';
require_once dirname(__DIR__) . '/src/Postcode.php';
require_once dirname(__DIR__) . '/src/Branch.php';
require_once dirname(__DIR__) . '/src/Member.php';
require_once dirname(__DIR__) . '/src/Email.php';
require_once dirname(__DIR__) . '/src/PostcodeValidation.php';
require_once dirname(__DIR__) . '/src/BranchAssignment.php';
require_once dirname(__DIR__) . '/src/Tagging.php';
require_once dirname(__DIR__) . '/src/Notifications.php';
