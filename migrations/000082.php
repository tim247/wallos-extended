<?php
// Insurances: building blocks of a combined policy, refund for a claim-free year.
// Annual review: one decision per contract / insurance and year.
$existing = [];
$columnQuery = $db->query('PRAGMA table_info(insurances)');
while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $column['name'];
}
foreach ([
    'parent_id' => 'INTEGER',              // insurances.id of the combined policy this one is part of
    'refund_amount' => 'REAL DEFAULT 0',   // premium refund for a claim-free year
] as $name => $definition) {
    if (!in_array($name, $existing)) {
        $db->exec("ALTER TABLE insurances ADD COLUMN $name $definition");
    }
}

$db->exec('CREATE TABLE IF NOT EXISTS annual_review (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    item_type TEXT NOT NULL,
    item_id INTEGER NOT NULL,
    decision TEXT NOT NULL,
    monthly REAL DEFAULT 0,
    decided_at TEXT,
    UNIQUE(user_id, year, item_type, item_id)
)');
