<?php
// Cancellation status: sent -> confirmed, or reset. "sent" also marks the item as
// cancelled, which stops the deadline reminders.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/cancellation_letter.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$type = (string) ($data['type'] ?? '');
$action = (string) ($data['action'] ?? '');
$item = cancellationItem($db, $userId, $type, intval($data['id'] ?? 0));
if ($item === null || !in_array($action, ['sent', 'confirmed', 'reset'], true)) {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
    exit;
}
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['date'] ?? '')) ? $data['date'] : date('Y-m-d');

if ($action === 'reset') {
    $sent = null;
    $confirmed = null;
    $cancelled = 0;
} elseif ($action === 'sent') {
    $sent = $date;
    $confirmed = null;
    $cancelled = 1;
} else {
    $sent = $item['cancellation_sent_date'] ?: $date;
    $confirmed = $date;
    $cancelled = 1;
}

$stmt = $db->prepare("UPDATE " . CANCELLATION_ITEM_TABLES[$type] . " SET cancellation_status = :cancelled, cancellation_sent_date = :sent, cancellation_confirmed_date = :confirmed WHERE id = :id AND user_id = :userId");
$stmt->bindValue(':cancelled', $cancelled, SQLITE3_INTEGER);
$stmt->bindValue(':sent', $sent, SQLITE3_TEXT);
$stmt->bindValue(':confirmed', $confirmed, SQLITE3_TEXT);
$stmt->bindValue(':id', $item['id'], SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
echo json_encode($stmt->execute()
    ? ['success' => true, 'message' => translate('cancellation_status_saved', $i18n)]
    : ['success' => false, 'message' => translate('error', $i18n)]);
$db->close();
