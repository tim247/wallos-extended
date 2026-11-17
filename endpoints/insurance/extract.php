<?php
// Policy data (number, premium, start, term) read out of the documents of an insurance:
// uploaded PDF/text files and linked Paperless-ngx documents (their OCR text).
// Only suggestions - the form fills empty fields, the user saves.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/item_files.php';
require_once '../../includes/paperless.php';
require_once '../../includes/insurance_insights.php';
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$insuranceId = intval($data['insurance_id'] ?? $data['item_id'] ?? 0);
$itemType = ($data['type'] ?? 'insurance') === 'contract' ? 'contract' : 'insurance'; // contracts use it, too
if (!itemFileOwns($db, $userId, $itemType, $insuranceId)) {
    die(json_encode(['success' => false, 'message' => translate('error', $i18n)]));
}

$texts = [];
// Uploaded files (newest first; PDFs without a text layer give nothing).
$stmt = $db->prepare("SELECT filename, stored_name FROM item_files WHERE user_id = :userId AND item_type = :type AND item_id = :id ORDER BY uploaded_at DESC");
$stmt->bindValue(':type', $itemType);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':id', $insuranceId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $path = itemFilesDir() . '/' . basename($row['stored_name']);
    $ext = strtolower(pathinfo($row['filename'], PATHINFO_EXTENSION));
    if (!is_file($path) || filesize($path) > ITEM_FILE_MAX_BYTES) {
        continue;
    }
    if ($ext === 'pdf') {
        $texts[] = pdfPlainText((string) file_get_contents($path));
    } elseif (in_array($ext, ['txt', 'eml'], true)) {
        $texts[] = (string) file_get_contents($path);
    }
}
// Paperless documents: their full text is already there.
$cfg = paperlessConfig($db, $userId);
if ($cfg !== null) {
    $stmt = $db->prepare("SELECT paperless_id FROM item_documents WHERE user_id = :userId AND item_type = :type AND item_id = :id ORDER BY doc_date DESC LIMIT 5");
    $stmt->bindValue(':type', $itemType);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $insuranceId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $doc = paperlessJson($cfg, $userId, '/api/documents/' . (int) $row['paperless_id'] . '/', ['fields' => 'content']);
        if (!empty($doc['content'])) {
            $texts[] = (string) $doc['content'];
        }
    }
}

// The first document that names a value wins (newest first).
$found = [];
foreach ($texts as $text) {
    $found += extractPolicyData($text);
}
echo json_encode([
    'success' => true,
    'data' => $found,
    'message' => $found ? translate('extract_found', $i18n) : translate($texts ? 'extract_nothing' : 'extract_no_documents', $i18n),
]);
$db->close();
