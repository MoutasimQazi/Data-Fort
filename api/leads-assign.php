<?php
/**
 * leads-assign.php — assign or recall leads. Admin only.
 *
 * Assignment is the primary containment mechanism (requirements
 * section 4), so it is an administrator action and it is always logged
 * with both the count and the target.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/honeytoken.php';

requireMethod('POST');

$ctx  = requireAuth($pdo, $CONFIG, 'admin');
$user = $ctx['user'];
$tid  = $user['tenant_id'];

$in    = body();
$refs  = $in['leads'] ?? [];
$toId  = !empty($in['userId']) ? (int) $in['userId'] : null;   // null = recall to pool

if (!is_array($refs) || !$refs) {
    respond(['error' => 'No leads selected'], 400);
}

// A sane ceiling. Assigning 50,000 leads in one request is either a
// mistake or an attempt to time out the database mid-write.
if (count($refs) > 5000) {
    respond(['error' => 'Assign at most 5,000 leads per request.'], 400);
}

$target = null;
if ($toId !== null) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
    $stmt->execute([$toId, $tid]);
    $target = $stmt->fetch();

    if (!$target) {
        respond(['error' => 'User not found'], 404);
    }
    if ($target['status'] === 'suspended') {
        respond(['error' => 'That user is suspended. Restore the account before assigning leads.'], 409);
    }
}

$placeholders = implode(',', array_fill(0, count($refs), '?'));

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "UPDATE leads SET owner_id = ?
         WHERE tenant_id = ? AND ref IN ($placeholders)"
    );
    $stmt->execute(array_merge([$toId, $tid], array_values($refs)));
    $changed = $stmt->rowCount();

    /* Recall kills nothing else: the reveals a rep already spent stay in
     * the ledger and stay in the audit log. Taking the leads back does
     * not un-see the numbers, and pretending otherwise would make the
     * investigation trail lie. */

    audit($pdo, $tid, $user, 'assign',
        $target ? $target['name'] : 'unassigned pool',
        $changed . ' leads ' . ($target ? 'assigned to ' . $target['name'] : 'recalled to the pool'),
        $ctx['device']);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[datafort] assign failed: ' . $e->getMessage());
    respond(['error' => 'Assignment failed'], 500);
}

/* Top the rep up to the tenant's configured number of decoys.
 *
 * Done on assignment rather than on import, because a decoy is only
 * useful once it sits inside a book somebody actually works. A decoy in
 * the unassigned pool identifies nobody.
 *
 * Outside the transaction on purpose: a failure to seed must not roll
 * back an assignment the admin has already been told succeeded. Losing
 * attribution on one batch is recoverable; losing the assignment is
 * confusing. */
$seeded = 0;

if ($target !== null) {
    try {
        $seeded = seedHoneytokens(
            $pdo, $tid, (int) $target['id'],
            (int) ($ctx['tenant']['honeytokens_per_rep'] ?? 0),
            $user
        );
    } catch (Throwable $e) {
        error_log('[datafort] honeytoken seeding failed: ' . $e->getMessage());
    }
}

$response = ['ok' => true, 'changed' => $changed];

if ($seeded > 0) {
    // Reported to the admin so they can see attribution is actually
    // running. Never reported to a rep — leads-list.php returns
    // honeytoken:false for non-admins, and that must stay true.
    $response['seeded'] = $seeded;
}

/* Flag a batch the target cannot realistically work through. Not a
 * refusal — the admin may know something we do not — but a quota of 25
 * against 800 leads means most of that book is never touched. */
if ($target && (int) $target['daily_quota'] > 0) {
    $days = (int) ceil($changed / (int) $target['daily_quota']);
    if ($days > 5) {
        $response['warning'] =
            $target['name'] . ' has a ' . $target['daily_quota'] . '/day reveal quota. ' .
            'At that rate these ' . $changed . ' leads would take about ' . $days .
            ' working days to work through.';
    }
}

respond($response);
