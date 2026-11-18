<?php
// Bausparvertrag: optional date of the first deposit when it differs from the contract start (e.g. start 08.01., first debit 30.01.).

$column = $db->query("SELECT * FROM pragma_table_info('loans') WHERE name='first_payment_date'");
if ($column->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec('ALTER TABLE loans ADD COLUMN first_payment_date TEXT');
}
