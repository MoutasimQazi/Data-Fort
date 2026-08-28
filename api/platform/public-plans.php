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
    "SELECT name, price_label, max_reps, features, stripe_payment_link
       FROM platform_plans
      WHERE is_active = 1
      ORDER BY sort_order, name"
);
$stmt->execute();

respond([
    'plans' => array_map(function (array $p): array {
        return [
            'name'       => $p['name'],
            'priceLabel' => $p['price_label'],
            'maxReps'    => $p['max_reps'] !== null ? (int) $p['max_reps'] : null,
            'features'   => $p['features']
                ? array_values(array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $p['features'])))))
                : [],
            // A real, clickable URL when set — this IS meant to be
            // public, unlike everything else this endpoint withholds.
            // NULL means sales-assisted: pricing.html shows "Talk to
            // us" for this plan instead of a buy button.
            'stripeLink' => $p['stripe_payment_link'],
        ];
    }, $stmt->fetchAll()),
]);
