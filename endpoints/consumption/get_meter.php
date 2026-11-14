<?php
require_once '../../includes/connect_endpoint.php';

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    if (isset($_GET['id']) && $_GET['id'] != "") {
        $meterId = intval($_GET['id']);
        $stmt = $db->prepare("SELECT * FROM meters WHERE id = :meterId AND user_id = :userId");
        $stmt->bindParam(':meterId', $meterId, SQLITE3_INTEGER);
        $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['name'] = htmlspecialchars_decode($row['name'] ?? "");
            $row['meter_number'] = htmlspecialchars_decode($row['meter_number'] ?? "");
            header('Content-Type: application/json');
            echo json_encode($row);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['error' => translate('error', $i18n)]);
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => translate('error', $i18n)]);
    }
}
$db->close();
?>
