<?php
// Add, edit or delete an income, a savings goal or a planned variable expense.
// POST JSON: kind (income|goal|expense), id, action (save|delete) and the fields.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$kind = $data['kind'] ?? '';
$table = ['income' => 'incomes', 'goal' => 'savings_goals', 'expense' => 'variable_expenses'][$kind] ?? null;
// Household member of this user, else 0 (= shared).
$member = fn($value) => (int) $db->querySingle('SELECT id FROM household WHERE id = ' . (int) $value . ' AND user_id = ' . (int) $userId);
$validDate = fn($value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? (string) $value : null;
$id = (int) ($data['id'] ?? 0);
$fail = fn() => die(json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]));
if (!$table) {
    $fail();
}

if (($data['action'] ?? 'save') === 'delete') {
    $stmt = $db->prepare("DELETE FROM $table WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    die(json_encode(['success' => (bool) $stmt->execute(), 'message' => translate('success', $i18n)]));
}

$name = mb_substr(trim(strip_tags((string) ($data['name'] ?? ''))), 0, 200);
$notes = mb_substr(trim(strip_tags((string) ($data['notes'] ?? ''))), 0, 1000);
if ($name === '') {
    $fail();
}
$values = [':name' => $name, ':notes' => $notes];
if ($kind === 'income') {
    $values += [
        ':amount' => max(0, (float) ($data['amount'] ?? 0)),
        ':cycle' => in_array((int) ($data['cycle'] ?? 3), [1, 2, 3, 4], true) ? (int) $data['cycle'] : 3,
        ':frequency' => max(1, min(366, (int) ($data['frequency'] ?? 1))),
        // Only the user's own currencies; anything else falls back to the main currency.
        ':currency' => (int) ($db->querySingle('SELECT id FROM currencies WHERE id = ' . (int) ($data['currency_id'] ?? 0) . ' AND user_id = ' . (int) $userId)
            ?: $db->querySingle('SELECT main_currency FROM user WHERE id = ' . (int) $userId)),
        ':payer' => $member($data['payer_user_id'] ?? 0),
        ':payday' => max(1, min(31, (int) ($data['pay_day'] ?? 1))),
        ':end' => $validDate($data['end_date'] ?? ''),
    ];
    $columns = 'name = :name, notes = :notes, amount = :amount, cycle = :cycle, frequency = :frequency, currency_id = :currency, payer_user_id = :payer, pay_day = :payday, end_date = :end';
    $insert = '(user_id, name, notes, amount, cycle, frequency, currency_id, payer_user_id, pay_day, end_date) VALUES (:userId, :name, :notes, :amount, :cycle, :frequency, :currency, :payer, :payday, :end)';
} elseif ($kind === 'expense') {
    $values += [':amount' => max(0, (float) ($data['amount'] ?? 0)), ':payer' => $member($data['payer_user_id'] ?? 0)];
    $columns = 'name = :name, notes = :notes, amount = :amount, payer_user_id = :payer';
    $insert = '(user_id, name, notes, amount, payer_user_id) VALUES (:userId, :name, :notes, :amount, :payer)';
} else {
    $values += [
        ':target' => max(0, (float) ($data['target_amount'] ?? 0)),
        ':saved' => max(0, (float) ($data['saved_amount'] ?? 0)),
        ':date' => $validDate($data['target_date'] ?? ''),
    ];
    $columns = 'name = :name, notes = :notes, target_amount = :target, saved_amount = :saved, target_date = :date';
    $insert = '(user_id, name, notes, target_amount, saved_amount, target_date) VALUES (:userId, :name, :notes, :target, :saved, :date)';
}

$stmt = $db->prepare($id > 0 ? "UPDATE $table SET $columns WHERE id = :id AND user_id = :userId" : "INSERT INTO $table $insert");
foreach ($values as $key => $value) {
    $stmt->bindValue($key, $value, $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : (is_float($value) ? SQLITE3_FLOAT : SQLITE3_TEXT)));
}
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
if ($id > 0) {
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
}
echo json_encode(['success' => (bool) $stmt->execute(), 'message' => translate('success', $i18n)]);
