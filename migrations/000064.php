<?php
// Change log (triggers), read-only share link, off-site backup via WebDAV, budget notifications.

$addColumns = function (string $table, array $columns) use ($db) {
    $existing = [];
    $result = $db->query("PRAGMA table_info($table)");
    while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
        $existing[] = $column['name'];
    }
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing)) {
            $db->exec("ALTER TABLE $table ADD COLUMN $name $definition");
        }
    }
};
$addColumns('user', ['share_token' => 'TEXT']);
$addColumns('categories', ['budget_notified' => 'TEXT']); // Y-m of the last over-budget notification
$addColumns('admin', [
    'backup_webdav_url' => "TEXT DEFAULT ''",
    'backup_webdav_user' => "TEXT DEFAULT ''",
    'backup_webdav_password' => "TEXT DEFAULT ''",
]);

$db->exec("CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    item_type TEXT NOT NULL,
    item_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    name TEXT,
    detail TEXT,
    changed_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime'))
)");
$db->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_user ON audit_log (user_id, id)');

// Only changes a person makes count: the columns below, not next_payment & co. that cron jobs move.
$tracked = [
    'subscription' => ['subscriptions', 'price', ['name', 'price', 'cycle', 'frequency', 'inactive', 'archived']],
    'contract' => ['contracts', 'price', ['name', 'price', 'cycle', 'frequency', 'archived', 'cancellation_status', 'notice_period_months']],
    'insurance' => ['insurances', 'price', ['name', 'price', 'cycle', 'frequency', 'archived', 'cancellation_status', 'insurance_type']],
    'loan' => ['loans', 'monthly_rate', ['name', 'principal', 'interest_rate', 'monthly_rate', 'archived', 'fixed_interest_end']],
    'meter' => ['meters', 'price_per_unit', ['name', 'price_per_unit', 'base_fee_monthly', 'monthly_advance', 'archived']],
];
foreach ($tracked as $type => [$table, $amount, $columns]) {
    $changed = implode(' OR ', array_map(fn($c) => "OLD.$c IS NOT NEW.$c", $columns));
    $diff = implode(" || ", array_map(fn($c) => "CASE WHEN OLD.$c IS NOT NEW.$c THEN '$c: ' || IFNULL(OLD.$c, '') || ' → ' || IFNULL(NEW.$c, '') || '; ' ELSE '' END", $columns));
    $db->exec("DROP TRIGGER IF EXISTS audit_{$table}_insert");
    $db->exec("DROP TRIGGER IF EXISTS audit_{$table}_update");
    $db->exec("DROP TRIGGER IF EXISTS audit_{$table}_delete");
    $db->exec("CREATE TRIGGER audit_{$table}_insert AFTER INSERT ON $table BEGIN
        INSERT INTO audit_log (user_id, item_type, item_id, action, name, detail) VALUES (NEW.user_id, '$type', NEW.id, 'add', NEW.name, NEW.$amount);
    END");
    $db->exec("CREATE TRIGGER audit_{$table}_update AFTER UPDATE ON $table WHEN $changed BEGIN
        INSERT INTO audit_log (user_id, item_type, item_id, action, name, detail) VALUES (NEW.user_id, '$type', NEW.id, 'edit', NEW.name, $diff);
    END");
    $db->exec("CREATE TRIGGER audit_{$table}_delete AFTER DELETE ON $table BEGIN
        INSERT INTO audit_log (user_id, item_type, item_id, action, name, detail) VALUES (OLD.user_id, '$type', OLD.id, 'delete', OLD.name, NULL);
    END");
}
