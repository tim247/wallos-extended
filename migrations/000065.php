<?php
// Document expiry (warranty), annual summary, read-only accounts, income and savings goals.

$addColumns = function (string $table, array $columns) use ($db) {
    $existing = [];
    $result = $db->query("PRAGMA table_info($table)");
    while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
        $existing[] = $column['name'];
    }
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing)) {
            $db->exec("ALTER TABLE $table ADD COLUMN $name $definition");
        }
    }
};
$addColumns('item_files', ['expires_at' => 'TEXT', 'expiry_notified' => 'TEXT']);
$addColumns('user', [
    'readonly_of' => 'INTEGER', // set = read-only login that sees this user's data
    'year_summary_sent' => 'INTEGER', // year of the last annual summary notification
]);

$db->exec("CREATE TABLE IF NOT EXISTS incomes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    amount REAL NOT NULL DEFAULT 0,
    cycle INTEGER NOT NULL DEFAULT 3,
    frequency INTEGER NOT NULL DEFAULT 1,
    currency_id INTEGER NOT NULL DEFAULT 1,
    payer_user_id INTEGER DEFAULT 0,
    notes TEXT DEFAULT ''
)");
$db->exec("CREATE TABLE IF NOT EXISTS savings_goals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    target_amount REAL NOT NULL DEFAULT 0,
    saved_amount REAL NOT NULL DEFAULT 0,
    target_date TEXT,
    notes TEXT DEFAULT ''
)");
