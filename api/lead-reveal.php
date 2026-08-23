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

/* ── Rule 1: quota, counted server-side ──
 *
 * For an admin, daily_quota = 0 means UNLIMITED. For a rep it means
 * blocked. The difference is deliberate: an admin is the tenant's
 * trusted party and capping them stops legitimate bulk work, while a rep
 * with no quota should not be unmasking anything.
 *
 * What is NOT different any more: the ledger row. Admin reveals used to
 * skip lead_reveals entirely, which meant the one account with
 * unrestricted access to every lead was the one account the ledger did
 * not measure — and lead_reveals is the first table anyone reads when
 * working out where a list went. A compromised admin left no trace
 * there. Now everyone is recorded; only the cap differs.
 */
$limit    = (int) $user['quota'];
$isAdmin  = $user['role'] === 'admin';
$metered  = !($isAdmin && $limit === 0);   // admins on 0 are uncapped

/* Already revealed this exact field on this exact lead? Do not charge
 * again. Re-reading something you already paid for is not new exposure,
 * and charging for it would push reps to screenshot the value the first
 * time "just in case" — the precise behaviour this product exists to
 * discourage. It is also what makes the one-at-a-time display rule in
 * reveal.js humane: a value that re-masks itself can always be brought
 * back for free. */
$seen = $pdo->prepare(
    "SELECT 1 FROM lead_reveals
     WHERE tenant_id = ? AND user_id = ? AND lead_id = ? AND field = ?
     LIMIT 1"
);
$seen->execute([$user['tenant_id'], $user['id'], $lead['id'], $field]);
$alreadyPaid = (bool) $seen->fetchColumn();

/* ── Burst limit ──
 *
 * The daily quota caps the total; it does nothing about 40 reveals in
 * 40 seconds, which is what a script does and what someone emptying
 * their book before resigning does. Two seconds between reveals is
 * invisible to a person reading a number off the screen and dialling
 * it, and turns a scripted sweep into a slow, noisy one.
 *
 * Applies to EVERY reveal, including ones already paid for. Putting
 * this inside the "not already paid" branch left a hole straight
 * through the one-at-a-time rule: a rep who had legitimately revealed
 * forty numbers across a day could script a loop at 5pm and pull all
 * forty back as images in a couple of seconds. They had seen each one
 * before, but seeing is not capturing in bulk, and bulk capture is the
 * thing being prevented.
 *
 * Rate is tracked in its own table rather than inferred from
 * lead_reveals, because lead_reveals only gains a row on a FIRST
 * reveal — a re-reveal would leave no trace to rate-limit against.
 */
$recent = $pdo->prepare(
    "SELECT COUNT(*) FROM security_events
     WHERE tenant_id = ? AND user_id = ? AND type = 'reveal_attempt'
       AND at > DATE_SUB(NOW(), INTERVAL 2 SECOND)"
);
$recent->execute([$user['tenant_id'], $user['id']]);

if ((int) $recent->fetchColumn() > 0) {
    audit($pdo, $user['tenant_id'], $user, 'blocked', $ref,
        'Reveal refused — faster than one every 2 seconds', $device);
    respond([
        'error' => 'Slow down — one reveal at a time. Try again in a moment.',
        'quota' => quotaPayload($pdo, $user, $limit),
    ], 429);
}

/* Every ATTEMPT leaves a timestamp here — including ones the quota gate
 * below is about to refuse. That is deliberate: what needs rate-limiting
 * is the request rate, and a script hammering a spent quota is exactly
 * the pattern worth slowing down.
 *
 * Named 'reveal_attempt' rather than 'contact_revealed' for that reason.
 * A row that says something was revealed when the request was refused
 * would put a lie in the one place an investigator has to trust.
 *
 * This is also the only trace a RE-reveal leaves: lead_reveals gains a
 * row on the first reveal only, so without this a rep pulling forty
 * already-paid values back would be invisible. */
$pdo->prepare(
    "INSERT INTO security_events (tenant_id, user_id, type, detail, page, ip)
     VALUES (?,?,'reveal_attempt',?,'api/lead-reveal.php',?)"
)->execute([
    $user['tenant_id'], $user['id'], $ref . '/' . $field, clientIp(),
]);

if (!$alreadyPaid) {

    if ($metered) {
        $used = revealsToday($pdo, $user['tenant_id'], $user['id']);

        if ($limit <= 0 || $used >= $limit) {
            audit($pdo, $user['tenant_id'], $user, 'blocked', $ref,
                "Reveal refused — daily quota spent ($used/$limit)", $device);
            respond([
                'error'  => 'Daily reveal quota spent.',
                'quota'  => ['limit' => $limit, 'used' => $used, 'left' => 0],
            ], 429);
        }
    }

    /* ── Rule 3: write the ledger row before rendering ──
     * Unconditional now. Even an uncapped admin lands here. */
    $pdo->prepare(
        "INSERT IGNORE INTO lead_reveals
         (tenant_id, user_id, lead_id, field, device_id, ip, reveal_date)
         VALUES (?,?,?,?,?,?, CURDATE())"
    )->execute([
        $user['tenant_id'], $user['id'], $lead['id'], $field,
        $device['id'] ?? null, clientIp(),
    ]);
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

renderWatermarkedValue($value, $user, $CONFIG, quotaPayload($pdo, $user, $limit));


/* ══ Helpers ═══════════════════════════════════════════════════════ */

/**
 * The real count for everyone, admins included — it comes from the same
 * ledger the dashboard and any investigation read, so the three can no
 * longer disagree about how many reveals happened.
 *
 * An uncapped admin (daily_quota = 0) reports limit 0 / left -1, which
 * the front end reads as "no cap" rather than "spent".
 */
function quotaPayload(PDO $pdo, array $user, int $limit): array
{
    $used     = revealsToday($pdo, $user['tenant_id'], $user['id']);
    $uncapped = $user['role'] === 'admin' && $limit === 0;

    return [
        'limit' => $limit,
        'used'  => $used,
        'left'  => $uncapped ? -1 : max(0, $limit - $used),
    ];
}

/**
 * Draws the value with the viewer's identity tiled across it and sends
 * it as a PNG. The identity in the image is the SESSION's identity, not
 * anything the client asked for — a rep cannot request a reveal
 * watermarked with somebody else's name.
 */
function renderWatermarkedValue(string $value, array $user, array $config, array $quota): void
{
    $pad = 6;

    /* GD's built-in bitmap fonts are used rather than TrueType so this
     * works on a stock cPanel PHP with no font file on disk. If FreeType
     * is available, imagettftext with the real UI font would look
     * considerably better — worth doing, not worth blocking on.
     *
     * Those fonts come in five fixed sizes, so config.watermark.font_size
     * is snapped to the nearest one. It used to be read and then ignored
     * while font 3 was hard-coded, which made it a setting that silently
     * did nothing — worse than no setting at all, because someone would
     * eventually change it and conclude the watermark was broken. */
    $requested = (int) ($config['watermark']['font_size'] ?? 13);

    if ($requested <= 8)       $font = 1;   // 5x8
    elseif ($requested <= 11)  $font = 2;   // 6x11
    elseif ($requested <= 13)  $font = 3;   // 7x13
    elseif ($requested <= 15)  $font = 5;   // 9x15
    else                       $font = 4;   // 8x16, the largest

    $charW = imagefontwidth($font);
    $charH = imagefontheight($font);

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
    header('X-Datafort-Quota: ' . json_encode($quota));

    imagepng($img);
    imagedestroy($img);
    exit;
}
