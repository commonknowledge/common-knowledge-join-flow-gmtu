<?php
/**
 * Test stub for the parent plugin's Settings class.
 *
 * The real Settings::get() calls carbon_get_theme_option() and reads $_ENV.
 * This stub reads only from $_ENV, which is sufficient for tests to control
 * the Stripe secret key without the full WordPress/CarbonFields stack.
 */

namespace CommonKnowledge\JoinBlock;

if (!class_exists(Settings::class)) {
    class Settings
    {
        public static function get(string $key): ?string
        {
            return $_ENV[strtoupper($key)] ?? null;
        }
    }
}
