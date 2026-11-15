<?php
// Life admin: deadlines & maintenance (ID card, passport, TÜV, heating service ...),
// household inventory (for the contents insurance) and the emergency dossier notes.

$db->exec("CREATE TABLE IF NOT EXISTS reminders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    kind TEXT NOT NULL DEFAULT 'document',      -- document | maintenance
    category TEXT NOT NULL DEFAULT 'other',     -- id_card, passport, tuev, heating ... (see includes/life_admin.php)
    name TEXT NOT NULL,
    holder TEXT DEFAULT '',                     -- whose passport, which car
    due_date TEXT,                              -- next expiry / next service
    interval_months INTEGER DEFAULT 0,          -- 0 = one-off (renew by hand)
    notify_days_before INTEGER DEFAULT 60,
    last_done TEXT,
    notified_for TEXT,                          -- due_date already notified
    notes TEXT DEFAULT ''
)");
$db->exec("CREATE TABLE IF NOT EXISTS inventory (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    category TEXT DEFAULT '',
    location TEXT DEFAULT '',
    purchase_date TEXT,
    price REAL DEFAULT 0,                       -- purchase price in the main currency
    lifetime_years INTEGER DEFAULT 0,           -- 0 = no depreciation
    serial_number TEXT DEFAULT '',
    notes TEXT DEFAULT ''
)");
$db->exec("CREATE TABLE IF NOT EXISTS emergency_notes (
    user_id INTEGER PRIMARY KEY,
    notes TEXT NOT NULL DEFAULT '',
    updated_at TEXT
)");
