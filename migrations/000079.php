<?php
// Insurances: how they can be ended. Health insurance can only be switched to another
// insurer (no cancellation focus), pension insurance usually not at all.
$existing = [];
$columnQuery = $db->query('PRAGMA table_info(insurances)');
while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $column['name'];
}
if (!in_array('cancel_mode', $existing)) {
    $db->exec("ALTER TABLE insurances ADD COLUMN cancel_mode TEXT DEFAULT 'cancellable'");
    $db->exec("UPDATE insurances SET cancel_mode = 'switch_only' WHERE insurance_type = 'health'");
    $db->exec("UPDATE insurances SET cancel_mode = 'not_cancellable' WHERE insurance_type = 'pension'");
}
