<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/homeassistant.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$url = rtrim(trim($data['url'] ?? ''), '/');
$token = trim($data['token'] ?? '');
$enabled = !empty($data['enabled']) ? 1 : 0;
// notify.<service> for reminders, e.g. mobile_app_pixel_7 (empty = no notifications).
$notifyService = preg_replace('/^notify\./', '', strtolower(trim((string) ($data['notify_service'] ?? ''))));
if ($notifyService !== '' && !preg_match('/^[a-z0-9_]+$/', $notifyService)) {
    echo json_encode(['success' => false, 'message' => translate('ha_error_invalid_entity', $i18n)]);
    exit;
}

// An empty token field keeps the stored one (it is never sent back to the browser).
$stmt = $db->prepare("SELECT token FROM homeassistant_settings WHERE user_id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stored = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if ($token === '' && $stored) {
    $token = $stored['token'];
}

if ($enabled) {
    if ($url === '' || $token === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['success' => false, 'message' => translate('ha_error_url_and_token_required', $i18n)]);
        exit;
    }
    $check = paperlessCheckUrl($url, $userId);
    if (is_string($check)) {
        echo json_encode(['success' => false, 'message' => translate('ha_error_' . $check, $i18n)]);
        exit;
    }
}

$stmt = $db->prepare("INSERT OR REPLACE INTO homeassistant_settings (user_id, url, token, enabled) VALUES (:userId, :url, :token, :enabled)");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':url', $url, SQLITE3_TEXT);
$stmt->bindValue(':token', $token, SQLITE3_TEXT);
$stmt->bindValue(':enabled', $enabled, SQLITE3_INTEGER);
$saved = $stmt->execute();
if ($saved) {
    $serviceStmt = $db->prepare('UPDATE homeassistant_settings SET notify_service = :service WHERE user_id = :userId');
    $serviceStmt->bindValue(':service', $notifyService, SQLITE3_TEXT);
    $serviceStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $saved = $serviceStmt->execute();
}
echo json_encode($saved
    ? ['success' => true, 'message' => translate('ha_saved', $i18n)]
    : ['success' => false, 'message' => translate('error', $i18n)]);
$db->close();
