<?php
// Add loan_type, interest-only, and Bausparvertrag fields to loans table.
if (!$db->exec("ALTER TABLE loans ADD COLUMN loan_type TEXT DEFAULT 'annuity'")) {
    if (!str_contains($db->lastErrorMsg() ?? '', 'duplicate column')) {
        error_log('Migration 000054: ' . $db->lastErrorMsg());
    }
}
foreach ([
    "ALTER TABLE loans ADD COLUMN interest_only_months INTEGER DEFAULT 0",
    "ALTER TABLE loans ADD COLUMN bausparen_loan_rate REAL DEFAULT 0",
    "ALTER TABLE loans ADD COLUMN bausparen_loan_monthly REAL DEFAULT 0",
    "ALTER TABLE loans ADD COLUMN bausparen_min_savings_pct REAL DEFAULT 40",
] as $sql) {
    if (!$db->exec($sql)) {
        $err = $db->lastErrorMsg() ?? '';
        if (!str_contains($err, 'duplicate column')) {
            error_log('Migration 000054: ' . $err);
        }
    }
}
