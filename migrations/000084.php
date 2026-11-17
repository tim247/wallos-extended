<?php
// Loans: allowed extra repayment per year (percentage of the loan or a fixed amount),
// and planned changes of the monthly rate ("from 2027 600 € instead of 500 €").
$existing = [];
$columnQuery = $db->query('PRAGMA table_info(loans)');
while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $column['name'];
}
foreach ([
    'extra_allowance_pct' => 'REAL DEFAULT 0',     // e.g. 5 = 5 % of the loan amount per calendar year
    'extra_allowance_amount' => 'REAL DEFAULT 0',  // or a fixed amount per year
    'extra_reminded_year' => 'INTEGER DEFAULT 0',
] as $name => $definition) {
    if (!in_array($name, $existing)) {
        $db->exec("ALTER TABLE loans ADD COLUMN $name $definition");
    }
}

$db->exec('CREATE TABLE IF NOT EXISTS loan_rate_changes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    loan_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    valid_from TEXT NOT NULL,
    monthly_rate REAL NOT NULL
)');
