<?php
// Meters: link to the supply contract, price guarantee and a reference price for the
// "check a tariff switch" reminder.
$existing = [];
$columnQuery = $db->query('PRAGMA table_info(meters)');
while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $column['name'];
}
foreach ([
    'contract_id' => 'INTEGER',              // contracts.id of the supply contract
    'price_guarantee_until' => 'TEXT',       // end of the price guarantee (Y-m-d)
    'reference_price' => 'REAL DEFAULT 0',   // price of a good offer, per unit
    'switch_notified' => 'TEXT',             // what the last switch reminder was about
] as $name => $definition) {
    if (!in_array($name, $existing)) {
        $db->exec("ALTER TABLE meters ADD COLUMN $name $definition");
    }
}
