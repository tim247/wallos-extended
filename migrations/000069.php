<?php
// Home Assistant as a notification channel: the notify service to call (notify.<service>).
$hasColumn = false;
$result = $db->query('PRAGMA table_info(homeassistant_settings)');
while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
    $hasColumn = $hasColumn || $column['name'] === 'notify_service';
}
if (!$hasColumn) {
    $db->exec("ALTER TABLE homeassistant_settings ADD COLUMN notify_service TEXT NOT NULL DEFAULT ''");
}
