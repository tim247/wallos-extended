<?php
// Cancellation package (ZIP of PDF letters) for the owner. POST form: sender_name, sender_address, place, deceased.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/emergency_letters.php';

$clean = fn(string $key, int $max) => mb_substr(trim(strip_tags((string) ($_POST[$key] ?? ''))), 0, $max);
$path = emergencyLetterZip($db, (int) $userId,
    ['name' => $clean('sender_name', 120), 'address' => $clean('sender_address', 400), 'place' => $clean('place', 80)],
    $clean('deceased', 120));
if ($path === null) {
    header('Content-Type: application/json');
    die(json_encode(['success' => false, 'message' => translate('emergency_letters_none', $i18n)]));
}
emergencySendLetterZip($path);
