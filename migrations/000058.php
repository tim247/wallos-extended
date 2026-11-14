<?php
// Cancellation letters: template, sender profile, provider address and status dates.
require_once __DIR__ . '/../includes/cancellation_letter.php';

$db->exec("CREATE TABLE IF NOT EXISTS cancellation_template (
    id INTEGER PRIMARY KEY,
    subject TEXT NOT NULL,
    body TEXT NOT NULL
)");
$default = cancellationDefaultTemplate();
$stmt = $db->prepare("INSERT OR IGNORE INTO cancellation_template (id, subject, body) VALUES (1, :subject, :body)");
$stmt->bindValue(':subject', $default['subject'], SQLITE3_TEXT);
$stmt->bindValue(':body', $default['body'], SQLITE3_TEXT);
$stmt->execute();

// Sender data is remembered per user, so it only has to be entered once.
$db->exec("CREATE TABLE IF NOT EXISTS letter_profiles (
    user_id INTEGER PRIMARY KEY,
    sender_name TEXT NOT NULL DEFAULT '',
    sender_address TEXT NOT NULL DEFAULT '',
    place TEXT NOT NULL DEFAULT ''
)");

foreach (['contracts', 'insurances'] as $table) {
    $existing = [];
    $columnQuery = $db->query("PRAGMA table_info($table)");
    while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
        $existing[] = $column['name'];
    }
    foreach (['provider_address' => 'TEXT', 'cancellation_sent_date' => 'TEXT', 'cancellation_confirmed_date' => 'TEXT'] as $name => $type) {
        if (!in_array($name, $existing)) {
            $db->exec("ALTER TABLE $table ADD COLUMN $name $type");
        }
    }
}
