<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

header('Content-Type: application/json');
// A new token on every "create", so an old link stops working.
$token = ($_POST['action'] ?? '') === 'create' ? bin2hex(random_bytes(24)) : null;
$stmt = $db->prepare('UPDATE user SET share_token = :token WHERE id = :userId');
$stmt->bindValue(':token', $token, $token === null ? SQLITE3_NULL : SQLITE3_TEXT);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
echo json_encode(['success' => (bool) $stmt->execute(), 'message' => translate('success', $i18n)]);
