<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$loanId  = intval($_POST['loan_id'] ?? 0);
$periodN = intval($_POST['period_n'] ?? 0);
$amount  = floatval(str_replace(',', '.', $_POST['payment_amount'] ?? '0'));

if ($loanId <= 0 || $periodN <= 0 || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
}

// Verify loan belongs to user
$check = $db->prepare("SELECT id FROM loans WHERE id = :id AND user_id = :uid");
$check->bindParam(':id', $loanId, SQLITE3_INTEGER);
$check->bindParam(':uid', $userId, SQLITE3_INTEGER);
if (!$check->execute()->fetchArray()) {
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit();
}

$stmt = $db->prepare(
    "INSERT INTO loan_schedule_overrides (loan_id, user_id, period_n, payment_amount)
     VALUES (:loan_id, :user_id, :period_n, :amount)
     ON CONFLICT(loan_id, period_n) DO UPDATE SET payment_amount = :amount"
);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => $db->lastErrorMsg()]);
    exit();
}
$stmt->bindParam(':loan_id', $loanId, SQLITE3_INTEGER);
$stmt->bindParam(':user_id', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':period_n', $periodN, SQLITE3_INTEGER);
$stmt->bindParam(':amount', $amount, SQLITE3_FLOAT);

header('Content-Type: application/json');
if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Override saved']);
} else {
    echo json_encode(['success' => false, 'message' => $db->lastErrorMsg()]);
}
$db->close();
