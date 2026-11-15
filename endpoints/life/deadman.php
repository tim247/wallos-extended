<?php
// Save the inactivity guard settings. POST JSON: enabled, days, trusted_name, trusted_email, message, access_code.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/deadman.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$fail = fn($key) => die(json_encode(['success' => false, 'message' => translate($key, $i18n)]));

$enabled = !empty($data['enabled']) ? 1 : 0;
$days = in_array((int) ($data['days'] ?? 90), [30, 60, 90, 180, 365], true) ? (int) $data['days'] : 90;
$name = mb_substr(trim(strip_tags((string) ($data['trusted_name'] ?? ''))), 0, 100);
$email = trim((string) ($data['trusted_email'] ?? ''));
$message = mb_substr(trim(strip_tags((string) ($data['message'] ?? ''))), 0, 3000);
$code = (string) ($data['access_code'] ?? '');
$current = deadmanSettings($db, (int) $userId);

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fail('deadman_invalid_email');
}
if ($code !== '' && strlen($code) < 8) {
    $fail('deadman_code_short');
}
if ($enabled && ($email === '' || ($code === '' && empty($current['access_code_hash'])))) {
    $fail('deadman_incomplete'); // armed needs a recipient and an access code
}

$hash = $code !== '' ? password_hash($code, PASSWORD_DEFAULT) : ($current['access_code_hash'] ?? null);
$stmt = $db->prepare('INSERT INTO deadman_settings (user_id, enabled, days, trusted_name, trusted_email, message, access_code_hash)
    VALUES (:userId, :enabled, :days, :name, :email, :message, :hash)
    ON CONFLICT(user_id) DO UPDATE SET enabled = excluded.enabled, days = excluded.days, trusted_name = excluded.trusted_name,
        trusted_email = excluded.trusted_email, message = excluded.message, access_code_hash = excluded.access_code_hash,
        triggered_at = NULL, access_token = NULL, token_expires = NULL, failed_attempts = 0, last_warning = NULL');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':enabled', $enabled, SQLITE3_INTEGER);
$stmt->bindValue(':days', $days, SQLITE3_INTEGER);
$stmt->bindValue(':name', $name);
$stmt->bindValue(':email', $email);
$stmt->bindValue(':message', $message);
$stmt->bindValue(':hash', $hash, $hash === null ? SQLITE3_NULL : SQLITE3_TEXT);
echo json_encode(['success' => (bool) $stmt->execute(), 'message' => translate('success', $i18n)]);
