<?php
/**
 * lead-reveal.php — the one endpoint that matters.
 *
 * This is the only place in Datafort where a full contact detail leaves
 * the database. Everything else in the product is arranged so that this
 * file is the sole door, and this file is arranged so the door is
 * narrow: ownership checked, quota checked, ledger written, and the
 * value returned as pixels rather than text.
 *
 * ─────────────────────────────────────────────────────────────────
 * FOUR RULES. BREAKING ANY ONE OF THEM BREAKS THE PRODUCT.
 *
 * 1. The quota is re-counted here, server-side, on every call. The
 *    browser's copy of "reveals left" is a convenience for greying out
 *    a button. A client that lies about it gets refused anyway.
 *
 * 2. Ownership is checked before existence. A rep asking about a lead
 *    that is not theirs gets the same answer whether or not it exists,
 *    or the error message becomes a way to enumerate the lead table.
 *
 * 3. The ledger row is written BEFORE the value is rendered. If the
 *    render fails, the quota is still spent. Spending a reveal on a
 *    failed render is a minor annoyance; handing out free unlogged
 *    reveals when rendering is flaky is a hole.
 *
 * 4. The response is an image with the watermark burned into the same
 *    pixels — never JSON containing the string. There is no text node
 *    to select, no DOM property to read, and cropping the mark out of a
 *    screenshot crops the number with it.
 * ─────────────────────────────────────────────────────────────────
 *
 * Returns image/png on success, application/json on refusal.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireMethod('POST');

$ctx    = requireAuth($pdo, $CONFIG);
$user   = $ctx['user'];
$device = $ctx['device'];
$tenant = $ctx['tenant'];

$in     = body();
$ref    = trim((string) ($in['lead'] ?? ''));
$field  = (string) ($in['field'] ?? '');

if (!in_array($field, ['phone', 'alt_phone', 'email'], true)) {
    respond(['error' => 'Unknown field'], 400);
}

/* ── Rule 2: ownership before existence ──
 * The WHERE clause carries tenant_id AND owner_id, so a lead belonging
 * to someone else simply does not exist as far as this query is
 * concerned. Admins are exempt from the owner check but not the tenant
 * check — there is no query path here that can cross a tenant. */
$sql = "SELECT * FROM leads WHERE tenant_id = ? AND ref = ?";
$params = [$user['tenant_id'], $ref];

if ($user['role'] !== 'admin') {
    $sql .= " AND owner_id = ?";
    $params[] = $user['id'];
}
$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lead = $stmt->fetch();

if (!$lead) {
    // Deliberately identical whether the lead is missing or unowned.
    audit($pdo, $user['tenant_id'], $user, 'blocked', $ref, 'Reveal denied — not owned or not found', $device);
    respond(['error' => 'Lead not available'], 404);
}

$value = $lead[$field] ?? '';
if ($value === '' || $value === null) {
    respond(['error' => 'No value on record for that field'], 404);
}

/* ── Rule 1: quota, counted server-side ── */
$limit = (int) $user['quota'];

if ($user['role'] !== 'admin') {
    /* Already revealed this exact field on this exact lead? Do not charge
     * again. Re-reading something you already paid for is not new
     * exposure, and charging for it would push reps to screenshot the
     * value the first time "just in case" — the precise behaviour this
     * product exists to discourage. */
    $seen = $pdo->prepare(
        "SELECT 1 FROM lead_reveals
         WHERE tenant_id = ? AND user_id = ? AND lead_id = ? AND field = ?
         LIMIT 1"
    );
    $seen->execute([$user['tenant_id'], $user['id'], $lead['id'], $field]);
    $alreadyPaid = (bool) $seen->fetchColumn();

    if (!$alreadyPaid) {
        $used = revealsToday($pdo, $user['tenant_id'], $user['id']);

        if ($limit <= 0 || $used >= $limit) {
            audit($pdo, $user['tenant_id'], $user, 'blocked', $ref,
                "Reveal refused — daily quota spent ($used/$limit)", $device);
            respond([
                'error'  => 'Daily reveal quota spent.',
                'quota'  => ['limit' => $limit, 'used' => $used, 'left' => 0],
            ], 429);
        }

        /* ── Rule 3: write the ledger row before rendering ── */
        $pdo->prepare(
            "INSERT INTO lead_reveals
             (tenant_id, user_id, lead_id, field, device_id, ip, reveal_date)
             VALUES (?,?,?,?,?,?, CURDATE())"
        )->execute([
            $user['tenant_id'], $user['id'], $lead['id'], $field,
            $device['id'] ?? null, clientIp(),
        ]);
    }
}

/* The audit row names the field and the lead but NEVER the value. An
 * audit log that records what was revealed is a second copy of the lead
 * list, sitting in the table nobody is allowed to delete. */
audit($pdo, $user['tenant_id'], $user, 'reveal', $ref, "Revealed $field", $device);

/* Honeytoken touched. The rep must see nothing unusual — the decoy only
 * works while it looks exactly like a real lead — but the admin should
 * know immediately, because a decoy being worked is a strong signal
 * about which records are moving. */
if (!empty($lead['honeytoken'])) {
    try {
        $pdo->prepare(
            "INSERT INTO security_events (tenant_id, user_id, type, detail, page, ip)
             VALUES (?,?,?,?,?,?)"
        )->execute([
            $user['tenant_id'], $user['id'], 'honeytoken_revealed',
            "Decoy lead $ref, field $field", 'api/lead-reveal.php', clientIp(),
        ]);
    } catch (Throwable $e) {
        error_log('[datafort] honeytoken event failed: ' . $e->getMessage());
    }
}

/* ── Rule 4: render to pixels ── */
if (empty($CONFIG['watermark']['enabled']) || !function_exists('imagecreatetruecolor')) {
    /* GD missing, or the tenant turned baking off for accessibility.
     * Fall back to JSON text and say so in the payload, so the front end
     * can render it as selectable text rather than silently showing a
     * broken image. This is a real weakening — the value becomes
     * copyable — which is why it is surfaced rather than hidden. */
    header('Content-Type: application/json; charset=utf-8');
    respond([
        'ok'         => true,
        'value'      => $value,
        'watermarked' => false,
        'quota'      => quotaPayload($pdo, $user, $limit),
    ]);
}

renderWatermarkedValue($value, $user, $CONFIG);


/* ══ Helpers ═══════════════════════════════════════════════════════ */

function quotaPayload(PDO $pdo, array $user, int $limit): array
{
    $used = $user['role'] === 'admin' ? 0 : revealsToday($pdo, $user['tenant_id'], $user['id']);
    return ['limit' => $limit, 'used' => $used, 'left' => max(0, $limit - $used)];
}

/**
 * Draws the value with the viewer's identity tiled across it and sends
 * it as a PNG. The identity in the image is the SESSION's identity, not
 * anything the client asked for — a rep cannot request a reveal
 * watermarked with somebody else's name.
 */
function renderWatermarkedValue(string $value, array $user, array $config): void
{
    $fontSize = (int) ($config['watermark']['font_size'] ?? 13);
    $pad      = 6;

    /* GD's built-in bitmap fonts are used rather than TrueType so this
     * works on a stock cPanel PHP without a font file on disk. Font 3 is
     * ~7x13px. If FreeType is available, imagettftext with the real UI
     * font would look considerably better — worth doing, not worth
     * blocking on. */
    $font   = 3;
    $charW  = imagefontwidth($font);
    $charH  = imagefontheight($font);

    $width  = $charW * strlen($value) + $pad * 2;
    $height = $charH + $pad * 2;

    $img = imagecreatetruecolor($width, $height);

    $bg   = imagecolorallocate($img, 244, 246, 248);   // --bg
    $ink  = imagecolorallocate($img, 20, 33, 61);      // --navy
    imagefilledrectangle($img, 0, 0, $width, $height, $bg);

    imagestring($img, $font, $pad, $pad, $value, $ink);

    // The mark. Alpha so it sits over the value without hiding it.
    $alpha = (int) round(127 * (1 - (float) ($config['watermark']['opacity'] ?? 0.34)));
    $mark  = imagecolorallocatealpha($img, 30, 107, 241, $alpha);

    $tag   = $user['name'] . ' ' . $user['id'] . ' ' . date('Y-m-d H:i');
    $tagW  = imagefontwidth(1) * strlen($tag);

    for ($y = 0; $y < $height; $y += 11) {
        for ($x = -10; $x < $width; $x += $tagW + 14) {
            imagestring($img, 1, $x, $y, $tag, $mark);
        }
    }

    /* no-store matters here specifically: a cached reveal is a copy of
     * the value sitting in the browser's disk cache, outliving the
     * session and readable without a certificate. */
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('Content-Disposition: inline');

    imagepng($img);
    imagedestroy($img);
    exit;
}
