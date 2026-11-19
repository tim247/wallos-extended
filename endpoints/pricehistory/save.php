<?php
// Price history of a contract or insurance: list, add a backdated price, delete an entry.
// POST JSON: type (contract|insurance), item_id, action (list|add|delete), date + price for add, id for delete.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
// type => [table, price column]; vehicle_tax is the yearly Kfz-Steuer of a vehicle.
$tables = ['contract' => ['contracts', 'price'], 'insurance' => ['insurances', 'price'], 'vehicle_tax' => ['vehicles', 'tax_amount']];
$type = (string) ($data['type'] ?? '');
$itemId = intval($data['item_id'] ?? 0);
$action = (string) ($data['action'] ?? 'list');
$fail = fn() => die(json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]));
if (!isset($tables[$type])) {
    $fail();
}
$stmt = $db->prepare("SELECT {$tables[$type][1]} AS price FROM {$tables[$type][0]} WHERE id = :id AND user_id = :userId");
$stmt->bindValue(':id', $itemId, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$item = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!$item) {
    $fail();
}

$rows = function () use ($db, $type, $itemId, $userId): array {
    $stmt = $db->prepare('SELECT id, old_price, new_price, changed_at FROM price_history WHERE user_id = :userId AND item_type = :type AND item_id = :id ORDER BY changed_at, id');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':type', $type);
    $stmt->bindValue(':id', $itemId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $out = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $out[] = $row;
    }
    return $out;
};

if ($action === 'add') {
    $date = (string) ($data['date'] ?? '');
    $price = round((float) str_replace(',', '.', (string) ($data['price'] ?? '')), 2);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d') || $price <= 0) {
        $fail();
    }
    // notified = 1: a backdated entry is no news, the price-increase mail skips it.
    $stmt = $db->prepare('INSERT INTO price_history (user_id, item_type, item_id, old_price, new_price, changed_at, notified) VALUES (:userId, :type, :id, :price, :price, :date, 1)');
    $stmt->bindValue(':price', $price, SQLITE3_FLOAT);
    $stmt->bindValue(':date', $date);
} elseif ($action === 'delete') {
    $stmt = $db->prepare('DELETE FROM price_history WHERE id = :rowId AND user_id = :userId AND item_type = :type AND item_id = :id');
    $stmt->bindValue(':rowId', intval($data['id'] ?? 0), SQLITE3_INTEGER);
} else {
    $stmt = null;
}
if ($stmt !== null) {
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':type', $type);
    $stmt->bindValue(':id', $itemId, SQLITE3_INTEGER);
    $stmt->execute();
    // Keep the chain consistent: each entry's old price is the price before it. The first entry keeps its own.
    $fix = $db->prepare('UPDATE price_history SET old_price = :old WHERE id = :rowId');
    $previous = null;
    foreach ($rows() as $row) {
        if ($previous !== null && (float) $row['old_price'] !== (float) $previous) {
            $fix->bindValue(':old', $previous, SQLITE3_FLOAT);
            $fix->bindValue(':rowId', $row['id'], SQLITE3_INTEGER);
            $fix->execute();
        }
        $previous = (float) $row['new_price'];
    }
}

// The price before the first change counts as the starting price, so a single change still shows its step.
$entries = [];
$history = $rows();
if ($history && (float) $history[0]['old_price'] !== (float) $history[0]['new_price']) {
    $entries[] = ['id' => null, 'date' => null, 'price' => (float) $history[0]['old_price']];
}
foreach ($history as $row) {
    $entries[] = ['id' => (int) $row['id'], 'date' => $row['changed_at'], 'price' => (float) $row['new_price']];
}
if ($entries && end($entries)['price'] !== (float) $item['price']) {
    $entries[] = ['id' => null, 'date' => null, 'current' => true, 'price' => (float) $item['price']];
}
echo json_encode(['success' => true, 'entries' => $entries, 'current' => (float) $item['price']]);
