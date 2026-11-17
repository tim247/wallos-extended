<?php
// Emergency dossier: entries (save / delete), "dossier reviewed today", medical data opt-in.
// POST JSON: action (save|delete|review|medical), id, section, name, info, detail, enabled.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/emergency_entries.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string) ($data['action'] ?? 'save');
$id = intval($data['id'] ?? 0);
$clean = fn(string $key, int $max) => mb_substr(trim(strip_tags((string) ($data[$key] ?? ''))), 0, $max);
$reply = fn(bool $ok) => die(json_encode(['success' => $ok, 'message' => translate($ok ? 'consumption_settings_saved' : 'error', $i18n)]));

if ($action === 'review' || $action === 'medical') {
    $db->exec('INSERT OR IGNORE INTO emergency_notes (user_id, notes) VALUES (' . (int) $userId . ", '')");
    $stmt = $action === 'review'
        ? $db->prepare('UPDATE emergency_notes SET reviewed_at = :value WHERE user_id = :userId')
        : $db->prepare('UPDATE emergency_notes SET medical_in_dossier = :value WHERE user_id = :userId');
    $stmt->bindValue(':value', $action === 'review' ? date('Y-m-d') : (!empty($data['enabled']) ? 1 : 0));
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $reply($stmt->execute() !== false);
}
if ($action === 'delete') {
    $stmt = $db->prepare('DELETE FROM emergency_entries WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $reply($stmt->execute() !== false);
}

$section = (string) ($data['section'] ?? '');
if ($id > 0) {
    $stored = $db->prepare('SELECT section FROM emergency_entries WHERE id = :id AND user_id = :userId');
    $stored->bindValue(':id', $id, SQLITE3_INTEGER);
    $stored->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $section = (string) ($stored->execute()->fetchArray()[0] ?? $section);
}
$name = $clean('name', 150);
if (!isset(EMERGENCY_SECTIONS[$section]) || $name === '') {
    die(json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]));
}
// Account numbers never in full - only in the accounts section, so phone numbers of contacts stay intact.
$mask = fn(string $text) => $section === 'accounts' ? emergencyMaskNumbers($text) : $text;
$info = $mask($clean('info', 300));
$detail = $mask($clean('detail', 2000));
$stmt = $id > 0
    ? $db->prepare('UPDATE emergency_entries SET name = :name, info = :info, detail = :detail, updated_at = :today, notified = NULL WHERE id = :id AND user_id = :userId')
    : $db->prepare('INSERT INTO emergency_entries (user_id, section, name, info, detail, updated_at) VALUES (:userId, :section, :name, :info, :detail, :today)');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':name', $name);
$stmt->bindValue(':info', $info);
$stmt->bindValue(':detail', $detail);
$stmt->bindValue(':today', date('Y-m-d'));
if ($id > 0) {
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
} else {
    $stmt->bindValue(':section', $section);
}
$reply($stmt->execute() !== false);
