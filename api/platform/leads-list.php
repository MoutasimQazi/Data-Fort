<?php
/**
 * leads-list.php — inbound sales inquiries from pricing.html.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

platformRequireAuth($pdo, $CONFIG);

$status = (string) ($_GET['status'] ?? '');
$where  = ['1=1'];
$params = [];
if ($status !== '') {
    $where[] = 'status = ?';
    $params[] = $status;
}

$stmt = $pdo->prepare(
    "SELECT * FROM platform_leads WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC"
);
$stmt->execute($params);

respond([
    'leads' => array_map(function (array $l): array {
        return [
            'id'           => (int) $l['id'],
            'name'         => $l['name'],
            'email'        => $l['email'],
            'company'      => $l['company'],
            'planInterest' => $l['plan_interest'],
            'message'      => $l['message'],
            'status'       => $l['status'],
            'createdAt'    => $l['created_at'],
        ];
    }, $stmt->fetchAll()),
]);
