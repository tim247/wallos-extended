<?php
// Vehicles (vehicles.php) and children (children.php): fuel statistics and the U check-ups.

/** Reminder categories that belong to a vehicle (matched by holder = name or plate). */
const VEHICLE_REMINDER_CATEGORIES = ['tuev', 'car_service', 'oil_change', 'tires'];

/**
 * Fuel log statistics. Consumption and cost per km need two fill-ups with a mileage:
 * the first fill-up only marks the start, what was filled after it was driven.
 * @param array $fuel rows with fuel_date, liters, cost, mileage
 * @return array{mileage: int, per100: ?float, cost_per_km: ?float, monthly: float}
 */
function vehicleFuelStats(array $fuel, string $today): array
{
    $withKm = array_values(array_filter($fuel, fn($r) => (int) $r['mileage'] > 0));
    usort($withKm, fn($a, $b) => $a['mileage'] <=> $b['mileage']);
    $per100 = $perKm = null;
    if (count($withKm) >= 2) {
        $km = $withKm[count($withKm) - 1]['mileage'] - $withKm[0]['mileage'];
        $after = array_slice($withKm, 1);
        if ($km > 0) {
            $per100 = round(array_sum(array_column($after, 'liters')) / $km * 100, 1);
            $perKm = round(array_sum(array_column($after, 'cost')) / $km, 2);
        }
    }
    // Fuel cost per month: the last twelve months.
    $since = date('Y-m-d', strtotime($today . ' -1 year'));
    $yearCost = array_sum(array_map(fn($r) => $r['fuel_date'] > $since ? (float) $r['cost'] : 0, $fuel));
    return [
        'mileage' => $withKm ? (int) $withKm[count($withKm) - 1]['mileage'] : 0,
        'per100' => $per100,
        'cost_per_km' => $perKm,
        'monthly' => round($yearCost / 12, 2),
    ];
}

/** German child check-ups: code => [window opens, window closes] after birth. */
/** Next Kfz-Steuer debit on or after today: the stored date moved on by whole years (it is debited yearly). */
function vehicleTaxNextDue(?string $due, string $today): ?string
{
    if (!$due) {
        return null;
    }
    $date = new DateTime($due);
    while ($date->format('Y-m-d') < $today) {
        $date->modify('+1 year');
    }
    return $date->format('Y-m-d');
}

/**
 * Takes over announced Kfz-Steuer amounts whose date has come: the trigger records the change in the
 * price history, which then gets the announced date instead of today.
 */
function vehicleTaxApplyPending(SQLite3 $db, int $userId, string $today): void
{
    $stmt = $db->prepare('SELECT id, tax_pending_amount, tax_pending_date FROM vehicles WHERE user_id = :userId AND tax_pending_date IS NOT NULL AND tax_pending_date <= :today');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':today', $today);
    $result = $stmt->execute();
    $due = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $due[] = $row;
    }
    foreach ($due as $row) {
        $before = (int) $db->querySingle('SELECT COALESCE(MAX(id), 0) FROM price_history');
        $update = $db->prepare('UPDATE vehicles SET tax_amount = :amount, tax_pending_amount = NULL, tax_pending_date = NULL WHERE id = :id AND user_id = :userId');
        $update->bindValue(':amount', (float) $row['tax_pending_amount'], SQLITE3_FLOAT);
        $update->bindValue(':id', $row['id'], SQLITE3_INTEGER);
        $update->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $update->execute();
        $dated = $db->prepare("UPDATE price_history SET changed_at = :date WHERE id > :before AND item_type = 'vehicle_tax' AND item_id = :id");
        $dated->bindValue(':date', $row['tax_pending_date']);
        $dated->bindValue(':before', $before, SQLITE3_INTEGER);
        $dated->bindValue(':id', $row['id'], SQLITE3_INTEGER);
        $dated->execute();
    }
}

const CHILD_EXAMS = [
    'U2' => ['+3 days', '+10 days'],
    'U3' => ['+28 days', '+35 days'],
    'U4' => ['+3 months', '+4 months'],
    'U5' => ['+6 months', '+7 months'],
    'U6' => ['+10 months', '+12 months'],
    'U7' => ['+21 months', '+24 months'],
    'U7a' => ['+34 months', '+36 months'],
    'U8' => ['+46 months', '+48 months'],
    'U9' => ['+60 months', '+64 months'],
    'J1' => ['+12 years', '+14 years'],
];

/**
 * Check-ups with their window and state: done, due (window open), overdue (closed in the
 * last six months, not done), upcoming, past (long gone - not nagged about).
 * @return array<string, array{from: string, to: string, state: string}>
 */
function childExams(?string $birthdate, string $done, string $today): array
{
    if (!$birthdate) {
        return [];
    }
    $doneList = array_filter(explode(',', $done));
    $nagUntil = date('Y-m-d', strtotime($today . ' -6 months'));
    $exams = [];
    foreach (CHILD_EXAMS as $code => [$open, $close]) {
        $from = date('Y-m-d', strtotime($birthdate . ' ' . $open));
        $to = date('Y-m-d', strtotime($birthdate . ' ' . $close));
        $state = in_array($code, $doneList, true) ? 'done'
            : ($from <= $today && $today <= $to ? 'due'
            : ($to < $today ? ($to >= $nagUntil ? 'overdue' : 'past') : 'upcoming'));
        $exams[$code] = ['from' => $from, 'to' => $to, 'state' => $state];
    }
    return $exams;
}

/** Age in full years. */
function childAge(string $birthdate, string $today): int
{
    return (int) date_diff(date_create($birthdate), date_create($today))->y;
}
