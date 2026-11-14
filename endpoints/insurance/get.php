<?php
require_once '../../includes/connect_endpoint.php';

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    if (isset($_GET['id']) && $_GET['id'] != "") {
        $insuranceId = intval($_GET['id']);
        $query = "SELECT * FROM insurances WHERE id = :insuranceId AND user_id = :userId";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':insuranceId', $insuranceId, SQLITE3_INTEGER);
        $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();

        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $row['name'] = htmlspecialchars_decode($row['name'] ?? "");
            $row['insurer'] = htmlspecialchars_decode($row['insurer'] ?? "");
            $row['policy_number'] = htmlspecialchars_decode($row['policy_number'] ?? "");
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
