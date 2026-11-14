<?php
// Price history (filled by triggers on every price change), uploaded documents
// per item, and the automatic backup setting.

$db->exec("CREATE TABLE IF NOT EXISTS price_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    item_type TEXT NOT NULL,
    item_id INTEGER NOT NULL,
    old_price REAL NOT NULL,
    new_price REAL NOT NULL,
    changed_at TEXT NOT NULL DEFAULT (date('now'))
)");

foreach (['subscription' => 'subscriptions', 'contract' => 'contracts', 'insurance' => 'insurances'] as $type => $table) {
    $db->exec("CREATE TRIGGER IF NOT EXISTS price_history_$table AFTER UPDATE OF price ON $table
        WHEN NEW.price <> OLD.price
        BEGIN
            INSERT INTO price_history (user_id, item_type, item_id, old_price, new_price)
            VALUES (NEW.user_id, '$type', NEW.id, OLD.price, NEW.price);
        END");
}

$db->exec("CREATE TABLE IF NOT EXISTS item_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    item_type TEXT NOT NULL,
    item_id INTEGER NOT NULL,
    filename TEXT NOT NULL,
    stored_name TEXT NOT NULL,
    size INTEGER NOT NULL DEFAULT 0,
    uploaded_at TEXT NOT NULL DEFAULT (datetime('now'))
)");

$columns = [];
$result = $db->query("PRAGMA table_info(admin)");
while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
    $columns[] = $column['name'];
}
if (!in_array('backup_keep', $columns)) {
    $db->exec("ALTER TABLE admin ADD COLUMN backup_keep INTEGER DEFAULT 14");
}
