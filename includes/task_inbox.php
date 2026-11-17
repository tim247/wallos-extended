<?php
// Task inbox: every open item of Wallos Extended in one list, plus the notification centre.
// A task key contains what it is about (and its date), so a new occurrence shows again even
// after the previous one was marked done.

require_once __DIR__ . '/cancellation_helpers.php';
require_once __DIR__ . '/price_helpers.php';
require_once __DIR__ . '/insurance_insights.php';
require_once __DIR__ . '/consumption_insights.php';
require_once __DIR__ . '/financing_calculations.php';
require_once __DIR__ . '/emergency_entries.php';

/** Reminder types of Wallos Extended that can be switched off (notification centre). */
const NOTIFICATION_KINDS = [
    'deadline' => 'fa-hourglass-half',       // cancellation deadlines, trials, cancellation dates
    'insurance_review' => 'fa-magnifying-glass',
    'insurance_claims' => 'fa-file-medical',
    'kfz' => 'fa-car',
    'warranty' => 'fa-shield',
    'consumption' => 'fa-gauge',             // unusual consumption, tariff switch
    'financing' => 'fa-landmark',            // extra repayment allowance
    'emergency' => 'fa-kit-medical',         // dossier and directives reviews
];

function notificationEnabled(SQLite3 $db, int $userId, string $kind): bool
{
    $stmt = @$db->prepare('SELECT enabled FROM notification_prefs WHERE user_id = :userId AND kind = :kind');
    if (!$stmt) {
        return true;
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':kind', $kind);
    $row = $stmt->execute()->fetchArray(SQLITE3_NUM);
    return $row === false || (int) $row[0] === 1;
}

/**
 * Tasks to show: all collected ones minus "done" and minus "later" until their day.
 * @param array $tasks from collectTasks()
 */
function filterTasks(array $tasks, array $states, string $today): array
{
    return array_values(array_filter($tasks, function ($task) use ($states, $today) {
        $state = $states[$task['key']] ?? null;
        if ($state === null) {
            return true;
        }
        return $state['status'] === 'later' && ($state['until'] ?? '') <= $today;
    }));
}

/**
 * Collects the open items.
 * @return array<int, array{key: string, kind: string, icon: string, text: string, link: string, date: string}>
 */
function collectTasks(SQLite3 $db, int $userId, array $i18n, string $today): array
{
    $t = fn(string $key) => $i18n[$key] ?? $key;
    $rows = function (string $sql) use ($db, $userId): array {
        $stmt = @$db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $out = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $out[] = $row;
        }
        return $out;
    };
    $in = fn(int $days) => date('Y-m-d', strtotime($today . " +$days days"));
    $name = fn($row) => html_entity_decode((string) $row['name']);
    $tasks = [];
    $add = function (string $key, string $kind, string $icon, string $text, string $link, string $date) use (&$tasks) {
        $tasks[] = compact('key', 'kind', 'icon', 'text', 'link', 'date');
    };

    // Cancellation deadlines in the next 30 days.
    foreach (['contract' => ['contracts', 'contracts.php'], 'insurance' => ['insurances', 'insurance.php']] as $type => [$table, $link]) {
        foreach ($rows("SELECT * FROM $table WHERE user_id = :userId AND archived = 0 AND cancellation_status = 0") as $row) {
            $deadline = getNextCancellationDeadline($row, $today)['deadline'] ?? null;
            if ($deadline && $deadline <= $in(30)) {
                $add("deadline:$type:{$row['id']}:$deadline", 'deadline', 'fa-hourglass-half', sprintf($t('task_deadline'), $name($row)), $link, $deadline);
            }
        }
    }
    // Trials and cancellation dates of subscriptions.
    foreach ($rows("SELECT id, name, trial_end, cancellation_date FROM subscriptions WHERE user_id = :userId AND inactive = 0 AND archived = 0") as $row) {
        if (!empty($row['trial_end']) && $row['trial_end'] >= $today && $row['trial_end'] <= $in(14)) {
            $add("trial:{$row['id']}:{$row['trial_end']}", 'deadline', 'fa-hourglass-end', sprintf($t('task_trial'), $name($row)), 'subscriptions.php', $row['trial_end']);
        }
        if (!empty($row['cancellation_date']) && $row['cancellation_date'] >= $today && $row['cancellation_date'] <= $in(14)) {
            $add("cancel:{$row['id']}:{$row['cancellation_date']}", 'deadline', 'fa-ban', sprintf($t('task_cancel'), $name($row)), 'subscriptions.php', $row['cancellation_date']);
        }
    }
    // Deadlines & maintenance (ID cards, inspection ...).
    foreach ($rows("SELECT * FROM reminders WHERE user_id = :userId AND due_date IS NOT NULL") as $row) {
        if ($row['due_date'] <= $in((int) $row['notify_days_before'])) {
            $add("reminder:{$row['id']}:{$row['due_date']}", 'deadline', 'fa-calendar-check', sprintf($t('task_reminder'), $name($row)), 'reminders.php', $row['due_date']);
        }
    }
    // Insurances: reviews, open claims, motor switching time.
    $insurances = $rows("SELECT * FROM insurances WHERE user_id = :userId AND archived = 0 AND cancellation_status = 0");
    foreach ($insurances as $row) {
        if (insuranceReviewDue($row, $today)) {
            $add('review:' . $row['id'] . ':' . ($row['last_review'] ?: $row['start_date']), 'insurance_review', 'fa-magnifying-glass', sprintf($t('task_insurance_review'), $name($row)), 'insurance.php', $today);
        }
        if (motorSwitchReminderDue($row + ['kfz_reminded_year' => 0], $today)) {
            $add("kfz:{$row['id']}:" . substr($today, 0, 4), 'kfz', 'fa-car', sprintf($t('task_kfz'), $name($row)), 'insurance.php', substr($today, 0, 4) . '-11-30');
        }
    }
    foreach ($rows("SELECT c.id, c.claim_date, i.name FROM insurance_claims c JOIN insurances i ON i.id = c.insurance_id WHERE c.user_id = :userId AND c.status IN ('reported', 'in_review')") as $row) {
        if ($row['claim_date'] <= date('Y-m-d', strtotime($today . ' -28 days'))) {
            $add("claim:{$row['id']}", 'insurance_claims', 'fa-file-medical', sprintf($t('task_claim'), $name($row)), 'insurance.php', $row['claim_date']);
        }
    }
    // Warranties.
    foreach ($rows("SELECT id, name, warranty_until FROM inventory WHERE user_id = :userId AND warranty_until IS NOT NULL") as $row) {
        if ($row['warranty_until'] >= $today && $row['warranty_until'] <= $in(30)) {
            $add("warranty:{$row['id']}:{$row['warranty_until']}", 'warranty', 'fa-shield', sprintf($t('task_warranty'), $name($row)), 'inventory.php', $row['warranty_until']);
        }
    }
    // Consumption: unusual use and tariff switch hints.
    foreach ($rows("SELECT * FROM meters WHERE user_id = :userId AND archived = 0 AND meter_type NOT IN ('pv_feed_in', 'pv_generation')") as $row) {
        $segments = computeConsumptionSegments(loadMeterRows($db, 'meter_readings', (int) $row['id'], $userId, 'reading_date'))['segments'];
        if (($outlier = detectOutlier($segments)) !== null) {
            $add("outlier:{$row['id']}:{$outlier['to']}", 'consumption', 'fa-triangle-exclamation', sprintf($t('task_outlier'), $name($row)), 'consumption.php', $outlier['to']);
        }
        foreach (tariffSwitchHints($row, $today) as $hint) {
            $add("switch:{$row['id']}:{$hint['key']}:" . ($hint['date'] ?? $row['price_per_unit']), 'consumption', 'fa-right-left', sprintf($t('task_tariff_switch'), $name($row)), 'consumption.php', $hint['date'] ?? $today);
        }
    }
    // Financing: extra repayment allowance left at the end of the year.
    if (substr($today, 5, 2) >= '10') {
        foreach ($rows("SELECT * FROM loans WHERE user_id = :userId AND archived = 0 AND loan_type != 'bausparen'") as $row) {
            $allowance = extraRepaymentAllowance($row, loadLoanInputs($db, $userId, $row)['extras'], (int) substr($today, 0, 4));
            if ($allowance !== null && $allowance['left'] > 0) {
                $add("allowance:{$row['id']}:" . substr($today, 0, 4), 'financing', 'fa-landmark', sprintf($t('task_allowance'), $name($row)), 'financing.php?id=' . (int) $row['id'], substr($today, 0, 4) . '-12-31');
            }
        }
    }
    // Emergency dossier.
    $notes = $rows('SELECT * FROM emergency_notes WHERE user_id = :userId')[0] ?? [];
    $firstEntry = $db->querySingle('SELECT MIN(updated_at) FROM emergency_entries WHERE user_id = ' . (int) $userId);
    if (emergencyReviewDue($notes['reviewed_at'] ?? null, $firstEntry, $today)) {
        $add('dossier:' . (($notes['reviewed_at'] ?? null) ?: $firstEntry), 'emergency', 'fa-kit-medical', $t('task_dossier'), 'emergency.php', $today);
    }
    foreach (emergencyDirectivesDue(emergencyEntries($db, $userId)['directives'], $today) as $row) {
        $add("directive:{$row['id']}:{$row['updated_at']}", 'emergency', 'fa-file-contract', sprintf($t('task_directive'), $name($row)), 'emergency.php', $today);
    }

    usort($tasks, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $tasks;
}

/** Open tasks of a user after "done" / "later". */
function openTasks(SQLite3 $db, int $userId, array $i18n, string $today): array
{
    $states = [];
    $stmt = @$db->prepare('SELECT task_key, status, until FROM task_state WHERE user_id = :userId');
    if ($stmt) {
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $states[$row['task_key']] = $row;
        }
    }
    // The inbox always shows everything; the notification centre only decides about messages.
    return filterTasks(collectTasks($db, $userId, $i18n, $today), $states, $today);
}
