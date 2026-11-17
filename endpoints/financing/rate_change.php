<?php
// Planned changes of the monthly rate of a loan: add or delete.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
if (($data['action'] ?? 'add') === 'delete') {
    $stmt = $db->prepare('DELETE FROM loan_rate_changes WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':id', intval($data['id'] ?? 0), SQLITE3_INTEGER);
} else {
    $loanId = intval($data['loan_id'] ?? 0);
    $owner = $db->prepare("SELECT COUNT(*) FROM loans WHERE id = :id AND user_id = :userId AND loan_type != 'bausparen'");
    $owner->bindValue(':id', $loanId, SQLITE3_INTEGER);
    $owner->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $rate = (float) str_replace(',', '.', (string) ($data['monthly_rate'] ?? 0));
    if (!$owner->execute()->fetchArray()[0] || $rate <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['valid_from'] ?? ''))) {
        die(json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]));
    }
    $stmt = $db->prepare('INSERT INTO loan_rate_changes (loan_id, user_id, valid_from, monthly_rate) VALUES (:loanId, :userId, :from, :rate)');
    $stmt->bindValue(':loanId', $loanId, SQLITE3_INTEGER);
    $stmt->bindValue(':from', $data['valid_from']);
    $stmt->bindValue(':rate', round($rate, 2), SQLITE3_FLOAT);
}
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$ok = $stmt->execute() !== false;
echo json_encode(['success' => $ok, 'message' => translate($ok ? 'consumption_settings_saved' : 'error', $i18n)]);
$db->close();
