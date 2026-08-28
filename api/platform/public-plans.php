<?php
/**
 * public-plans.php — the ONLY unauthenticated endpoint under
 * api/platform/. Feeds the public pricing.html marketing page with
 * live catalog data, so editing a plan on platform/pricing.html
 * actually reaches the page a prospect sees instead of that page
 * carrying its own frozen copy of numbers.
 *
 * Deliberately minimal: name, price, seat count, features, in that
 * order. Nothing about tenants, nothing about who runs this platform,
 * no customer counts — a prospect browsing pricing should not be able
 * to infer anything about the customer base from this page. Retired
 * plans (is_active=0) are excluded — they're kept in the catalog so an
 * existing tenant on one stays explicable, not to keep selling them.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
// No platformRequireAuth() call — the one platform endpoint meant to
// be reached by an anonymous visitor, on purpose.

$stmt = $pdo->prepare(
    "SELECT id, name, price_label, max_reps, features, stripe_price_id
       FROM platform_plans
      WHERE is_active = 1
      ORDER BY sort_order, name"
);
$stmt->execute();

respond([
    'plans' => array_map(function (array $p): array {
        return [
            // Needed by checkout-start.php's request body — nothing else
            // on this public page has previously had to name a plan by
            // id, only by its catalog fields below.
            'id'         => (int) $p['id'],
            'name'       => $p['name'],
            'priceLabel' => $p['price_label'],
            'maxReps'    => $p['max_reps'] !== null ? (int) $p['max_reps'] : null,
            'features'   => $p['features']
                ? array_values(array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $p['features'])))))
                : [],
            // True when self-serve Checkout is available for this plan —
            // this IS meant to be public, unlike everything else this
            // endpoint withholds. false means sales-assisted: pricing.html
            // shows "Talk to us" for this plan instead of a buy button.
            // The actual Price ID never leaves the server.
            'purchasable' => $p['stripe_price_id'] !== null && $p['stripe_price_id'] !== '',
        ];
    }, $stmt->fetchAll()),
]);
