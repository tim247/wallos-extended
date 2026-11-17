<?php
// Vehicles (with fuel log, linked motor insurance and leasing/finance contract) and
// children (Kindergeld, maintenance, childcare costs, U check-ups). Deadlines of both
// (TÜV, service, ID card, passport) stay in the reminders table, matched by the holder.

$db->exec("CREATE TABLE IF NOT EXISTS vehicles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    plate TEXT DEFAULT '',
    first_registration TEXT,
    insurance_id INTEGER,                       -- motor insurance (insurances.id)
    contract_id INTEGER,                        -- leasing / finance contract (contracts.id)
    notes TEXT DEFAULT ''
)");
$db->exec("CREATE TABLE IF NOT EXISTS vehicle_fuel (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    vehicle_id INTEGER NOT NULL,
    fuel_date TEXT NOT NULL,
    liters REAL DEFAULT 0,                      -- litres or kWh
    cost REAL DEFAULT 0,
    mileage INTEGER DEFAULT 0
)");
$db->exec("CREATE TABLE IF NOT EXISTS children (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    household_id INTEGER NOT NULL,              -- the household member this child is
    birthdate TEXT,
    child_benefit REAL DEFAULT 0,               -- Kindergeld per month
    maintenance REAL DEFAULT 0,                 -- maintenance received per month
    care_costs REAL DEFAULT 0,                  -- childcare per month
    exams_done TEXT DEFAULT '',                 -- comma list of done check-ups (U2,U3 ...)
    notes TEXT DEFAULT ''
)");
