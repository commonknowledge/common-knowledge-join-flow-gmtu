<?php
/**
 * Route all WordPress email through Mailpit SMTP.
 *
 * Must-use plugin — loaded automatically, no activation needed.
 * Mailpit web UI: http://localhost:8025
 */
add_action('phpmailer_init', function ($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host       = 'mailpit';
    $phpmailer->Port       = 1025;
    $phpmailer->SMTPAuth   = false;
    $phpmailer->SMTPAutoTLS = false;
});
