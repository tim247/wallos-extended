<?php
// Household members get a tag colour, chosen on the settings page.
if (!$db->querySingle("SELECT COUNT(*) FROM pragma_table_info('household') WHERE name = 'color'")) {
    $db->exec("ALTER TABLE household ADD COLUMN color TEXT DEFAULT ''");
}
