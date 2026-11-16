<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/getsettings.php';
$isEdit = isset($_POST['id']) && $_POST['id'] != '';

$name        = validate($_POST['name'] ?? '');
$loanType    = in_array($_POST['loan_type'] ?? 'annuity', ['annuity', 'interest_only', 'bausparen'])
               ? $_POST['loan_type'] : 'annuity';
$principal   = floatval($_POST['principal'] ?? 0);
$startDate   = validate($_POST['start_date'] ?? '');
$currencyId  = intval($_POST['currency_id'] ?? 1);

if ($name === '' || $principal <= 0 || $startDate === '') {
    echo json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]);
    exit();
}

$lender       = validate($_POST['lender'] ?? '');
$notes        = validate($_POST['notes'] ?? '');
$interestRate = floatval(str_replace(',', '.', $_POST['interest_rate'] ?? '0'));
$monthlyRate  = floatval(str_replace(',', '.', $_POST['monthly_rate'] ?? '0'));
$termMonths   = ($_POST['term_months'] ?? '') !== '' ? intval($_POST['term_months']) : null;
$fixedEnd     = validate($_POST['fixed_interest_end'] ?? '');
$fixedEnd     = $fixedEnd !== '' ? $fixedEnd : null;

// Optional link: this loan is paid off by the payout of one of the user's Bausparverträge.
$replacedBy = null;
if ($loanType !== 'bausparen' && !empty($_POST['replaced_by_loan_id'])) {
    $candidate = intval($_POST['replaced_by_loan_id']);
    $check = $db->prepare("SELECT id FROM loans WHERE id = :id AND user_id = :userId AND loan_type = 'bausparen'");
    $check->bindValue(':id', $candidate, SQLITE3_INTEGER);
    $check->bindValue(':userId', $userId, SQLITE3_INTEGER);
    if ($check->execute()->fetchArray()) {
        $replacedBy = $candidate;
    }
}

$interestOnlyMonths     = intval($_POST['interest_only_months'] ?? 0);
$bausparenLoanRate      = floatval(str_replace(',', '.', $_POST['bausparen_loan_rate'] ?? '0'));
$bausparenLoanMonthly   = floatval(str_replace(',', '.', $_POST['bausparen_loan_monthly'] ?? '0'));
$bausparenMinSavingsPct = floatval(str_replace(',', '.', $_POST['bausparen_min_savings_pct'] ?? '40'));
$bausparenFee           = max(0.0, floatval(str_replace(',', '.', $_POST['bausparen_fee'] ?? '0')));

// Type-specific validation
if ($loanType === 'bausparen') {
    if ($monthlyRate <= 0) {
        echo json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]);
        exit();
    }
    if ($bausparenLoanMonthly > 0) {
        $loanAmount = $principal * (1 - $bausparenMinSavingsPct / 100);
        $firstInterest = $loanAmount * ($bausparenLoanRate / 100 / 12);
        if ($bausparenLoanMonthly <= $firstInterest && $firstInterest > 0) {
            echo json_encode(['success' => false, 'message' => translate('loan_rate_below_interest', $i18n)]);
            exit();
        }
    }
} else {
    // Annuity: monthly_rate required and above the interest. Interest-only: optional (repaid in one sum or by a Bausparvertrag).
    if ($monthlyRate <= 0 && $loanType !== 'interest_only') {
        echo json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]);
        exit();
    }
    $firstInterest = $principal * ($interestRate / 100 / 12);
    if ($loanType !== 'interest_only' && $monthlyRate <= $firstInterest) {
        echo json_encode(['success' => false, 'message' => translate('loan_rate_below_interest', $i18n)]);
        exit();
    }
}

$cols = "user_id, name, lender, principal, interest_rate, monthly_rate, start_date,
         term_months, fixed_interest_end, currency_id, notes, loan_type,
         interest_only_months, bausparen_loan_rate, bausparen_loan_monthly, bausparen_min_savings_pct, bausparen_fee, replaced_by_loan_id";
$vals = ":userId, :name, :lender, :principal, :interestRate, :monthlyRate, :startDate,
         :termMonths, :fixedEnd, :currencyId, :notes, :loanType,
         :interestOnlyMonths, :bausparenLoanRate, :bausparenLoanMonthly, :bausparenMinSavingsPct, :bausparenFee, :replacedBy";

if (!$isEdit) {
    $sql = "INSERT INTO loans ($cols) VALUES ($vals)";
} else {
    $id  = intval($_POST['id']);
    $sql = "UPDATE loans SET
                name = :name, lender = :lender, principal = :principal,
                interest_rate = :interestRate, monthly_rate = :monthlyRate,
                start_date = :startDate, term_months = :termMonths,
                fixed_interest_end = :fixedEnd, currency_id = :currencyId,
                notes = :notes, loan_type = :loanType,
                interest_only_months = :interestOnlyMonths,
                bausparen_loan_rate = :bausparenLoanRate,
                bausparen_loan_monthly = :bausparenLoanMonthly,
                bausparen_min_savings_pct = :bausparenMinSavingsPct,
                bausparen_fee = :bausparenFee,
                replaced_by_loan_id = :replacedBy
            WHERE id = :id AND user_id = :userId";
}

$stmt = $db->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $db->lastErrorMsg()]);
    exit();
}
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':name', $name, SQLITE3_TEXT);
$stmt->bindParam(':lender', $lender, SQLITE3_TEXT);
$stmt->bindParam(':principal', $principal, SQLITE3_FLOAT);
$stmt->bindParam(':interestRate', $interestRate, SQLITE3_FLOAT);
$stmt->bindParam(':monthlyRate', $monthlyRate, SQLITE3_FLOAT);
$stmt->bindParam(':startDate', $startDate, SQLITE3_TEXT);
$stmt->bindParam(':termMonths', $termMonths, SQLITE3_INTEGER);
$stmt->bindParam(':fixedEnd', $fixedEnd, SQLITE3_TEXT);
$stmt->bindParam(':currencyId', $currencyId, SQLITE3_INTEGER);
$stmt->bindParam(':notes', $notes, SQLITE3_TEXT);
$stmt->bindParam(':loanType', $loanType, SQLITE3_TEXT);
$stmt->bindParam(':interestOnlyMonths', $interestOnlyMonths, SQLITE3_INTEGER);
$stmt->bindParam(':bausparenLoanRate', $bausparenLoanRate, SQLITE3_FLOAT);
$stmt->bindParam(':bausparenLoanMonthly', $bausparenLoanMonthly, SQLITE3_FLOAT);
$stmt->bindParam(':bausparenMinSavingsPct', $bausparenMinSavingsPct, SQLITE3_FLOAT);
$stmt->bindValue(':bausparenFee', $bausparenFee, SQLITE3_FLOAT);
$stmt->bindValue(':replacedBy', $replacedBy, $replacedBy === null ? SQLITE3_NULL : SQLITE3_INTEGER);
if ($isEdit) {
    $stmt->bindParam(':id', $id, SQLITE3_INTEGER);
}

header('Content-Type: application/json');
if ($stmt->execute()) {
    $text = $isEdit ? 'loan_updated_successfully' : 'loan_added_successfully';
    echo json_encode(['success' => true, 'message' => translate($text, $i18n)]);
} else {
    echo json_encode(['success' => false, 'message' => translate('error', $i18n) . ': ' . $db->lastErrorMsg()]);
}
$db->close();
