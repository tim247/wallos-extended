<?php
// Subscriptions: end of a free trial. Inventory: end of the warranty. Each with a "reminded" marker.
$add = function (string $table, array $columns) use ($db) {
    $existing = [];
    $columnQuery = $db->query("PRAGMA table_info($table)");
    while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
        $existing[] = $column['name'];
    }
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing)) {
            $db->exec("ALTER TABLE $table ADD COLUMN $name $definition");
        }
    }
};
$add('subscriptions', ['trial_end' => 'TEXT', 'trial_notified' => 'TEXT']);
$add('inventory', ['warranty_until' => 'TEXT', 'warranty_notified' => 'TEXT']);
