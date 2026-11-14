<?php
// Loans: balances from bank statements, and "redeemed by Bausparvertrag" link.

$db->exec("CREATE TABLE IF NOT EXISTS loan_balance_anchors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    loan_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    anchor_date TEXT NOT NULL,
    balance REAL NOT NULL,
    UNIQUE(loan_id, anchor_date)
)");

$existing = [];
$columnQuery = $db->query("PRAGMA table_info(loans)");
while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $column['name'];
}
if (!in_array('replaced_by_loan_id', $existing)) {
    $db->exec('ALTER TABLE loans ADD COLUMN replaced_by_loan_id INTEGER');
}
