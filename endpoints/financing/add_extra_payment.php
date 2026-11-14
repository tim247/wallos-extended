<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$loanId = intval($data['loan_id'] ?? 0);
$paymentDate = validate($data['payment_date'] ?? '');
$amount = floatval($data['amount'] ?? 0);
$notes = validate($data['notes'] ?? '');

if ($loanId <= 0 || $paymentDate === '' || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]);
    exit();
}

// Verify the loan belongs to the user.
$check = $db->prepare("SELECT id FROM loans WHERE id = :loanId AND user_id = :userId");
$check->bindParam(':loanId', $loanId, SQLITE3_INTEGER);
$check->bindParam(':userId', $userId, SQLITE3_INTEGER);
$checkResult = $check->execute();
if (!$checkResult || !$checkResult->fetchArray()) {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
    exit();
}

$stmt = $db->prepare("INSERT INTO loan_extra_payments (loan_id, user_id, payment_date, amount, notes)
                      VALUES (:loanId, :userId, :paymentDate, :amount, :notes)");
$stmt->bindParam(':loanId', $loanId, SQLITE3_INTEGER);
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':paymentDate', $paymentDate, SQLITE3_TEXT);
$stmt->bindParam(':amount', $amount, SQLITE3_FLOAT);
$stmt->bindParam(':notes', $notes, SQLITE3_TEXT);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => translate('extra_payment_added', $i18n)]);
} else {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n)]);
}
$db->close();
