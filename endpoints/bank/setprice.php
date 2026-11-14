<?php
// Bank statement check: adopt the charged amount as the new price of an item.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/price_helpers.php';

header('Content-Type: application/json');
$tables = ['subscription' => 'subscriptions', 'contract' => 'contracts', 'insurance' => 'insurances'];
$table = $tables[$_POST['type'] ?? ''] ?? null;
$id = (int) ($_POST['id'] ?? 0);
$booked = (float) ($_POST['booked'] ?? 0);
if (!$table || $id <= 0 || $booked <= 0) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}

$stmt = $db->prepare("SELECT price, currency_id FROM $table WHERE id = :id AND user_id = :userId");
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$converted = $row ? getPriceConverted((float) $row['price'], $row['currency_id'], $db, $userId) : 0;
if (!$row || $converted <= 0) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}

// The booking is in the main currency; scale the stored price in its own currency.
$newPrice = round($row['price'] * $booked / $converted, 2);
$stmt = $db->prepare("UPDATE $table SET price = :price WHERE id = :id AND user_id = :userId");
$stmt->bindValue(':price', $newPrice, SQLITE3_FLOAT);
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->execute();
echo json_encode(['success' => true, 'message' => translate('success', $i18n)]);
