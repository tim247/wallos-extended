<?php
// Contracts and insurances get a logo like subscriptions (file name in images/uploads/logos/).

foreach (['contracts', 'insurances'] as $table) {
    $column = $db->query("SELECT * FROM pragma_table_info('$table') WHERE name='logo'");
    if ($column->fetchArray(SQLITE3_ASSOC) === false) {
        $db->exec("ALTER TABLE $table ADD COLUMN logo TEXT DEFAULT ''");
    }
}
