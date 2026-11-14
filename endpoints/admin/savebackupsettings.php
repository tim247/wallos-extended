<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';

$data = json_decode(file_get_contents("php://input"), true);
$keep = max(0, min(365, (int) ($data['keep'] ?? 14)));

$stmt = $db->prepare('UPDATE admin SET backup_keep = :keep');
$stmt->bindValue(':keep', $keep, SQLITE3_INTEGER);
die(json_encode([
    "success" => (bool) $stmt->execute(),
    "message" => translate('success', $i18n)
]));
