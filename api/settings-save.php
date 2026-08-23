<?php
/**
 * settings-save.php — write tenant policy. Admin only.
 *
 * Every field here changes how much data is exposed, so each change is
 * audited individually with its before and after value. "Who turned
 * masking off" is a question that will be asked.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireMethod('POST');

$ctx  = requireAuth($pdo, $CONFIG, 'admin');
$user = $ctx['user'];
$tid  = $user['tenant_id'];
$old  = $ctx['tenant'];

$in = body();

/* column => [type, min, max] */
$fields = [
    'default_quota'       => ['int', 0, 500],
    'max_assigned'        => ['int', 0, 100000],
    'mask_phone'          => ['bool'],
    'mask_email'          => ['bool'],
    'bake_watermark'      => ['bool'],
    'honeytokens_per_rep' => ['int', 0, 20],
    'burst_alert_limit'   => ['int', 1, 500],
    'device_enforcement'  => ['enum', ['off', 'log', 'enforce']],
];

$map = [
    'defaultQuota'      => 'default_quota',
    'maxAssigned'       => 'max_assigned',
    'maskPhone'         => 'mask_phone',
    'maskEmail'         => 'mask_email',
    'bakeWatermark'     => 'bake_watermark',
    'honeytokensPerRep' => 'honeytokens_per_rep',
    'burstAlertLimit'   => 'burst_alert_limit',
    'deviceEnforcement' => 'device_enforcement',
];

$sets = [];
$vals = [];
$changes = [];

foreach ($map as $key => $col) {
    if (!array_key_exists($key, $in)) continue;

    $spec = $fields[$col];
    $value = $in[$key];

    if ($spec[0] === 'int') {
        $value = (int) $value;
        if ($value < $spec[1] || $value > $spec[2]) {
            respond(['error' => $key . ' must be between ' . $spec[1] . ' and ' . $spec[2] . '.'], 400);
        }
    } elseif ($spec[0] === 'bool') {
        $value = !empty($value) ? 1 : 0;
    } elseif ($spec[0] === 'enum') {
        if (!in_array($value, $spec[1], true)) {
            respond(['error' => 'Invalid value for ' . $key . '.'], 400);
        }
    }

    if ((string) $old[$col] !== (string) $value) {
        $sets[] = "$col = ?";
        $vals[] = $value;
        $changes[] = $col . ': ' . $old[$col] . ' → ' . $value;
    }
}

if (!$sets) {
    respond(['ok' => true, 'changed' => false]);
}

$vals[] = $tid;
$pdo->prepare("UPDATE tenants SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);

audit($pdo, $tid, $user, 'settings', 'tenant policy', implode('; ', $changes), $ctx['device']);

$warning = null;

/* Turning masking off is not a preference, it is a change to the
 * product's central claim. Say so rather than accepting it silently. */
if (array_key_exists('maskPhone', $in) && empty($in['maskPhone']) && $old['mask_phone']) {
    $warning = 'Phone masking is now OFF. Every rep can read every assigned number ' .
               'without spending quota, and the daily limit no longer caps exposure.';
}
if (array_key_exists('deviceEnforcement', $in) && $in['deviceEnforcement'] === 'enforce'
    && $old['device_enforcement'] !== 'enforce') {
    $warning = 'Device enforcement is now ON. Confirm Apache has SSLVerifyClient set and ' .
               'that device_auth_log shows no unexpected denials, or users will be locked out.';
}

respond(['ok' => true, 'changed' => true, 'warning' => $warning]);
