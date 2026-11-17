<?php
// Emergency dossier: entries per section (contacts, directives, accounts, digital estate, pets,
// places, medical), review date and reminders, a second trusted person with a limited scope.

$db->exec('CREATE TABLE IF NOT EXISTS emergency_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    section TEXT NOT NULL,
    name TEXT NOT NULL,
    info TEXT DEFAULT "",
    detail TEXT DEFAULT "",
    updated_at TEXT,
    notified TEXT
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_emergency_entries_user ON emergency_entries (user_id, section)');

$add = function (string $table, array $columns) use ($db) {
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
$add('emergency_notes', [
    'reviewed_at' => 'TEXT',                 // dossier last checked
    'review_notified' => 'TEXT',
    'medical_in_dossier' => 'INTEGER DEFAULT 0', // health data only on explicit opt-in
]);
$add('deadman_settings', [
    'trusted2_name' => "TEXT DEFAULT ''",
    'trusted2_email' => "TEXT DEFAULT ''",
    'trusted2_scope' => "TEXT DEFAULT 'limited'", // limited: notes, contacts, directives, places, pets, checklist
    'access_code2_hash' => 'TEXT',
    'access_token2' => 'TEXT',
    'token2_expires' => 'TEXT',
    'failed_attempts2' => 'INTEGER DEFAULT 0',
]);
