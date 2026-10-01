<?php
/**
 * Income guidance on the membership step.
 *
 * Switches on the parent plugin's income guidance table, which shows income
 * bands against membership tiers. The bands themselves are entered on each
 * membership plan in the Join settings. Needs Common Knowledge Join Flow
 * 1.4.44 or newer; on an older parent the filter is simply never read.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Register income guidance hooks.
 *
 * @since 1.5.13
 *
 * @return void
 */
function register_income_guidance() {
    add_filter('ck_join_flow_income_guidance_enabled', '__return_true');
}
