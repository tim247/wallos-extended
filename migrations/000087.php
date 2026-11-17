<?php
// Contracts: a type (electricity, internet, rent, leasing …) and its type-specific details as JSON.
$existing = [];
$columnQuery = $db->query('PRAGMA table_info(contracts)');
while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $column['name'];
}
foreach (['contract_type' => "TEXT DEFAULT 'other'", 'details' => "TEXT DEFAULT ''"] as $name => $definition) {
    if (!in_array($name, $existing)) {
        $db->exec("ALTER TABLE contracts ADD COLUMN $name $definition");
    }
}
