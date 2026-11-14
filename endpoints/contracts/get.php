<?php
require_once '../../includes/connect_endpoint.php';

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    if (isset($_GET['id']) && $_GET['id'] != "") {
        $contractId = intval($_GET['id']);
        $query = "SELECT * FROM contracts WHERE id = :contractId AND user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':contractId', $contractId, SQLITE3_INTEGER);
        $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['name'] = htmlspecialchars_decode($row['name'] ?? "");
            $row['provider'] = htmlspecialchars_decode($row['provider'] ?? "");
            $row['contract_number'] = htmlspecialchars_decode($row['contract_number'] ?? "");
            $row['notes'] = htmlspecialchars_decode($row['notes'] ?? "");
            $row['url'] = htmlspecialchars_decode($row['url'] ?? "");

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
