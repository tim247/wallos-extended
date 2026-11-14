<?php
// Home Assistant: connection per user and a sensor per meter.

$db->exec("CREATE TABLE IF NOT EXISTS homeassistant_settings (
    user_id INTEGER PRIMARY KEY,
    url TEXT NOT NULL DEFAULT '',
    token TEXT NOT NULL DEFAULT '',
    enabled INTEGER NOT NULL DEFAULT 1
)");

$existing = [];
$columnQuery = $db->query("PRAGMA table_info(meters)");
while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $column['name'];
}
if (!in_array('ha_entity_id', $existing)) {
    $db->exec("ALTER TABLE meters ADD COLUMN ha_entity_id TEXT DEFAULT ''");
}
