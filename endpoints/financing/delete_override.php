<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$loanId  = intval($_POST['loan_id'] ?? 0);
$periodN = intval($_POST['period_n'] ?? 0);

if ($loanId <= 0 || $periodN <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit();
}

$stmt = $db->prepare(
    "DELETE FROM loan_schedule_overrides WHERE loan_id = :loan_id AND user_id = :user_id AND period_n = :period_n"
);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => $db->lastErrorMsg()]);
    exit();
}
$stmt->bindParam(':loan_id', $loanId, SQLITE3_INTEGER);
$stmt->bindParam(':user_id', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':period_n', $periodN, SQLITE3_INTEGER);

header('Content-Type: application/json');
if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Override deleted']);
} else {
    echo json_encode(['success' => false, 'message' => $db->lastErrorMsg()]);
}
$db->close();
