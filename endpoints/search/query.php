<?php
// Live search for the dashboard box. GET q; needs a login (read-only accounts may use it).
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/global_search.php';

header('Content-Type: application/json');
header('Cache-Control: private, no-store');
if (empty($userId)) {
    http_response_code(401);
    die(json_encode(['success' => false]));
}
$hits = globalSearch($db, (int) $userId, (string) ($_GET['q'] ?? ''), 6);
$labels = [];
foreach (array_unique(array_column($hits, 'area')) as $area) {
    $labels[$area] = translate($area, $i18n);
}
echo json_encode(['success' => true, 'hits' => $hits, 'labels' => $labels], JSON_UNESCAPED_UNICODE);
