<?php
// This migration adds the loans table (Finanzierungen) and the
// loan_extra_payments table (Sondertilgungen). The amortization
// schedule is computed on the fly, not stored.

$db->exec('CREATE TABLE IF NOT EXISTS loans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    lender TEXT DEFAULT "",
    principal REAL NOT NULL,
    interest_rate REAL NOT NULL,
    monthly_rate REAL NOT NULL,
    start_date DATE NOT NULL,
    term_months INTEGER DEFAULT NULL,
    fixed_interest_end DATE DEFAULT NULL,
    currency_id INTEGER NOT NULL DEFAULT 1,
    notes TEXT DEFAULT "",
    archived INTEGER DEFAULT 0,
    archived_at DATE DEFAULT NULL
)');

$db->exec('CREATE TABLE IF NOT EXISTS loan_extra_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    loan_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    payment_date DATE NOT NULL,
    amount REAL NOT NULL,
    notes TEXT DEFAULT ""
)');

?>
