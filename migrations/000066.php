<?php
// Budget planning: pay day and end date of an income, planned variable expenses.

$existing = [];
$result = $db->query('PRAGMA table_info(incomes)');
while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $column['name'];
}
if (!in_array('pay_day', $existing)) {
    $db->exec('ALTER TABLE incomes ADD COLUMN pay_day INTEGER DEFAULT 1'); // day of the month the money arrives
}
if (!in_array('end_date', $existing)) {
    $db->exec('ALTER TABLE incomes ADD COLUMN end_date TEXT'); // e.g. child benefit ends
}

$db->exec("CREATE TABLE IF NOT EXISTS variable_expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    amount REAL NOT NULL DEFAULT 0, -- per month, main currency
    payer_user_id INTEGER DEFAULT 0,
    notes TEXT DEFAULT ''
)");
