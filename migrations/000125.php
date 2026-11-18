<?php
// Dashboard panels a user switched off (comma list of panel keys, see includes/dashboard_panels.php).

$column = $db->query("SELECT * FROM pragma_table_info('settings') WHERE name='dashboard_hidden'");
if ($column->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE settings ADD COLUMN dashboard_hidden TEXT DEFAULT ''");
}
