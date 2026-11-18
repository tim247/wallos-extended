<?php
// Dashboard panels for vehicles and children (index.php), shown when there is at least one.
// Expects $db, $userId, $i18n, $hiddenPanels and $currencies/$main_currency from the dashboard.
require_once __DIR__ . '/household_extras.php';
require_once __DIR__ . '/life_admin.php';
require_once __DIR__ . '/price_helpers.php';

$dashToday = date('Y-m-d');
$dashRows = function (string $sql) use ($db, $userId): array {
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $out = [];
    while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
        $out[] = $row;
    }
    return $out;
};
$dashFmt = fn($amount) => CurrencyFormatter::format($amount, $currencies[$main_currency]['code'] ?? 'EUR');
$dashDate = fn(?string $date) => $date ? date('d.m.Y', strtotime($date)) : '–';
$dashReminders = $dashRows('SELECT category, holder, due_date FROM reminders WHERE user_id = :userId AND due_date IS NOT NULL ORDER BY due_date');
$dashNext = function (array $holders, array $categories) use ($dashReminders): ?array {
    $holders = array_map(fn($h) => mb_strtolower(trim(html_entity_decode((string) $h))), array_filter($holders));
    foreach ($dashReminders as $row) {
        if (in_array($row['category'], $categories, true) && in_array(mb_strtolower(trim(html_entity_decode($row['holder']))), $holders, true)) {
            return $row;
        }
    }
    return null;
};

$dashVehicles = in_array('vehicles', $hiddenPanels, true) ? [] : $dashRows('SELECT * FROM vehicles WHERE user_id = :userId ORDER BY name COLLATE NOCASE');
if ($dashVehicles):
    $monthlyOf = [];
    foreach ([['insurances', 'insurance_id'], ['contracts', 'contract_id']] as [$table]) {
        foreach ($dashRows("SELECT id, price, cycle, frequency FROM $table WHERE user_id = :userId") as $row) {
            $monthlyOf[$table][(int) $row['id']] = getPricePerMonth($row['cycle'], $row['frequency'], (float) $row['price']);
        }
    }
    $fuel = [];
    foreach ($dashRows('SELECT * FROM vehicle_fuel WHERE user_id = :userId') as $row) {
        $fuel[(int) $row['vehicle_id']][] = $row;
    } ?>
    <div class="dashboard-panel" data-panel="vehicles">
        <h2><i class="fa-solid fa-car"></i> <?= translate('vehicles', $i18n) ?></h2>
        <ul class="upcoming-events">
            <?php foreach ($dashVehicles as $vehicle):
                $cost = vehicleFuelStats($fuel[(int) $vehicle['id']] ?? [], $dashToday)['monthly']
                    + ($monthlyOf['insurances'][(int) $vehicle['insurance_id']] ?? 0) + ($monthlyOf['contracts'][(int) $vehicle['contract_id']] ?? 0);
                $next = $dashNext([$vehicle['name'], $vehicle['plate']], VEHICLE_REMINDER_CATEGORIES); ?>
                <li class="upcoming-event"><a href="vehicles.php">
                    <span class="upcoming-event-date"><?= $next ? $dashDate($next['due_date']) : '' ?></span>
                    <span class="upcoming-event-icon"><i class="fa-solid <?= $next ? REMINDER_CATEGORIES[$next['category']][1] : 'fa-car' ?>"></i></span>
                    <span class="upcoming-event-name"><?= htmlspecialchars($vehicle['name']) ?><?= $next ? ' · ' . translate('reminder_cat_' . $next['category'], $i18n) : '' ?></span>
                    <span class="upcoming-event-detail"><?= $dashFmt($cost) ?> / <?= translate('month', $i18n) ?></span>
                </a></li>
            <?php endforeach; ?>
        </ul>
        <a class="dashboard-panel-link" href="vehicles.php"><?= translate('show_details', $i18n) ?> <i class="fa-solid fa-arrow-right"></i></a>
    </div>
<?php endif;

$dashChildren = in_array('children', $hiddenPanels, true) ? [] : $dashRows('SELECT c.*, h.name FROM children c JOIN household h ON h.id = c.household_id AND h.user_id = c.user_id WHERE c.user_id = :userId ORDER BY c.birthdate DESC');
if ($dashChildren): ?>
    <div class="dashboard-panel" data-panel="children">
        <h2><i class="fa-solid fa-child"></i> <?= translate('children', $i18n) ?></h2>
        <ul class="upcoming-events">
            <?php foreach ($dashChildren as $child):
                $exam = null;
                foreach (childExams($child['birthdate'], (string) $child['exams_done'], $dashToday) as $code => $candidate) {
                    if (in_array($candidate['state'], ['due', 'overdue', 'upcoming'], true)) {
                        $exam = [$code, $candidate];
                        break;
                    }
                }
                $doc = $dashNext([$child['name']], array_keys(array_filter(REMINDER_CATEGORIES, fn($c) => $c[0] === 'document'))); ?>
                <li class="upcoming-event <?= $exam && $exam[1]['state'] !== 'upcoming' ? 'warning' : '' ?>"><a href="children.php">
                    <span class="upcoming-event-date"><?= $exam ? $dashDate($exam[1]['from']) : ($doc ? $dashDate($doc['due_date']) : '') ?></span>
                    <span class="upcoming-event-icon"><i class="fa-solid <?= $exam ? 'fa-stethoscope' : 'fa-child' ?>"></i></span>
                    <span class="upcoming-event-name"><?= $child['name'] ?><?= $exam ? ' · ' . $exam[0] . ' ' . translate('child_exam_' . $exam[1]['state'], $i18n) : ($doc ? ' · ' . translate('reminder_cat_' . $doc['category'], $i18n) : '') ?></span>
                    <span class="upcoming-event-detail"><?= $dashFmt($child['child_benefit'] + $child['maintenance'] - $child['care_costs'] - $child['savings_rate']) ?> / <?= translate('month', $i18n) ?></span>
                </a></li>
            <?php endforeach; ?>
        </ul>
        <a class="dashboard-panel-link" href="children.php"><?= translate('show_details', $i18n) ?> <i class="fa-solid fa-arrow-right"></i></a>
    </div>
<?php endif;
