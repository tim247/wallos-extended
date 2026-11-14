<?php

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';

require 'settimezone.php';

$date = new DateTime('now');
echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";

$today = $date->format('Y-m-d');

// Apply announced price changes whose effective date has been reached.
$query = "SELECT * FROM subscription_price_changes WHERE applied = 0 AND effective_date <= :today";
$stmt = $db->prepare($query);
$stmt->bindValue(':today', $today, SQLITE3_TEXT);
$result = $stmt->execute();

$appliedCount = 0;
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $updateStmt = $db->prepare("UPDATE subscriptions SET price = :price, currency_id = :currencyId WHERE id = :subscriptionId AND user_id = :userId");
    $updateStmt->bindValue(':price', $row['price'], SQLITE3_FLOAT);
    $updateStmt->bindValue(':currencyId', $row['currency_id'], SQLITE3_INTEGER);
    $updateStmt->bindValue(':subscriptionId', $row['subscription_id'], SQLITE3_INTEGER);
    $updateStmt->bindValue(':userId', $row['user_id'], SQLITE3_INTEGER);
    $updateStmt->execute();

    $markStmt = $db->prepare("UPDATE subscription_price_changes SET applied = 1 WHERE id = :id");
    $markStmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
    $markStmt->execute();

    $appliedCount++;
}

echo "Applied $appliedCount price change(s).<br />\n";

$db->close();
