<?php
// This migration adds archiving support to subscriptions.
// "archived" is distinct from the existing "inactive" flag:
// inactive = disabled/replaced, archived = kept for history only.

$columnQuery = $db->query("PRAGMA table_info(subscriptions)");
$columns = [];
while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
    $columns[] = $column['name'];
}

if (!in_array('archived', $columns)) {
    $db->exec('ALTER TABLE subscriptions ADD COLUMN archived INTEGER DEFAULT 0');
}

if (!in_array('archived_at', $columns)) {
    $db->exec('ALTER TABLE subscriptions ADD COLUMN archived_at DATE DEFAULT NULL');
}

?>
