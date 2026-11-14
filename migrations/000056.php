<?php
// Meter advance payments + reading reminders, and fixed-interest-end reminders for loans.

$addColumns = function (string $table, array $columns) use ($db) {
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

$addColumns('meters', [
    'monthly_advance' => 'REAL DEFAULT 0',          // Abschlag per month
    'billing_start' => 'TEXT',                      // start of a billing year (Y-m-d)
    'reading_reminder_days' => 'INTEGER DEFAULT 0', // 0 = no reminder
    'last_reading_reminder' => 'TEXT',
]);

$addColumns('loans', [
    'fixed_interest_notified' => 'TEXT', // fixed_interest_end value already notified
]);
