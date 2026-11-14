<?php
/*
Summary of the household costs, e.g. for Home Assistant REST sensors.
GET/POST, parameter api_key (or header X-API-Key).

{
  "success": true,
  "currency": "EUR",
  "monthly_total": 1234.56,
  "yearly_total": 14814.72,
  "monthly": {"subscription": 50.0, "contract": 120.0, "insurance": 80.0, "loan": 900.0, "meter": 84.56},
  "count": 23,
  "price_increases_12m": 2,
  "next_payment": {"name": "Netflix", "date": "2026-12-01", "amount": 12.99},
  "next_deadline": {"name": "Fitnessstudio", "date": "2027-01-31", "days": 78}
}
*/

require_once '../../includes/connect_endpoint.php';
require_once '../../includes/household_costs.php';
require_once '../../includes/cancellation_helpers.php';

header('Content-Type: application/json; charset=UTF-8');

$apiKey = $_REQUEST['api_key'] ?? $_REQUEST['apiKey'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
$stmt = $db->prepare("SELECT * FROM user WHERE api_key = :apiKey AND api_key != ''");
$stmt->bindValue(':apiKey', (string) $apiKey);
$user = $apiKey ? $stmt->execute()->fetchArray(SQLITE3_ASSOC) : false;
if (!$user) {
    http_response_code(401);
    echo json_encode(["success" => false, "title" => "Invalid API key"]);
    exit;
}
$userId = (int) $user['id'];
$GLOBALS['userId'] = $userId;

$items = householdCostItems($db, $userId);
$monthly = ['subscription' => 0.0, 'contract' => 0.0, 'insurance' => 0.0, 'loan' => 0.0, 'meter' => 0.0];
foreach ($items as $item) {
    $monthly[$item['type']] += $item['monthly'];
}
$monthly = array_map(fn($v) => round($v, 2), $monthly);
$total = round(array_sum($monthly), 2);

$today = date('Y-m-d');
$stmt = $db->prepare("SELECT name, next_payment, price, currency_id FROM subscriptions
    WHERE user_id = :userId AND archived = 0 AND inactive = 0 AND next_payment >= :today ORDER BY next_payment LIMIT 1");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':today', $today);
$row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$nextPayment = $row ? ['name' => $row['name'], 'date' => $row['next_payment'],
    'amount' => round(getPriceConverted((float) $row['price'], $row['currency_id'], $db, $userId), 2)] : null;

$nextDeadline = null;
foreach (['contracts', 'insurances'] as $table) {
    $stmt = $db->prepare("SELECT * FROM $table WHERE user_id = :userId AND archived = 0 AND cancellation_status = 0");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $deadline = getNextCancellationDeadline($row, $today);
        if ($deadline !== null && ($nextDeadline === null || $deadline['deadline'] < $nextDeadline['date'])) {
            $nextDeadline = ['name' => $row['name'], 'date' => $deadline['deadline'],
                'days' => (int) round((strtotime($deadline['deadline']) - strtotime($today)) / 86400)];
        }
    }
}

$stmt = $db->prepare("SELECT COUNT(*) FROM price_history WHERE user_id = :userId AND new_price > old_price AND changed_at >= date('now', '-12 months')");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$increases = (int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0];

$stmt = $db->prepare("SELECT code FROM currencies WHERE id = :id");
$stmt->bindValue(':id', $user['main_currency'], SQLITE3_INTEGER);
$currency = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['code'] ?? 'EUR';

echo json_encode([
    "success" => true,
    "currency" => $currency,
    "monthly_total" => $total,
    "yearly_total" => round($total * 12, 2),
    "monthly" => $monthly,
    "count" => count($items),
    "price_increases_12m" => $increases,
    "next_payment" => $nextPayment,
    "next_deadline" => $nextDeadline,
]);
