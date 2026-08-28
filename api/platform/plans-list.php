<?php
/**
 * plans-list.php — the pricing catalog. Every plan, active or not
 * (retired plans stay visible here so a tenant still on one is
 * explicable — see tenants-save.php's is_active reasoning).
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

platformRequireAuth($pdo, $CONFIG);

$stmt = $pdo->prepare(
    "SELECT p.*, (SELECT COUNT(*) FROM platform_tenants t WHERE t.plan_id = p.id) AS tenant_count
       FROM platform_plans p
      ORDER BY p.sort_order, p.name"
);
$stmt->execute();

respond([
    'plans' => array_map(function (array $p): array {
        return [
            'id'          => (int) $p['id'],
            'name'        => $p['name'],
            'priceLabel'  => $p['price_label'],
            'maxReps'     => $p['max_reps'] !== null ? (int) $p['max_reps'] : null,
            // \r\n normalised to \n — a textarea on Windows sends \r\n,
            // the seed INSERT's literal \n does not, and both end up in
            // this column, so both are handled the same way here.
            'features'    => $p['features']
                ? array_values(array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $p['features'])))))
                : [],
            'sortOrder'   => (int) $p['sort_order'],
            'isActive'    => (bool) $p['is_active'],
            'tenantCount' => (int) $p['tenant_count'],
        ];
    }, $stmt->fetchAll()),
]);
