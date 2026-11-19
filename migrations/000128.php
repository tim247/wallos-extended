<?php
// Vehicles: Kfz-Steuer per year with its next debit date and an announced change (new amount from a date).
// Changes of the amount land in price_history (item_type 'vehicle_tax') like contract prices.

$existing = [];
$columnQuery = $db->query('PRAGMA table_info(vehicles)');
while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $column['name'];
}
foreach ([
    'tax_amount' => 'REAL DEFAULT 0',   // per year
    'tax_due' => 'TEXT',                // next debit date
    'tax_pending_amount' => 'REAL',     // announced new amount ...
    'tax_pending_date' => 'TEXT',       // ... valid from this date
] as $name => $definition) {
    if (!in_array($name, $existing)) {
        $db->exec("ALTER TABLE vehicles ADD COLUMN $name $definition");
    }
}
$db->exec("CREATE TRIGGER IF NOT EXISTS price_history_vehicle_tax AFTER UPDATE OF tax_amount ON vehicles
    WHEN NEW.tax_amount <> OLD.tax_amount AND OLD.tax_amount > 0
    BEGIN
        INSERT INTO price_history (user_id, item_type, item_id, old_price, new_price, notified)
        VALUES (NEW.user_id, 'vehicle_tax', NEW.id, OLD.tax_amount, NEW.tax_amount, 1);
    END");
