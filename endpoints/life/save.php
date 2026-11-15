<?php
// Deadlines & maintenance, inventory and emergency notes.
// POST JSON: kind (reminder|inventory|notes), action (save|delete|done), id and the fields.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/life_admin.php';
require_once '../../includes/item_files.php';

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
        ':notes' => $text('notes', 1000),
    ];
    $columns = 'name = :name, category = :category, location = :location, purchase_date = :purchase, price = :price,
        lifetime_years = :lifetime, serial_number = :serial, notes = :notes';
    $insert = '(user_id, name, category, location, purchase_date, price, lifetime_years, serial_number, notes)
        VALUES (:userId, :name, :category, :location, :purchase, :price, :lifetime, :serial, :notes)';
}
$stmt = $db->prepare($id > 0 ? "UPDATE $table SET $columns WHERE id = :id AND user_id = :userId" : "INSERT INTO $table $insert");
$bind($stmt, $values + [':userId' => (int) $userId] + ($id > 0 ? [':id' => $id] : []));
$reply((bool) $stmt->execute());
