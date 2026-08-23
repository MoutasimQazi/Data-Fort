<?php
/**
 * settings-get.php — tenant policy. Admin only.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$ctx = requireAuth($pdo, $CONFIG, 'admin');
$t   = $ctx['tenant'];

respond([
    'name'              => $t['name'],
    'defaultQuota'      => (int) $t['default_quota'],
    'maxAssigned'       => (int) $t['max_assigned'],
    'maskPhone'         => (bool) $t['mask_phone'],
    'maskEmail'         => (bool) $t['mask_email'],
    'bakeWatermark'     => (bool) $t['bake_watermark'],
    'honeytokensPerRep' => (int) $t['honeytokens_per_rep'],
    'burstAlertLimit'   => (int) $t['burst_alert_limit'],
    'deviceEnforcement' => $t['device_enforcement'],
]);
