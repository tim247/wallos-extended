<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/homeassistant.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$cfg = ['url' => rtrim(trim($data['url'] ?? ''), '/'), 'token' => trim($data['token'] ?? '')];
if ($cfg['token'] === '') {
    $stmt = $db->prepare("SELECT token FROM homeassistant_settings WHERE user_id = :userId");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stored = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $cfg['token'] = $stored['token'] ?? '';
}
if ($cfg['url'] === '' || $cfg['token'] === '' || !filter_var($cfg['url'], FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'message' => translate('ha_error_url_and_token_required', $i18n)]);
    exit;
}

// GET /api/ answers {"message": "API running."} for a valid token.
$result = homeAssistantGet($cfg, $userId, '/api/');
if (isset($result['error'])) {
    die(json_encode(['success' => false, 'message' => translate('ha_error_' . $result['error'], $i18n)]));
}
// With a notify service entered: send a test message to the phone as well.
$service = preg_replace('/^notify\./', '', strtolower(trim((string) ($data['notify_service'] ?? ''))));
if ($service !== '') {
    $error = homeAssistantNotify($cfg, $userId, $service, 'Wallos Extended', translate('ha_notify_test_message', $i18n));
    die(json_encode($error === ''
        ? ['success' => true, 'message' => translate('ha_notify_test_sent', $i18n)]
        : ['success' => false, 'message' => translate('ha_error_' . $error, $i18n)]));
}
echo json_encode(['success' => true, 'message' => translate('ha_connection_ok', $i18n)]);
$db->close();
