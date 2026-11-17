<?php
// Task inbox: mark a task done / later (7 days), switch a reminder type on or off.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/task_inbox.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
if (($data['action'] ?? '') === 'pref') {
    $kind = (string) ($data['kind'] ?? '');
    if (!isset(NOTIFICATION_KINDS[$kind])) {
        die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
    }
    $stmt = $db->prepare('INSERT OR REPLACE INTO notification_prefs (user_id, kind, enabled) VALUES (:userId, :kind, :enabled)');
    $stmt->bindValue(':kind', $kind);
    $stmt->bindValue(':enabled', !empty($data['enabled']) ? 1 : 0, SQLITE3_INTEGER);
} else {
    $key = mb_substr((string) ($data['key'] ?? ''), 0, 200);
    $status = ($data['status'] ?? '') === 'later' ? 'later' : 'done';
    if (!preg_match('/^[a-z_]+:[A-Za-z0-9_:.\-]+$/', $key)) {
        die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
    }
    $stmt = $db->prepare('INSERT OR REPLACE INTO task_state (user_id, task_key, status, until) VALUES (:userId, :key, :status, :until)');
    $stmt->bindValue(':key', $key);
    $stmt->bindValue(':status', $status);
    $stmt->bindValue(':until', $status === 'later' ? date('Y-m-d', strtotime('+7 days')) : null, $status === 'later' ? SQLITE3_TEXT : SQLITE3_NULL);
}
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$ok = $stmt->execute() !== false;
echo json_encode(['success' => $ok, 'message' => translate($ok ? 'consumption_settings_saved' : 'error', $i18n)]);
$db->close();
