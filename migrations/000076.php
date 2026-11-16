<?php
// Consumption insights: tariff history, supplier bills, PV generation and amortization,
// heating meters (degree days), household data for comparisons, outlier notifications.

$addColumns = function (string $table, array $columns) use ($db) {
    $existing = [];
    $columnQuery = $db->query("PRAGMA table_info($table)");
    while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
        $existing[] = $column['name'];
    }
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing)) {
            $db->exec("ALTER TABLE $table ADD COLUMN $name $definition");
        }
    }
};

$addColumns('meters', [
    'price_valid_from' => 'TEXT',              // the current price applies from this date (older ones in meter_tariffs)
    'is_heating' => 'INTEGER DEFAULT 0',       // heating meter: weather-adjusted analysis (gas is always heating)
    'investment_cost' => 'REAL DEFAULT 0',     // PV generation: purchase price of the system
    'commissioning_date' => 'TEXT',            // PV generation: start of operation
    'outlier_notified' => 'TEXT',              // end date of the last segment reported as unusually high
]);

// Earlier prices of a meter. A price applies from valid_from until the next one.
$db->exec('CREATE TABLE IF NOT EXISTS meter_tariffs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    meter_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    valid_from TEXT NOT NULL,
    price_per_unit REAL NOT NULL DEFAULT 0,
    base_fee_monthly REAL NOT NULL DEFAULT 0
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_meter_tariffs_meter ON meter_tariffs (meter_id, valid_from)');

// Annual bills of the supplier, checked against the own readings.
$db->exec('CREATE TABLE IF NOT EXISTS meter_bills (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    meter_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    period_from TEXT NOT NULL,
    period_to TEXT NOT NULL,
    units REAL NOT NULL DEFAULT 0,
    amount REAL NOT NULL DEFAULT 0,
    note TEXT DEFAULT ""
)');

// Household data for the comparison and the location for the weather data.
$db->exec('CREATE TABLE IF NOT EXISTS consumption_settings (
    user_id INTEGER PRIMARY KEY,
    persons INTEGER DEFAULT 0,
    living_area REAL DEFAULT 0,
    location_name TEXT DEFAULT "",
    latitude REAL,
    longitude REAL
)');

// Daily mean temperatures from Open-Meteo, cached per location (rounded to ~1 km).
$db->exec('CREATE TABLE IF NOT EXISTS weather_daily (
    location TEXT NOT NULL,
    day TEXT NOT NULL,
    temp_mean REAL,
    PRIMARY KEY (location, day)
)');
