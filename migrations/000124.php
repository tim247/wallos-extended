<?php
// Children: monthly savings rate the parents pay for the child (savings plan, child's account).

$column = $db->query("SELECT * FROM pragma_table_info('children') WHERE name='savings_rate'");
if ($column->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec('ALTER TABLE children ADD COLUMN savings_rate REAL DEFAULT 0');
}
