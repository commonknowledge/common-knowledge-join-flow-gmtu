<?php
/**
 * Test stub for the parent plugin's MailchimpService.
 *
 * Retag.php compares tag-write outcomes against MailchimpService::TAG_*, so
 * the class has to exist for those comparisons to resolve. The parent plugin
 * is not installed in this suite, so stand in for it with the constants only.
 *
 * The values are duplicated from the parent deliberately. If they ever drift
 * apart, the re-tag job silently stops recognising Mailchimp's answers, so the
 * parent pins them with a test of its own (testStatusValuesAreStable) and this
 * stub is the other half of that contract.
 *
 * No methods are defined here on purpose: require_parent_plugin_helpers() uses
 * method_exists to detect an out-of-date parent, and the guard test relies on
 * them being absent.
 */

namespace CommonKnowledge\JoinBlock\Services;

if (!class_exists(MailchimpService::class)) {
    class MailchimpService
    {
        public const TAG_OK = 'ok';
        public const TAG_NOT_FOUND = 'not_found';
        public const TAG_NOT_CONFIGURED = 'not_configured';
        public const TAG_ERROR = 'error';
    }
}
