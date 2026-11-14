<?php
// Every active recurring cost of a user, converted to the main currency.
// Shared by the summary API (Home Assistant), the annual report and the bank import.

require_once __DIR__ . '/price_helpers.php';
require_once __DIR__ . '/financing_calculations.php';

/**
 * @return array<int, array{type: string, id: int, name: string, match: string, amount: float, monthly: float, payer_id: int}>
 *   amount = one payment in the main currency, match = text to find it on a bank statement
 */
function householdCostItems(SQLite3 $db, int $userId): array
{
    $items = [];
    $rows = function (string $sql) use ($db, $userId): array {
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $out = [];
        while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
            $out[] = $row;
        }
        return $out;
    };

    $sources = [
        'subscription' => ["SELECT *, '' AS provider FROM subscriptions WHERE user_id = :userId AND archived = 0 AND inactive = 0", 'provider'],
        'contract' => ["SELECT * FROM contracts WHERE user_id = :userId AND archived = 0", 'provider'],
        'insurance' => ["SELECT *, insurer AS provider FROM insurances WHERE user_id = :userId AND archived = 0", 'provider'],
    ];
    foreach ($sources as $type => [$sql]) {
        foreach ($rows($sql) as $row) {
            $amount = getPriceConverted((float) $row['price'], $row['currency_id'], $db, $userId);
            $items[] = [
                'type' => $type,
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'match' => trim($row['name'] . ' ' . ($row['provider'] ?? '')),
                'amount' => round($amount, 2),
                'monthly' => round(getPricePerMonth((int) $row['cycle'], (int) $row['frequency'], $amount), 2),
                'payer_id' => (int) ($row['payer_user_id'] ?? 0),
            ];
        }
    }
    foreach ($rows("SELECT * FROM loans WHERE user_id = :userId AND archived = 0") as $row) {
        $schedule = loadLoanSchedule($db, $userId, $row, date('Y-m-d'));
        if ($schedule['error'] !== null || $schedule['balance_as_of'] <= 0) {
            continue; // paid off
        }
        $amount = round(getPriceConverted((float) $row['monthly_rate'], $row['currency_id'], $db, $userId), 2);
        $items[] = ['type' => 'loan', 'id' => (int) $row['id'], 'name' => $row['name'], 'match' => trim($row['name'] . ' ' . $row['lender']),
            'amount' => $amount, 'monthly' => $amount, 'payer_id' => 0];
    }
    foreach ($rows("SELECT * FROM meters WHERE user_id = :userId AND archived = 0 AND monthly_advance > 0") as $row) {
        $amount = round(getPriceConverted((float) $row['monthly_advance'], $row['currency_id'], $db, $userId), 2);
        $items[] = ['type' => 'meter', 'id' => (int) $row['id'], 'name' => $row['name'], 'match' => $row['name'],
            'amount' => $amount, 'monthly' => $amount, 'payer_id' => 0];
    }
    return $items;
}
