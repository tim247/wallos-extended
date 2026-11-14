<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$stmt = $db->prepare("DELETE FROM loan_balance_anchors WHERE id = :id AND user_id = :userId");
$stmt->bindValue(':id', intval($data['id'] ?? 0), SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
echo json_encode($stmt->execute()
    ? ['success' => true, 'message' => translate('loan_anchor_deleted', $i18n)]
    : ['success' => false, 'message' => translate('error', $i18n)]);
$db->close();
