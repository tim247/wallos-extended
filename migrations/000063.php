<?php
// Monthly budget per category (0 = none).
$hasBudget = false;
$result = $db->query('PRAGMA table_info(categories)');
while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
    $hasBudget = $hasBudget || $column['name'] === 'budget';
}
if (!$hasBudget) {
    $db->exec('ALTER TABLE categories ADD COLUMN budget REAL DEFAULT 0');
}
