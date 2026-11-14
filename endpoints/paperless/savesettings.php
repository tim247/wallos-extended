<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/paperless.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$url = rtrim(trim($data['url'] ?? ''), '/');
$token = trim($data['token'] ?? '');
$enabled = !empty($data['enabled']) ? 1 : 0;

// Keep the stored token when the field was left empty (it is never sent back to the browser).
$stmt = $db->prepare("SELECT token FROM paperless_settings WHERE user_id = :userId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stored = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if ($token === '' && $stored) {
    $token = $stored['token'];
}

if ($enabled) {
    if ($url === '' || $token === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        paperlessError('paperless_url_and_token_required', $i18n);
    }
    $check = paperlessCheckUrl($url, $userId);
    if (is_string($check)) {
        paperlessError('paperless_' . $check, $i18n);
    }
}

$stmt = $db->prepare("INSERT OR REPLACE INTO paperless_settings (user_id, url, token, enabled) VALUES (:userId, :url, :token, :enabled)");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':url', $url, SQLITE3_TEXT);
$stmt->bindValue(':token', $token, SQLITE3_TEXT);
$stmt->bindValue(':enabled', $enabled, SQLITE3_INTEGER);
echo json_encode($stmt->execute()
    ? ['success' => true, 'message' => translate('paperless_saved', $i18n)]
    : ['success' => false, 'message' => translate('error', $i18n)]);
$db->close();
