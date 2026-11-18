<?php
// Deadlines & maintenance, inventory and emergency notes.
// POST JSON: kind (reminder|inventory|notes), action (save|delete|done), id and the fields.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/life_admin.php';
require_once '../../includes/item_files.php';
require_once '../../includes/household_extras.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$kind = $data['kind'] ?? '';
$action = $data['action'] ?? 'save';
$id = (int) ($data['id'] ?? 0);
$text = fn($key, $max = 200) => mb_substr(trim(strip_tags((string) ($data[$key] ?? ''))), 0, $max);
$date = fn($key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data[$key] ?? '')) ? (string) $data[$key] : null;
$reply = fn(bool $ok, string $key = 'success') => die(json_encode(['success' => $ok, 'message' => translate($ok ? $key : 'error', $i18n)]));
$bind = function (SQLite3Stmt $stmt, array $values) {
    foreach ($values as $key => $value) {
        $stmt->bindValue($key, $value, $value === null ? SQLITE3_NULL : (is_int($value) ? SQLITE3_INTEGER : (is_float($value) ? SQLITE3_FLOAT : SQLITE3_TEXT)));
    }
};

if ($kind === 'notes') {
    $stmt = $db->prepare("INSERT INTO emergency_notes (user_id, notes, updated_at) VALUES (:userId, :notes, datetime('now', 'localtime'))
        ON CONFLICT(user_id) DO UPDATE SET notes = excluded.notes, updated_at = excluded.updated_at");
    $bind($stmt, [':userId' => (int) $userId, ':notes' => mb_substr((string) ($data['notes'] ?? ''), 0, 20000)]);
    $reply((bool) $stmt->execute());
}

// Vehicles, their fuel log and children (vehicles.php, children.php).
$owns = function (string $table, int $rowId) use ($db, $userId, $bind): bool {
    $stmt = $db->prepare("SELECT 1 FROM $table WHERE id = :id AND user_id = :userId");
    $bind($stmt, [':id' => $rowId, ':userId' => (int) $userId]);
    return (bool) $stmt->execute()->fetchArray();
};
$money = fn($key) => max(0, round((float) str_replace(',', '.', (string) ($data[$key] ?? 0)), 2));
if (in_array($kind, ['vehicle', 'fuel', 'child'], true)) {
    $table = ['vehicle' => 'vehicles', 'fuel' => 'vehicle_fuel', 'child' => 'children'][$kind];
    if ($action === 'delete') {
        if ($kind === 'vehicle') {
            $stmt = $db->prepare('DELETE FROM vehicle_fuel WHERE vehicle_id = :id AND user_id = :userId');
            $bind($stmt, [':id' => $id, ':userId' => (int) $userId]);
            $stmt->execute();
        }
        $stmt = $db->prepare("DELETE FROM $table WHERE id = :id AND user_id = :userId");
        $bind($stmt, [':id' => $id, ':userId' => (int) $userId]);
        $reply((bool) $stmt->execute());
    }
    if ($kind === 'child' && $action === 'exam') {
        // Ticks a check-up off (or back on).
        $exam = (string) ($data['exam'] ?? '');
        $stmt = $db->prepare('SELECT exams_done FROM children WHERE id = :id AND user_id = :userId');
        $bind($stmt, [':id' => $id, ':userId' => (int) $userId]);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$row || !isset(CHILD_EXAMS[$exam])) {
            $reply(false);
        }
        $done = array_filter(explode(',', (string) $row['exams_done']));
        $done = in_array($exam, $done, true) ? array_diff($done, [$exam]) : [...$done, $exam];
        $stmt = $db->prepare('UPDATE children SET exams_done = :done WHERE id = :id AND user_id = :userId');
        $bind($stmt, [':done' => implode(',', $done), ':id' => $id, ':userId' => (int) $userId]);
        $reply((bool) $stmt->execute());
    }
    if ($kind === 'vehicle') {
        $name = $text('name');
        $insuranceId = (int) ($data['insurance_id'] ?? 0);
        $contractId = (int) ($data['contract_id'] ?? 0);
        $values = [
            ':name' => $name, ':plate' => $text('plate', 20), ':first' => $date('first_registration'),
            ':insurance' => $insuranceId > 0 && $owns('insurances', $insuranceId) ? $insuranceId : null,
            ':contract' => $contractId > 0 && $owns('contracts', $contractId) ? $contractId : null,
            ':notes' => $text('notes', 1000),
        ];
        $columns = 'name = :name, plate = :plate, first_registration = :first, insurance_id = :insurance, contract_id = :contract, notes = :notes';
        $insert = '(user_id, name, plate, first_registration, insurance_id, contract_id, notes) VALUES (:userId, :name, :plate, :first, :insurance, :contract, :notes)';
    } elseif ($kind === 'fuel') {
        $vehicleId = (int) ($data['vehicle_id'] ?? 0);
        $name = $date('fuel_date') ?? '';
        if ($name !== '' && !$owns('vehicles', $vehicleId)) {
            $reply(false);
        }
        $values = [':vehicle' => $vehicleId, ':date' => $name, ':liters' => $money('liters'), ':cost' => $money('cost'),
            ':mileage' => max(0, (int) ($data['mileage'] ?? 0))];
        $columns = 'vehicle_id = :vehicle, fuel_date = :date, liters = :liters, cost = :cost, mileage = :mileage';
        $insert = '(user_id, vehicle_id, fuel_date, liters, cost, mileage) VALUES (:userId, :vehicle, :date, :liters, :cost, :mileage)';
    } else {
        $memberId = (int) ($data['household_id'] ?? 0);
        $name = $owns('household', $memberId) ? 'ok' : '';
        $values = [':member' => $memberId, ':birth' => $date('birthdate'), ':benefit' => $money('child_benefit'),
            ':maintenance' => $money('maintenance'), ':care' => $money('care_costs'), ':savings' => $money('savings_rate'), ':notes' => $text('notes', 1000)];
        $columns = 'household_id = :member, birthdate = :birth, child_benefit = :benefit, maintenance = :maintenance, care_costs = :care, savings_rate = :savings, notes = :notes';
        $insert = '(user_id, household_id, birthdate, child_benefit, maintenance, care_costs, savings_rate, notes) VALUES (:userId, :member, :birth, :benefit, :maintenance, :care, :savings, :notes)';
    }
    if ($name === '') {
        die(json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]));
    }
    $stmt = $db->prepare($id > 0 ? "UPDATE $table SET $columns WHERE id = :id AND user_id = :userId" : "INSERT INTO $table $insert");
    $bind($stmt, $values + [':userId' => (int) $userId] + ($id > 0 ? [':id' => $id] : []));
    $reply((bool) $stmt->execute());
}

$table = ['reminder' => 'reminders', 'inventory' => 'inventory'][$kind] ?? null;
if (!$table) {
    $reply(false);
}

if ($action === 'delete') {
    if ($kind === 'inventory') {
        itemFilesDeleteFor($db, (int) $userId, 'inventory', $id);
    }
    $stmt = $db->prepare("DELETE FROM $table WHERE id = :id AND user_id = :userId");
    $bind($stmt, [':id' => $id, ':userId' => (int) $userId]);
    $reply((bool) $stmt->execute());
}

if ($action === 'done' && $kind === 'reminder') {
    // Done today: remember it and plan the next date from the interval.
    $stmt = $db->prepare('SELECT interval_months FROM reminders WHERE id = :id AND user_id = :userId');
    $bind($stmt, [':id' => $id, ':userId' => (int) $userId]);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) {
        $reply(false);
    }
    $today = date('Y-m-d');
    $next = reminderNextDue($today, (int) $row['interval_months']);
    $stmt = $db->prepare('UPDATE reminders SET last_done = :today, due_date = COALESCE(:next, due_date), notified_for = NULL WHERE id = :id AND user_id = :userId');
    $bind($stmt, [':today' => $today, ':next' => $next, ':id' => $id, ':userId' => (int) $userId]);
    $reply((bool) $stmt->execute(), 'reminder_done');
}

$name = $text('name');
if ($name === '') {
    die(json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]));
}
if ($kind === 'reminder') {
    $category = isset(REMINDER_CATEGORIES[$data['category'] ?? '']) ? $data['category'] : 'other';
    $values = [
        ':name' => $name,
        ':kind' => REMINDER_CATEGORIES[$category][0],
        ':category' => $category,
        ':holder' => $text('holder'),
        ':due' => $date('due_date'),
        ':interval' => max(0, min(600, (int) ($data['interval_months'] ?? 0))),
        ':notify' => max(0, min(730, (int) ($data['notify_days_before'] ?? 60))),
        ':notes' => $text('notes', 1000),
    ];
    $columns = 'name = :name, kind = :kind, category = :category, holder = :holder, due_date = :due, interval_months = :interval,
        notify_days_before = :notify, notes = :notes, notified_for = NULL';
    $insert = '(user_id, name, kind, category, holder, due_date, interval_months, notify_days_before, notes)
        VALUES (:userId, :name, :kind, :category, :holder, :due, :interval, :notify, :notes)';
} else {
    $values = [
        ':name' => $name,
        ':category' => $text('category', 100),
        ':location' => $text('location', 100),
        ':purchase' => $date('purchase_date'),
        ':price' => max(0, (float) ($data['price'] ?? 0)),
        ':lifetime' => max(0, min(100, (int) ($data['lifetime_years'] ?? 0))),
        ':serial' => $text('serial_number', 100),
        ':warranty' => $date('warranty_until'),
        ':notes' => $text('notes', 1000),
    ];
    $columns = 'name = :name, category = :category, location = :location, purchase_date = :purchase, price = :price,
        lifetime_years = :lifetime, serial_number = :serial, warranty_until = :warranty, notes = :notes,
        warranty_notified = CASE WHEN warranty_until IS :warranty THEN warranty_notified ELSE NULL END';
    $insert = '(user_id, name, category, location, purchase_date, price, lifetime_years, serial_number, warranty_until, notes)
        VALUES (:userId, :name, :category, :location, :purchase, :price, :lifetime, :serial, :warranty, :notes)';
}
$stmt = $db->prepare($id > 0 ? "UPDATE $table SET $columns WHERE id = :id AND user_id = :userId" : "INSERT INTO $table $insert");
$bind($stmt, $values + [':userId' => (int) $userId] + ($id > 0 ? [':id' => $id] : []));
$reply((bool) $stmt->execute());
