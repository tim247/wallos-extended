<?php
// This migration adds the subscription_price_changes table to track
// the price history of subscriptions, including announced future
// price increases (applied = 0 until effective_date is reached).
// It backfills one initial row per existing subscription.

$tableQuery = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='subscription_price_changes'");
$tableRequired = $tableQuery->fetchArray(SQLITE3_ASSOC) === false;

if ($tableRequired) {
    $db->exec('CREATE TABLE subscription_price_changes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        subscription_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        price REAL NOT NULL,
        currency_id INTEGER NOT NULL,
        effective_date DATE NOT NULL,
        applied INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    // Backfill: one row per existing subscription with its current price,
    // effective from its start date (or today when no start date is set).
    $db->exec("INSERT INTO subscription_price_changes (subscription_id, user_id, price, currency_id, effective_date, applied)
               SELECT id, user_id, price, currency_id, COALESCE(start_date, date('now')), 1 FROM subscriptions");
}

?>
