<?php
// Insurances: claims, coverage notes, contact, insured persons, motor data, review date.

$existing = [];
$columnQuery = $db->query('PRAGMA table_info(insurances)');
while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $column['name'];
}
foreach ([
    'coverage_notes' => "TEXT DEFAULT ''",   // key benefits and exclusions
    'contact_name' => "TEXT DEFAULT ''",     // broker / contact person
    'contact_phone' => "TEXT DEFAULT ''",
    'contact_email' => "TEXT DEFAULT ''",
    'insured_persons' => "TEXT DEFAULT ''",  // household member ids, comma separated; empty = the payer
    'kfz_sf_class' => "TEXT DEFAULT ''",     // Schadenfreiheitsklasse
    'kfz_km' => 'INTEGER DEFAULT 0',         // kilometres per year
    'kfz_type_class' => "TEXT DEFAULT ''",
    'kfz_region_class' => "TEXT DEFAULT ''",
    'kfz_reminded_year' => 'INTEGER DEFAULT 0',
    'last_review' => 'TEXT',                 // coverage last checked
    'review_notified' => 'TEXT',
] as $name => $definition) {
    if (!in_array($name, $existing)) {
        $db->exec("ALTER TABLE insurances ADD COLUMN $name $definition");
    }
}

$db->exec('CREATE TABLE IF NOT EXISTS insurance_claims (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    insurance_id INTEGER NOT NULL,
    claim_date TEXT NOT NULL,
    description TEXT DEFAULT "",
    claim_number TEXT DEFAULT "",
    amount_claimed REAL DEFAULT 0,
    amount_paid REAL DEFAULT 0,
    status TEXT NOT NULL DEFAULT "reported",
    status_changed TEXT,
    open_notified INTEGER DEFAULT 0
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_insurance_claims_insurance ON insurance_claims (insurance_id)');
