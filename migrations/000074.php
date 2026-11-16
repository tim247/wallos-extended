<?php
// Bausparvertrag: Abschlussgebühr, charged at the start, so the savings balance starts below zero.
if (!$db->querySingle("SELECT COUNT(*) FROM pragma_table_info('loans') WHERE name = 'bausparen_fee'")) {
    $db->exec("ALTER TABLE loans ADD COLUMN bausparen_fee REAL DEFAULT 0");
}
