<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/paperless.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$cfg = ['url' => rtrim(trim($data['url'] ?? ''), '/'), 'token' => trim($data['token'] ?? '')];

if ($cfg['token'] === '') {
    $stmt = $db->prepare("SELECT token FROM paperless_settings WHERE user_id = :userId");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stored = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $cfg['token'] = $stored['token'] ?? '';
}
if ($cfg['url'] === '' || $cfg['token'] === '' || !filter_var($cfg['url'], FILTER_VALIDATE_URL)) {
    paperlessError('paperless_url_and_token_required', $i18n);
}

$error = null;
$result = paperlessJson($cfg, $userId, '/api/documents/', ['page_size' => 1, 'fields' => 'id'], $error);
if ($result === null) {
    paperlessError($error === 'paperless_auth_failed' || $error === 'paperless_unexpected_response' ? $error : 'paperless_' . $error, $i18n);
}
echo json_encode(['success' => true, 'message' => sprintf(translate('paperless_connection_ok', $i18n), (int) ($result['count'] ?? 0))]);
$db->close();
