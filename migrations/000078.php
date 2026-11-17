<?php
// Contracts and insurances: an announced price change with the date it applies from.
foreach (['contracts', 'insurances'] as $table) {
    $existing = [];
    $columnQuery = $db->query("PRAGMA table_info($table)");
    while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
        $existing[] = $column['name'];
    }
    foreach (['pending_price' => 'REAL', 'pending_price_date' => 'TEXT'] as $name => $definition) {
        if (!in_array($name, $existing)) {
            $db->exec("ALTER TABLE $table ADD COLUMN $name $definition");
        }
    }
}
