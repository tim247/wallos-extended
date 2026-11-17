<?php
// Claims of an insurance (list / add / update / delete) plus its balance and premium
// development; "review" marks the cover as checked today.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/insurance_insights.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string) ($data['action'] ?? 'list');
$insuranceId = intval($data['insurance_id'] ?? 0);
$isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
$money = fn($v) => round((float) str_replace(',', '.', (string) $v), 2);

$stmt = $db->prepare('SELECT * FROM insurances WHERE id = :id AND user_id = :userId');
$stmt->bindValue(':id', $insuranceId, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$insurance = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!$insurance) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}

if ($action === 'add') {
    if (!$isDate($data['claim_date'] ?? null)) {
        die(json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]));
    }
    $stmt = $db->prepare('INSERT INTO insurance_claims (user_id, insurance_id, claim_date, description, claim_number, amount_claimed, amount_paid, status, status_changed)
        VALUES (:userId, :insuranceId, :date, :description, :number, :claimed, 0, "reported", :today)');
    $stmt->bindValue(':date', $data['claim_date']);
    $stmt->bindValue(':description', validate(mb_substr((string) ($data['description'] ?? ''), 0, 300)));
    $stmt->bindValue(':number', validate(mb_substr((string) ($data['claim_number'] ?? ''), 0, 60)));
    $stmt->bindValue(':claimed', $money($data['amount_claimed'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':today', date('Y-m-d'));
} elseif ($action === 'update') {
    $status = in_array($data['status'] ?? '', CLAIM_STATUSES, true) ? $data['status'] : 'reported';
    $stmt = $db->prepare('UPDATE insurance_claims SET status = :status, amount_paid = :paid,
        status_changed = CASE WHEN status != :status THEN :today ELSE status_changed END
        WHERE id = :id AND user_id = :userId AND insurance_id = :insuranceId');
    $stmt->bindValue(':status', $status);
    $stmt->bindValue(':paid', $money($data['amount_paid'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':today', date('Y-m-d'));
    $stmt->bindValue(':id', intval($data['id'] ?? 0), SQLITE3_INTEGER);
} elseif ($action === 'delete') {
    $stmt = $db->prepare('DELETE FROM insurance_claims WHERE id = :id AND user_id = :userId AND insurance_id = :insuranceId');
    $stmt->bindValue(':id', intval($data['id'] ?? 0), SQLITE3_INTEGER);
} elseif ($action === 'review') {
    $stmt = $db->prepare('UPDATE insurances SET last_review = :today WHERE id = :insuranceId AND user_id = :userId');
    $stmt->bindValue(':today', date('Y-m-d'));
} else {
    $stmt = null;
}
if ($stmt !== null) {
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':insuranceId', $insuranceId, SQLITE3_INTEGER);
    if ($stmt->execute() === false) {
        die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
    }
}

// Current state for the panel.
$claims = [];
$stmt = $db->prepare('SELECT * FROM insurance_claims WHERE insurance_id = :id AND user_id = :userId ORDER BY claim_date DESC, id DESC');
$stmt->bindValue(':id', $insuranceId, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $claims[] = $row;
}
$history = [];
$stmt = $db->prepare("SELECT id, old_price, new_price, changed_at FROM price_history WHERE item_type = 'insurance' AND item_id = :id AND user_id = :userId");
$stmt->bindValue(':id', $insuranceId, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $history[] = $row;
}
$today = date('Y-m-d');
$periods = insurancePricePeriods($insurance, $history);
$last = $db->prepare('SELECT last_review FROM insurances WHERE id = :id');
$last->bindValue(':id', $insuranceId, SQLITE3_INTEGER);
$deductible = (float) ($insurance['deductible'] ?? 0);
$refund = (float) ($insurance['refund_amount'] ?? 0);
foreach ($claims as &$claim) {
    $claim += claimWorthReporting((float) $claim['amount_claimed'], $deductible, $refund);
}
unset($claim);
echo json_encode([
    'success' => true,
    'worth' => claimWorthReporting(0, $deductible, $refund),
    'message' => $action === 'list' ? '' : translate('consumption_settings_saved', $i18n),
    'claims' => $claims,
    'balance' => insuranceBalance($insurance, $periods, $claims, $today),
    'development' => premiumDevelopment($insurance, $periods, $today),
    'last_review' => $last->execute()->fetchArray()[0],
    'currency' => $db->querySingle('SELECT code FROM currencies WHERE id = ' . (int) $insurance['currency_id']) ?: 'EUR',
]);
$db->close();
