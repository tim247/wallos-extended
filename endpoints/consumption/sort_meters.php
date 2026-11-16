<?php
// Saves the order of the meters after drag and drop on the consumption page.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$data = json_decode(file_get_contents("php://input"), true);
$ids = array_values(array_filter(array_map('intval', (array) ($data['ids'] ?? []))));

$db->exec('BEGIN');
$stmt = $db->prepare('UPDATE meters SET sort_order = :position WHERE id = :id AND user_id = :userId');
foreach ($ids as $position => $id) {
    $stmt->bindValue(':position', $position + 1, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    $stmt->reset();
}
$db->exec('COMMIT');

echo json_encode(['success' => true, 'message' => translate('meter_order_saved', $i18n)]);
$db->close();
