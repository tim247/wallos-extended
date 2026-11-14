<?php
// Loan schedule overrides: user-defined payment amounts for specific periods.
$db->exec("CREATE TABLE IF NOT EXISTS loan_schedule_overrides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    loan_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    period_n INTEGER NOT NULL,
    payment_amount REAL NOT NULL,
    UNIQUE(loan_id, period_n)
)");
