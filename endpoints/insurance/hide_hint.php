<?php
// Hides one insurance tip (POST JSON: key = 8-char hash), or shows all again (key = "").
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$key = (string) ($data['key'] ?? '');
if ($key !== '' && !preg_match('/^[0-9a-f]{8}$/', $key)) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}
$stmt = $db->prepare('SELECT insurance_hidden_hints FROM settings WHERE user_id = :userId');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$hidden = array_filter(explode(',', (string) ($stmt->execute()->fetchArray(SQLITE3_NUM)[0] ?? '')));
// ponytail: the list only grows; capped at the newest 200 keys, tips whose text changed come back anyway.
$hidden = $key === '' ? [] : array_slice(array_values(array_unique([...$hidden, $key])), -200);
$stmt = $db->prepare('UPDATE settings SET insurance_hidden_hints = :hidden WHERE user_id = :userId');
$stmt->bindValue(':hidden', implode(',', $hidden));
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
die(json_encode(['success' => (bool) $stmt->execute(), 'message' => translate('success', $i18n)]));
