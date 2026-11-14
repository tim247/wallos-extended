<?php
// Creates the cancellation letter PDF and remembers sender + provider address.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/cancellation_helpers.php';
require_once '../../includes/cancellation_letter.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$type = (string) ($data['type'] ?? '');
$item = cancellationItem($db, $userId, $type, intval($data['id'] ?? 0));
if ($item === null) {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
    exit;
}

$clean = fn($key, $max) => mb_substr(trim((string) ($data[$key] ?? '')), 0, $max);
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['date'] ?? '')) ? $data['date'] : date('Y-m-d');
$terminateOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['terminate_on'] ?? '')) ? $data['terminate_on'] : '';
$senderName = $clean('sender_name', 120);
$senderAddress = $clean('sender_address', 400);
$recipientAddress = $clean('recipient_address', 400);
$place = $clean('place', 80);

if ($senderName === '' || $senderAddress === '') {
    echo json_encode(['success' => false, 'message' => translate('cancellation_sender_required', $i18n)]);
    exit;
}

$stmt = $db->prepare("INSERT OR REPLACE INTO letter_profiles (user_id, sender_name, sender_address, place) VALUES (:userId, :name, :address, :place)");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':name', $senderName, SQLITE3_TEXT);
$stmt->bindValue(':address', $senderAddress, SQLITE3_TEXT);
$stmt->bindValue(':place', $place, SQLITE3_TEXT);
$stmt->execute();

$stmt = $db->prepare("UPDATE " . CANCELLATION_ITEM_TABLES[$type] . " SET provider_address = :address WHERE id = :id AND user_id = :userId");
$stmt->bindValue(':address', $recipientAddress, SQLITE3_TEXT);
$stmt->bindValue(':id', $item['id'], SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->execute();

$deadline = getNextCancellationDeadline($item, date('Y-m-d'));
$pdf = cancellationLetterPdf(cancellationTemplate($db), [
    'sender_name' => $senderName, 'sender_address' => $senderAddress,
    'provider' => $item['_provider'], 'recipient_address' => $recipientAddress,
    'item_name' => $item['name'], 'number' => $item['_number'], 'number_label' => $item['_number_label'],
    'terminate_on' => $terminateOn, 'deadline' => $deadline['deadline'] ?? '',
    'date' => $date, 'place' => $place,
]);

$slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $item['name'])), '-') ?: 'Vertrag';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Kuendigung-' . $slug . '.pdf"');
header('X-Content-Type-Options: nosniff');
echo $pdf;
