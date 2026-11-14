<?php
// This migration adds the meters and meter_readings tables for the
// consumption tracker (Strom, Wasser, Gas, PV-Einspeisung).
// Consumption is derived from the difference of consecutive readings.

$db->exec('CREATE TABLE IF NOT EXISTS meters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    meter_type TEXT NOT NULL DEFAULT "electricity",
    meter_number TEXT DEFAULT "",
    unit TEXT NOT NULL DEFAULT "kWh",
    price_per_unit REAL DEFAULT 0,
    base_fee_monthly REAL DEFAULT 0,
    conversion_factor REAL DEFAULT NULL,
    currency_id INTEGER NOT NULL DEFAULT 1,
    archived INTEGER DEFAULT 0,
    archived_at DATE DEFAULT NULL
)');

$db->exec('CREATE TABLE IF NOT EXISTS meter_readings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    meter_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    reading_date DATE NOT NULL,
    reading_value REAL NOT NULL,
    is_meter_change INTEGER DEFAULT 0,
    note TEXT DEFAULT "",
    UNIQUE(meter_id, reading_date)
)');

?>
