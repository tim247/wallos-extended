<?php
// This migration adds the contracts table (Verträge: gym, mobile, etc.)
// with cancellation reminder support and archiving.

$db->exec('CREATE TABLE IF NOT EXISTS contracts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    category_id INTEGER DEFAULT 1,
    provider TEXT DEFAULT "",
    contract_number TEXT DEFAULT "",
    price REAL NOT NULL DEFAULT 0,
    currency_id INTEGER NOT NULL DEFAULT 1,
    cycle INTEGER NOT NULL DEFAULT 3,
    frequency INTEGER NOT NULL DEFAULT 1,
    payment_method_id INTEGER DEFAULT 1,
    payer_user_id INTEGER DEFAULT 1,
    start_date DATE,
    initial_term_months INTEGER DEFAULT 12,
    renewal_term_months INTEGER DEFAULT 12,
    auto_renews INTEGER DEFAULT 1,
    end_date DATE DEFAULT NULL,
    notice_period_months INTEGER DEFAULT 3,
    notice_period_days INTEGER DEFAULT 0,
    notify INTEGER DEFAULT 0,
    notify_days_before INTEGER DEFAULT 30,
    cancellation_status INTEGER DEFAULT 0,
    last_notified_deadline DATE DEFAULT NULL,
    notes TEXT DEFAULT "",
    url TEXT DEFAULT "",
    archived INTEGER DEFAULT 0,
    archived_at DATE DEFAULT NULL
)');

?>
