<?php
// Data for the cancellation dialog: defaults for the letter and the current status.
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

$deadline = getNextCancellationDeadline($item, date('Y-m-d'));
$profile = $db->querySingle("SELECT sender_name, sender_address, place FROM letter_profiles WHERE user_id = " . intval($userId), true);
if (!$profile) {
    $user = $db->querySingle("SELECT firstname, lastname FROM user WHERE id = " . intval($userId), true) ?: [];
    $profile = ['sender_name' => trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')), 'sender_address' => '', 'place' => ''];
}

echo json_encode([
    'success' => true,
    'name' => $item['name'],
    'provider' => $item['_provider'],
    'number' => $item['_number'],
    'recipient_address' => (string) ($item['provider_address'] ?? ''),
    'terminate_on' => $deadline['term_end'] ?? '',
    'deadline' => $deadline['deadline'] ?? '',
    'sender_name' => $profile['sender_name'],
    'sender_address' => $profile['sender_address'],
    'place' => $profile['place'],
    'sent_date' => $item['cancellation_sent_date'],
    'confirmed_date' => $item['cancellation_confirmed_date'],
    'cancelled' => (int) $item['cancellation_status'],
]);
$db->close();
