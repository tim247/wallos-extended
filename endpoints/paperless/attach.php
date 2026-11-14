<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/paperless.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$type = (string) ($data['type'] ?? '');
$itemId = intval($data['item_id'] ?? 0);
$paperlessId = intval($data['paperless_id'] ?? 0);
$cfg = paperlessConfig($db, $userId);
if ($cfg === null || $paperlessId <= 0 || paperlessItem($db, $userId, $type, $itemId) === null) {
    paperlessError('error', $i18n);
}

// Look the document up instead of trusting the title sent by the browser.
$error = null;
$doc = paperlessJson($cfg, $userId, "/api/documents/$paperlessId/", ['fields' => 'id,title,created,correspondent'], $error);
if ($doc === null) {
    paperlessError(strpos($error, 'paperless_') === 0 ? $error : 'paperless_' . $error, $i18n);
}
$summary = paperlessSummary($doc, paperlessCorrespondents($cfg, $userId));

$stmt = $db->prepare("INSERT OR IGNORE INTO item_documents (user_id, item_type, item_id, paperless_id, title, doc_date, correspondent)
                      VALUES (:userId, :type, :itemId, :paperlessId, :title, :docDate, :correspondent)");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':type', $type, SQLITE3_TEXT);
$stmt->bindValue(':itemId', $itemId, SQLITE3_INTEGER);
$stmt->bindValue(':paperlessId', $paperlessId, SQLITE3_INTEGER);
$stmt->bindValue(':title', $summary['title'], SQLITE3_TEXT);
$stmt->bindValue(':docDate', $summary['date'], SQLITE3_TEXT);
$stmt->bindValue(':correspondent', $summary['correspondent'], SQLITE3_TEXT);
echo json_encode($stmt->execute()
    ? ['success' => true, 'message' => translate('paperless_attached', $i18n)]
    : ['success' => false, 'message' => translate('error', $i18n)]);
$db->close();
