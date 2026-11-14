<?php
// Price increase notifications and the tax-deductible flag.

$columns = fn(string $table) => array_column(iterator_to_array((function () use ($db, $table) {
    $result = $db->query("PRAGMA table_info($table)");
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        yield $row;
    }
})(), false), 'name');

if (!in_array('notified', $columns('price_history'))) {
    $db->exec('ALTER TABLE price_history ADD COLUMN notified INTEGER DEFAULT 0');
    // Changes recorded before this version were already shown on the dashboard.
    $db->exec('UPDATE price_history SET notified = 1');
}
foreach (['contracts', 'insurances'] as $table) {
    if (!in_array('tax_deductible', $columns($table))) {
        $db->exec("ALTER TABLE $table ADD COLUMN tax_deductible INTEGER DEFAULT 0");
    }
}
