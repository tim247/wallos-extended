<?php
// "What does an extra repayment bring?" - the loan schedule with one more extra payment,
// against the current one. Nothing is stored.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/financing_calculations.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$amount = round((float) str_replace(',', '.', (string) ($data['amount'] ?? 0)), 2);
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['date'] ?? '')) ? $data['date'] : date('Y-m-d');
$stmt = $db->prepare("SELECT * FROM loans WHERE id = :id AND user_id = :userId AND loan_type != 'bausparen'");
$stmt->bindValue(':id', intval($data['loan_id'] ?? 0), SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$loan = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!$loan || $amount <= 0) {
    die(json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]));
}
$in = loadLoanInputs($db, $userId, $loan);
$loan['rate_changes'] = $in['rate_changes'];
$now = computeLoanSchedule($loan, $in['extras'], null, $in['overrides'], $in['anchors'], $in['redeem_on']);
$extras = array_merge($in['extras'], [['payment_date' => $date, 'amount' => $amount]]);
$with = computeLoanSchedule($loan, $extras, null, $in['overrides'], $in['anchors'], $in['redeem_on']);
if ($now['error'] !== null || $with['error'] !== null) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}
$interestSaved = round($now['total_interest'] - $with['total_interest'], 2);
$months = count($now['rows']) - count($with['rows']);
// With a redemption (Bausparvertrag) or a fixed term the end date stays; then less is left to redeem.
$termEnd = loanTermEnd($loan);
$lastBalance = function (array $s) use ($termEnd): float {
    $last = end($s['rows']);
    // A redemption (Bausparvertrag) or the lump sum at the end of a fixed term.
    return (!empty($last['redemption']) || ($termEnd !== null && $last['date'] === $termEnd)) ? (float) $last['principal'] : 0.0;
};
echo json_encode([
    'success' => true,
    'interest_saved' => $interestSaved,
    'months_saved' => max(0, $months),
    'payoff_before' => $now['payoff_date'],
    'payoff_after' => $with['payoff_date'],
    'redemption_less' => round($lastBalance($now) - $lastBalance($with), 2),
    'return_percent' => $amount > 0 ? round($interestSaved / $amount * 100, 1) : 0,
]);
$db->close();
