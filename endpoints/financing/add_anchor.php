<?php
// Balance from a bank statement: from this date on the schedule continues from it.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$loanId = intval($data['loan_id'] ?? 0);
$date = (string) ($data['anchor_date'] ?? '');
$balance = $data['balance'] ?? null;

if ($loanId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($balance) || $balance < 0) {
    echo json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]);
    exit();
}

$check = $db->prepare("SELECT id FROM loans WHERE id = :loanId AND user_id = :userId AND loan_type != 'bausparen'");
$check->bindValue(':loanId', $loanId, SQLITE3_INTEGER);
$check->bindValue(':userId', $userId, SQLITE3_INTEGER);
if (!$check->execute()->fetchArray()) {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
    exit();
}

$stmt = $db->prepare("INSERT INTO loan_balance_anchors (loan_id, user_id, anchor_date, balance) VALUES (:loanId, :userId, :date, :balance)
                      ON CONFLICT(loan_id, anchor_date) DO UPDATE SET balance = :balance");
$stmt->bindValue(':loanId', $loanId, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':date', $date, SQLITE3_TEXT);
$stmt->bindValue(':balance', round((float) $balance, 2), SQLITE3_FLOAT);
echo json_encode($stmt->execute()
    ? ['success' => true, 'message' => translate('loan_anchor_saved', $i18n)]
    : ['success' => false, 'message' => translate('error', $i18n)]);
$db->close();
