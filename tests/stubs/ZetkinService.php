<?php
/**
 * Test stub for the parent plugin's ZetkinService.
 *
 * Retag.php compares tag-write outcomes against ZetkinService::TAG_*, so the
 * class has to exist for those comparisons to resolve. The parent plugin is
 * not installed in this suite, so stand in for it with the constants only.
 *
 * The values are duplicated from the parent deliberately: the parent pins
 * them with test_status_values_are_stable, and here the test fakes return
 * the wire literals while the code compares against this stub, so the two
 * drifting apart fails the suite.
 *
 * No methods are defined here on purpose: require_parent_plugin_helpers()
 * uses method_exists to detect an out-of-date parent, and the guard test
 * relies on them being absent.
 */

namespace CommonKnowledge\JoinBlock\Services;

if (!class_exists(ZetkinService::class)) {
    class ZetkinService
    {
        public const TAG_OK = 'ok';
        public const TAG_MISSING = 'missing';
        public const TAG_NOT_CONFIGURED = 'not_configured';
        public const TAG_ERROR = 'error';
    }
}
