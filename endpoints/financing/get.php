<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/financing_calculations.php';

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    if (isset($_GET['id']) && $_GET['id'] != "") {
        $loanId = intval($_GET['id']);
        $query = "SELECT * FROM loans WHERE id = :loanId AND user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':loanId', $loanId, SQLITE3_INTEGER);
        $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['name'] = htmlspecialchars_decode($row['name'] ?? "");
            $row['lender'] = htmlspecialchars_decode($row['lender'] ?? "");
            $row['notes'] = htmlspecialchars_decode($row['notes'] ?? "");

            $extraPayments = [];
            $stmt = $db->prepare("SELECT * FROM loan_extra_payments WHERE loan_id = :loanId AND user_id = :userId ORDER BY payment_date ASC");
            $stmt->bindParam(':loanId', $loanId, SQLITE3_INTEGER);
            $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
            $epResult = $stmt->execute();
            while ($ep = $epResult->fetchArray(SQLITE3_ASSOC)) {
                $extraPayments[] = $ep;
            }

            $row['extra_payments'] = $extraPayments;

            $overrides = [];
            $ovStmt = $db->prepare("SELECT * FROM loan_schedule_overrides WHERE loan_id = :loanId AND user_id = :userId ORDER BY period_n ASC");
            if ($ovStmt) {
                $ovStmt->bindParam(':loanId', $loanId, SQLITE3_INTEGER);
                $ovStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
                $ovResult = $ovStmt->execute();
                while ($ov = $ovResult->fetchArray(SQLITE3_ASSOC)) {
                    $overrides[] = $ov;
                }
            }
            $row['overrides'] = $overrides;

            $loanType = $row['loan_type'] ?? 'annuity';
            if ($loanType === 'bausparen') {
                $row['schedule'] = computeBausparSchedule($row);
                $row['schedule_plan'] = null;
            } else {
                $in = loadLoanInputs($db, $userId, $row);
                $row['anchors'] = $in['anchors'];
                $row['schedule'] = computeLoanSchedule($row, $extraPayments, null, $overrides, $in['anchors'], $in['redeem_on']);
                $row['schedule_plan'] = computeLoanSchedule($row, [], null, [], [], $in['redeem_on']);
            }

            header('Content-Type: application/json');
            echo json_encode($row);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['error' => translate('error', $i18n)]);
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => translate('error', $i18n)]);
    }
}
$db->close();
?>
