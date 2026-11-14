<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$loanId = intval($data["id"] ?? 0);
$deleteStmt = $db->prepare("DELETE FROM loans WHERE id = :loanId AND user_id = :userId");
$deleteStmt->bindParam(':loanId', $loanId, SQLITE3_INTEGER);
$deleteStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);

if ($deleteStmt->execute()) {
    $linkStmt = $db->prepare("DELETE FROM item_documents WHERE item_type = 'loan' AND item_id = :itemId AND user_id = :userId");
    $linkStmt->bindValue(':itemId', $loanId, SQLITE3_INTEGER);
    $linkStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $linkStmt->execute();
    foreach (['loan_balance_anchors', 'loan_schedule_overrides'] as $cleanupTable) {
        $cleanup = $db->prepare("DELETE FROM $cleanupTable WHERE loan_id = :loanId AND user_id = :userId");
        $cleanup->bindValue(':loanId', $loanId, SQLITE3_INTEGER);
        $cleanup->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $cleanup->execute();
    }
    $unlink = $db->prepare("UPDATE loans SET replaced_by_loan_id = NULL WHERE replaced_by_loan_id = :loanId AND user_id = :userId");
    $unlink->bindValue(':loanId', $loanId, SQLITE3_INTEGER);
    $unlink->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $unlink->execute();
    $stmt = $db->prepare("DELETE FROM loan_extra_payments WHERE loan_id = :loanId AND user_id = :userId");
    $stmt->bindParam(':loanId', $loanId, SQLITE3_INTEGER);
    $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();

    echo json_encode([
        "success" => true,
        "message" => translate('loan_deleted', $i18n)
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => translate('error', $i18n)
    ]);
}
$db->close();
