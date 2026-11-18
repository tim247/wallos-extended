<?php
// Insurance tips a user hid (comma list of short hashes of the tip text, see insurance.php).

$column = $db->query("SELECT * FROM pragma_table_info('settings') WHERE name='insurance_hidden_hints'");
if ($column->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE settings ADD COLUMN insurance_hidden_hints TEXT DEFAULT ''");
}
