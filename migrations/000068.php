<?php
// Inactivity guard ("dead man's switch"): when the owner has not been active for a set number
// of days and ignored the reminders, a trusted person gets a link to the emergency dossier.

$hasColumn = false;
$result = $db->query('PRAGMA table_info(user)');
while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
    $hasColumn = $hasColumn || $column['name'] === 'last_seen';
}
if (!$hasColumn) {
    $db->exec('ALTER TABLE user ADD COLUMN last_seen TEXT'); // Y-m-d of the last page view
}

$db->exec("CREATE TABLE IF NOT EXISTS deadman_settings (
    user_id INTEGER PRIMARY KEY,
    enabled INTEGER NOT NULL DEFAULT 0,
    days INTEGER NOT NULL DEFAULT 90,
    trusted_name TEXT NOT NULL DEFAULT '',
    trusted_email TEXT NOT NULL DEFAULT '',
    message TEXT NOT NULL DEFAULT '',
    access_code_hash TEXT,          -- password_hash() of the code the trusted person gets in person
    triggered_at TEXT,              -- when the e-mail went out (NULL = armed)
    access_token TEXT,              -- secret part of the link, NULL = no access
    token_expires TEXT,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    last_warning TEXT               -- date of the last reminder to the owner
)");
