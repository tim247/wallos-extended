<?php
// Meters: forecast method - auto, weather (heating degree days), previous_year, recent (90-day average).

$column = $db->query("SELECT * FROM pragma_table_info('meters') WHERE name='forecast_method'");
if ($column->fetchArray(SQLITE3_ASSOC) === false) {
    $db->exec("ALTER TABLE meters ADD COLUMN forecast_method TEXT DEFAULT 'auto'");
}
