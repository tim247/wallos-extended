<?php
// Prices of a contract, insurance or the Kfz-Steuer of a vehicle - one list for history, current price and
// the planned change. POST JSON: type, item_id, action (list|add|delete|delete_start|delete_pending),
// date + price for add, id for delete / delete_start.
//
// add decides by the date:
//   after today                    -> planned change (the item's pending price, applied on that day)
//   today or earlier, newest entry -> becomes the current price at once, dated in the history
//   older than the newest entry    -> a backdated history entry, the current price stays
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
// type => [table, price column, pending price column, pending date column]
$tables = [
    'contract' => ['contracts', 'price', 'pending_price', 'pending_price_date'],
    'insurance' => ['insurances', 'price', 'pending_price', 'pending_price_date'],
    'vehicle_tax' => ['vehicles', 'tax_amount', 'tax_pending_amount', 'tax_pending_date'],
];
$type = (string) ($data['type'] ?? '');
$itemId = intval($data['item_id'] ?? 0);
$action = (string) ($data['action'] ?? 'list');
$today = date('Y-m-d');
$fail = fn() => die(json_encode(['success' => false, 'message' => translate('fill_all_fields', $i18n)]));
if (!isset($tables[$type])) {
    $fail();
}
[$table, $priceCol, $pendingCol, $pendingDateCol] = $tables[$type];
$loadItem = function () use ($db, $table, $priceCol, $pendingCol, $pendingDateCol, $itemId, $userId) {
    $stmt = $db->prepare("SELECT $priceCol AS price, $pendingCol AS pending_price, $pendingDateCol AS pending_date FROM $table WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':id', $itemId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
};
if (!$loadItem()) {
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
// Sets the item's price. The table's trigger logs the change dated today: it gets $date, or is removed
// ($date null) when the change only follows the list (deleting the newest entry).
$setPrice = function (float $price, ?string $date) use ($db, $table, $priceCol, $type, $itemId, $userId) {
    $before = (int) $db->querySingle('SELECT COALESCE(MAX(id), 0) FROM price_history');
    $stmt = $db->prepare("UPDATE $table SET $priceCol = :price WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':price', $price, SQLITE3_FLOAT);
    $stmt->bindValue(':id', $itemId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    $sql = $date === null
        ? 'DELETE FROM price_history WHERE id > :before AND item_type = :type AND item_id = :id AND user_id = :userId'
        : 'UPDATE price_history SET changed_at = :date, notified = 1 WHERE id > :before AND item_type = :type AND item_id = :id AND user_id = :userId';
    $logged = $db->prepare($sql);
    if ($date !== null) {
        $logged->bindValue(':date', $date);
    }
    $logged->bindValue(':before', $before, SQLITE3_INTEGER);
    $logged->bindValue(':type', $type);
    $logged->bindValue(':id', $itemId, SQLITE3_INTEGER);
    $logged->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $logged->execute();
    // No trigger row (same price, or the first Kfz-Steuer after 0): the dated entry is written here.
    if ($date !== null && $db->changes() === 0) {
        $stmt = $db->prepare('INSERT INTO price_history (user_id, item_type, item_id, old_price, new_price, changed_at, notified) VALUES (:userId, :type, :id, :price, :price, :date, 1)');
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':id', $itemId, SQLITE3_INTEGER);
        $stmt->bindValue(':price', $price, SQLITE3_FLOAT);
        $stmt->bindValue(':date', $date);
        $stmt->execute();
    }
};
$setPending = function (?float $price, ?string $date) use ($db, $table, $pendingCol, $pendingDateCol, $itemId, $userId) {
    $stmt = $db->prepare("UPDATE $table SET $pendingCol = :price, $pendingDateCol = :date WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':price', $price, $price === null ? SQLITE3_NULL : SQLITE3_FLOAT);
    $stmt->bindValue(':date', $date, $date === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $stmt->bindValue(':id', $itemId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
};
$byId = function (string $sql) use ($db, $type, $itemId, $userId, $data) {
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':rowId', intval($data['id'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':type', $type);
    $stmt->bindValue(':id', $itemId, SQLITE3_INTEGER);
    $stmt->execute();
};

$changed = true;
if ($action === 'add') {
    $date = (string) ($data['date'] ?? '');
    $price = round((float) str_replace(',', '.', (string) ($data['price'] ?? '')), 2);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $price <= 0) {
        $fail();
    }
    $history = $rows();
    $newest = $history ? end($history)['changed_at'] : null;
    if ($date > $today) {
        $setPending($price, $date);
    } elseif ($newest === null || $date >= $newest) {
        $setPrice($price, $date);
    } else {
        // notified = 1: a backdated entry is no news, the price-increase mail skips it.
        $stmt = $db->prepare('INSERT INTO price_history (user_id, item_type, item_id, old_price, new_price, changed_at, notified) VALUES (:userId, :type, :id, :price, :price, :date, 1)');
        $stmt->bindValue(':price', $price, SQLITE3_FLOAT);
        $stmt->bindValue(':date', $date);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':id', $itemId, SQLITE3_INTEGER);
        $stmt->execute();
    }
} elseif ($action === 'delete_pending') {
    $setPending(null, null);
} elseif ($action === 'delete_start') {
    // The starting price ("before") is the old price of the first change: dropping it removes that step.
    $byId('UPDATE price_history SET old_price = new_price WHERE id = :rowId AND user_id = :userId AND item_type = :type AND item_id = :id');
} elseif ($action === 'delete') {
    $history = $rows();
    $wasNewest = $history && (int) end($history)['id'] === intval($data['id'] ?? 0);
    $byId('DELETE FROM price_history WHERE id = :rowId AND user_id = :userId AND item_type = :type AND item_id = :id');
    // The newest entry was the current price: the one before it is current again.
    $history = $rows();
    if ($wasNewest && $history) {
        $setPrice((float) end($history)['new_price'], null);
    }
} else {
    $changed = false;
}
if ($changed) {
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
$item = $loadItem();
$entries = [];
$history = $rows();
if ($history && (float) $history[0]['old_price'] !== (float) $history[0]['new_price']) {
    $entries[] = ['id' => null, 'start_of' => (int) $history[0]['id'], 'date' => null, 'price' => (float) $history[0]['old_price']];
}
foreach ($history as $row) {
    $entries[] = ['id' => (int) $row['id'], 'date' => $row['changed_at'], 'price' => (float) $row['new_price']];
}
if (!$entries || end($entries)['price'] !== (float) $item['price']) {
    $entries[] = ['id' => null, 'date' => null, 'current' => true, 'price' => (float) $item['price']];
}
if ($item['pending_date'] !== null && $item['pending_price'] !== null) {
    $entries[] = ['id' => null, 'pending' => true, 'date' => $item['pending_date'], 'price' => (float) $item['pending_price']];
}
echo json_encode(['success' => true, 'entries' => $entries, 'current' => (float) $item['price']]);
