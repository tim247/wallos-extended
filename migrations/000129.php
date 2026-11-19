<?php
// Vehicles: the Kfz-Steuer debit the reminder mail was sent for (once per due date).

$column = $db->query("SELECT * FROM pragma_table_info('vehicles') WHERE name='tax_notified_for'");
if ($column->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec('ALTER TABLE vehicles ADD COLUMN tax_notified_for TEXT');
}
