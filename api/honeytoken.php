<?php
/**
 * honeytoken.php — seeded decoy leads. Requirements section 7.3.
 *
 * A honeytoken is a fake lead mixed into a rep's assigned set. It looks
 * exactly like a real record and is never marked as anything else in the
 * rep's UI. If a lead list later turns up at a competitor, the decoys in
 * it name whose copy leaked.
 *
 * ─────────────────────────────────────────────────────────────────
 * WHY THIS FILE HAD TO EXIST
 *
 * The column, the admin-only marker in leads-list.php, the alert in
 * lead-reveal.php and the honeytokens_per_rep setting were all built
 * first. Nothing ever WROTE a decoy row, so attribution was a feature
 * the product described but did not have. Until this runs, a leaked
 * list is untraceable.
 * ─────────────────────────────────────────────────────────────────
 *
 * THREE RULES
 *
 * 1. A decoy must be indistinguishable from a real lead TO A REP. If a
 *    rep can spot one they simply avoid it, and the trick stops working.
 *    leads-list.php returns honeytoken:false for non-admins; anything
 *    new that reads leads must do the same.
 *
 * 2. The contact details must be unreachable. A decoy that dials a real
 *    stranger turns an internal security measure into harassment. Phones
 *    are +91 90000xxxxx and emails are @example.com, a reserved domain
 *    that cannot receive mail.
 *
 * 3. One decoy belongs to exactly one rep. Sharing them destroys
 *    attribution — the entire point is that the record identifies a
 *    single person.
 */

/**
 * Ensures a rep holds the configured number of decoys.
 *
 * Called after assignment, because a decoy is only useful once it sits
 * inside a book the rep actually works. Idempotent: counts what is
 * already there and tops up the difference.
 */
function seedHoneytokens(PDO $pdo, int $tenantId, int $userId, int $want, ?array $actor = null): int
{
    if ($want <= 0) return 0;

    $have = $pdo->prepare(
        "SELECT COUNT(*) FROM leads
         WHERE tenant_id = ? AND owner_id = ? AND honeytoken = 1"
    );
    $have->execute([$tenantId, $userId]);
    $need = $want - (int) $have->fetchColumn();

    if ($need <= 0) return 0;

    /* Decoys blend in only if they look like the surrounding data, so
     * they borrow the tenant's own most common city, industry and
     * source. A decoy from "Springfield" in a book of Mumbai leads is
     * not a decoy, it is a signpost. */
    $shape = $pdo->prepare(
        "SELECT
           (SELECT city     FROM leads WHERE tenant_id = ? AND city     IS NOT NULL
              GROUP BY city     ORDER BY COUNT(*) DESC LIMIT 1) AS city,
           (SELECT state    FROM leads WHERE tenant_id = ? AND state    IS NOT NULL
              GROUP BY state    ORDER BY COUNT(*) DESC LIMIT 1) AS state,
           (SELECT industry FROM leads WHERE tenant_id = ? AND industry IS NOT NULL
              GROUP BY industry ORDER BY COUNT(*) DESC LIMIT 1) AS industry,
           (SELECT source_id FROM leads WHERE tenant_id = ? AND source_id IS NOT NULL
              GROUP BY source_id ORDER BY COUNT(*) DESC LIMIT 1) AS source_id"
    );
    $shape->execute([$tenantId, $tenantId, $tenantId, $tenantId]);
    $shape = $shape->fetch() ?: [];

    $first = ['Rohit','Anita','Sameer','Kavita','Nitin','Shalini','Arun','Bhavna',
              'Devendra','Ishita','Girish','Lalita','Mohit','Nandini','Prakash','Rekha'];
    $last  = ['Agarwal','Bhatt','Chauhan','Deshmukh','Ghosh','Hegde','Jain','Kapoor',
              'Mishra','Nayar','Purohit','Ranganathan','Sinha','Thakur','Vaidya'];
    $co    = ['Sterling Components','Anchor Traders','Peakline Systems','Westford Supply',
              'Crestway Industries','Northbay Logistics','Ridgeform Metals','Clearpoint Retail'];
    $title = ['Procurement Head','Operations Manager','Purchase Executive','Director',
              'Plant Manager','Founder'];

    $maxRef = $pdo->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(ref, 3) AS UNSIGNED)), 4199)
         FROM leads WHERE tenant_id = ?"
    );
    $maxRef->execute([$tenantId]);
    $nextRef = (int) $maxRef->fetchColumn() + 1;

    $ins = $pdo->prepare(
        "INSERT INTO leads
         (tenant_id, ref, name, company, designation, phone, email, city, state,
          industry, company_size, source_id, source_cost, acquired_date,
          status, owner_id, honeytoken, dedup_key)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0, DATE_SUB(CURDATE(), INTERVAL ? DAY),
                 'new', ?, 1, ?)"
    );

    $made = 0;

    for ($i = 0; $i < $need; $i++) {
        $fn = $first[random_int(0, count($first) - 1)];
        $ln = $last[random_int(0, count($last) - 1)];

        /* The phone is the attribution key. 90000 + a value derived from
         * the user id keeps every decoy in one obviously-reserved block
         * while still being unique, so a list can be matched back to a
         * rep by inspection. */
        $serial = str_pad((string) (($userId * 100) + $i + random_int(0, 40)), 5, '0', STR_PAD_LEFT);
        $digits = '90000' . substr($serial, -5);
        $email  = strtolower($fn . '.' . $ln) . substr($digits, -4) . '@example.com';

        $ref = 'L-' . $nextRef;

        try {
            $ins->execute([
                $tenantId, $ref, $fn . ' ' . $ln,
                $co[random_int(0, count($co) - 1)],
                $title[random_int(0, count($title) - 1)],
                '+91 ' . $digits, $email,
                $shape['city'] ?? null, $shape['state'] ?? null,
                $shape['industry'] ?? null, null,
                $shape['source_id'] ?? null,
                random_int(3, 60),
                $userId,
                $digits,
            ]);
            $nextRef++;
            $made++;
        } catch (Throwable $e) {
            // A dedup_key collision just means this one is skipped.
            $nextRef++;
        }
    }

    if ($made > 0 && $actor !== null) {
        /* Audited so the decoys are accounted for, but WITHOUT their
         * refs. An audit row naming every decoy would hand the answer to
         * anyone who can read the audit log — including the rep the
         * decoys are meant to identify, once a support case gives them a
         * reason to look. The admin view queries the leads table for
         * that instead. */
        audit($pdo, $tenantId, $actor, 'device',
            'honeytokens', $made . ' decoy records seeded for user ' . $userId);
    }

    return $made;
}
