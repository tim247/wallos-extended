<?php
// Year in review ("Life Wrapped"): what got cheaper, what was cancelled, the most expensive
// hobby (category) and an estimate of the hours freed up. Everything from data Wallos has.
require_once __DIR__ . '/price_helpers.php';

// ponytail: time saved is an estimate with fixed assumptions, shown on the page; tune here.
const WRAPPED_HOURS_PER_CANCELLATION = 2.0;   // calls, letters, checking bills of a contract per year
const WRAPPED_MINUTES_PER_AUTO_READING = 5;   // meter reading imported from Home Assistant
const WRAPPED_MINUTES_PER_DONE_REMINDER = 30; // looking up and remembering a deadline

function lifeWrapped(SQLite3 $db, int $userId, int $year): array
{
    $from = "$year-01-01";
    $to = ($year + 1) . '-01-01';
    $rows = function (string $sql) use ($db, $userId, $from, $to): array {
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':from', $from);
        $stmt->bindValue(':to', $to);
        $result = $stmt->execute();
        $out = [];
        while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
            $out[] = $row;
        }
        return $out;
    };
    $yearly = fn(array $row, float $price) => getPricePerMonth((int) $row['cycle'], (int) $row['frequency'],
        getPriceConverted($price, $row['currency_id'], $db, $userId)) * 12;

    // Cancelled = archived during the year.
    $cancelled = [];
    foreach (['subscriptions', 'contracts', 'insurances'] as $table) {
        foreach ($rows("SELECT name, price, cycle, frequency, currency_id FROM $table WHERE user_id = :userId AND archived = 1 AND archived_at >= :from AND archived_at < :to") as $row) {
            $cancelled[] = ['name' => $row['name'], 'yearly' => round($yearly($row, (float) $row['price']), 2)];
        }
    }
    usort($cancelled, fn($a, $b) => $b['yearly'] <=> $a['yearly']);

    // Price changes during the year, as a yearly effect.
    $down = $up = [];
    foreach ($rows("SELECT ph.old_price, ph.new_price, COALESCE(s.name, c.name, i.name) AS name,
            COALESCE(s.cycle, c.cycle, i.cycle) AS cycle, COALESCE(s.frequency, c.frequency, i.frequency) AS frequency,
            COALESCE(s.currency_id, c.currency_id, i.currency_id) AS currency_id
        FROM price_history ph
        LEFT JOIN subscriptions s ON ph.item_type = 'subscription' AND s.id = ph.item_id
        LEFT JOIN contracts c ON ph.item_type = 'contract' AND c.id = ph.item_id
        LEFT JOIN insurances i ON ph.item_type = 'insurance' AND i.id = ph.item_id
        WHERE ph.user_id = :userId AND ph.changed_at >= :from AND ph.changed_at < :to") as $row) {
        if ($row['name'] === null) {
            continue;
        }
        $effect = round($yearly($row, (float) $row['new_price'] - (float) $row['old_price']), 2);
        if ($effect < 0) {
            $down[] = ['name' => $row['name'], 'yearly' => -$effect];
        } elseif ($effect > 0) {
            $up[] = ['name' => $row['name'], 'yearly' => $effect];
        }
    }
    usort($down, fn($a, $b) => $b['yearly'] <=> $a['yearly']);

    // Most expensive "hobby": the category with the highest yearly cost of active subscriptions and contracts.
    $categories = [];
    foreach (['subscriptions' => 'inactive = 0 AND', 'contracts' => ''] as $table => $active) {
        foreach ($rows("SELECT t.price, t.cycle, t.frequency, t.currency_id, c.name AS category FROM $table t
            JOIN categories c ON c.id = t.category_id WHERE t.user_id = :userId AND $active t.archived = 0 AND t.category_id > 1") as $row) {
            $categories[$row['category']] = ($categories[$row['category']] ?? 0) + $yearly($row, (float) $row['price']);
        }
    }
    arsort($categories);
    $topCategory = $categories ? ['name' => array_key_first($categories), 'yearly' => round(reset($categories), 2)] : null;

    $count = fn(string $sql) => (int) ($rows($sql)[0]['n'] ?? 0);
    $autoReadings = $count("SELECT COUNT(*) AS n FROM meter_readings WHERE user_id = :userId AND note = 'Home Assistant' AND reading_date >= :from AND reading_date < :to");
    $remindersDone = $count("SELECT COUNT(*) AS n FROM reminders WHERE user_id = :userId AND last_done >= :from AND last_done < :to");
    $hours = count($cancelled) * WRAPPED_HOURS_PER_CANCELLATION
        + $autoReadings * WRAPPED_MINUTES_PER_AUTO_READING / 60
        + $remindersDone * WRAPPED_MINUTES_PER_DONE_REMINDER / 60;

    return [
        'year' => $year,
        'cancelled' => $cancelled,
        'cancelled_yearly' => round(array_sum(array_column($cancelled, 'yearly')), 2),
        'price_down' => $down,
        'price_down_yearly' => round(array_sum(array_column($down, 'yearly')), 2),
        'price_up_yearly' => round(array_sum(array_column($up, 'yearly')), 2),
        'price_up_count' => count($up),
        'top_category' => $topCategory,
        'auto_readings' => $autoReadings,
        'reminders_done' => $remindersDone,
        'inventory_added' => $count("SELECT COUNT(*) AS n FROM inventory WHERE user_id = :userId AND purchase_date >= :from AND purchase_date < :to"),
        'goals_reached' => $count("SELECT COUNT(*) AS n FROM savings_goals WHERE user_id = :userId AND target_amount > 0 AND saved_amount >= target_amount"),
        'hours_saved' => round($hours, 1),
    ];
}
