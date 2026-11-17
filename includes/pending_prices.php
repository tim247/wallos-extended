<?php
// Announced price changes of contracts and insurances ("new premium from 01.01.").
// Until the date the old price applies; from then on the new one, and the price history
// (filled by the price_history triggers) gets the date of the change, not the day it was applied.

const PENDING_PRICE_TABLES = ['contract' => 'contracts', 'insurance' => 'insurances'];

/**
 * Switches every announced price that is due. Runs from the daily cron and when the
 * pages open, so it is right without a cron, too. $userId = null: all users.
 * @return int number of switched prices
 */
function applyPendingPrices(SQLite3 $db, ?int $userId = null, ?string $today = null): int
{
    $today = $today ?? date('Y-m-d');
    $switched = 0;
    foreach (PENDING_PRICE_TABLES as $type => $table) {
        $sql = "SELECT id, user_id, price, pending_price, pending_price_date FROM $table
            WHERE pending_price IS NOT NULL AND pending_price_date IS NOT NULL AND pending_price_date <= :today"
            . ($userId !== null ? ' AND user_id = :userId' : '');
        $stmt = @$db->prepare($sql);
        if (!$stmt) {
            continue; // migration not run yet
        }
        $stmt->bindValue(':today', $today);
        if ($userId !== null) {
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        $due = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $due[] = $row;
        }
        foreach ($due as $row) {
            $update = $db->prepare("UPDATE $table SET price = :price, pending_price = NULL, pending_price_date = NULL WHERE id = :id AND user_id = :userId");
            $update->bindValue(':price', (float) $row['pending_price'], SQLITE3_FLOAT);
            $update->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $update->bindValue(':userId', $row['user_id'], SQLITE3_INTEGER);
            $update->execute();
            if (abs((float) $row['price'] - (float) $row['pending_price']) < 0.001) {
                $switched++;
                continue; // same price: the trigger wrote no history row
            }
            // The trigger dated the change today; it belongs to the announced date.
            $history = $db->prepare("UPDATE price_history SET changed_at = :date WHERE id = (SELECT MAX(id) FROM price_history
                WHERE item_type = :type AND item_id = :id AND user_id = :userId)");
            $history->bindValue(':date', $row['pending_price_date']);
            $history->bindValue(':type', $type);
            $history->bindValue(':id', $row['id'], SQLITE3_INTEGER);
            $history->bindValue(':userId', $row['user_id'], SQLITE3_INTEGER);
            $history->execute();
            $switched++;
        }
    }
    return $switched;
}

/**
 * Reads the announced price from a save request: [price|null, date|null].
 * Both or nothing - a price without a date (or the other way round) is ignored.
 */
function pendingPriceFromRequest(array $post): array
{
    $price = trim((string) ($post['pending_price'] ?? ''));
    $date = (string) ($post['pending_price_date'] ?? '');
    if ($price === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [null, null];
    }
    return [max(0.0, (float) str_replace(',', '.', $price)), $date];
}

/** Announced price increases of the user that are still ahead (dashboard). */
function announcedPriceIncreases(SQLite3 $db, int $userId, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $rows = [];
    foreach (PENDING_PRICE_TABLES as $type => $table) {
        $stmt = @$db->prepare("SELECT name, price AS old_price, pending_price AS new_price, pending_price_date AS changed_at, currency_id
            FROM $table WHERE user_id = :userId AND archived = 0 AND pending_price IS NOT NULL AND pending_price_date > :today AND pending_price > price");
        if (!$stmt) {
            continue;
        }
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':today', $today);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row + ['item_type' => $type, 'announced' => true];
        }
    }
    usort($rows, fn($a, $b) => strcmp($a['changed_at'], $b['changed_at']));
    return $rows;
}
