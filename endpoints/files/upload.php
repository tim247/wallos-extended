<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/item_files.php';

header('Content-Type: application/json');
$type = (string) ($_POST['type'] ?? '');
$itemId = (int) ($_POST['item_id'] ?? 0);
$file = $_FILES['file'] ?? null;
$fail = fn($key) => die(json_encode(['success' => false, 'message' => translate($key, $i18n)]));

if (!itemFileOwns($db, $userId, $type, $itemId)) {
    $fail('error');
}
if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    $fail('file_upload_failed');
}
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($file['size'] > ITEM_FILE_MAX_BYTES || !in_array($ext, ITEM_FILE_EXTENSIONS, true)) {
    $fail('file_type_or_size');
}
$dir = itemFilesDir();
if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
    $fail('file_upload_failed');
}
$stored = bin2hex(random_bytes(16));
if (!move_uploaded_file($file['tmp_name'], "$dir/$stored")) {
    $fail('file_upload_failed');
}
$name = mb_substr(str_replace(['/', '\\', "\0", "\r", "\n", '"'], '_', basename($file['name'])), 0, 200);
$stmt = $db->prepare('INSERT INTO item_files (user_id, item_type, item_id, filename, stored_name, size) VALUES (:userId, :type, :itemId, :name, :stored, :size)');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':type', $type);
$stmt->bindValue(':itemId', $itemId, SQLITE3_INTEGER);
$stmt->bindValue(':name', $name);
$stmt->bindValue(':stored', $stored);
$stmt->bindValue(':size', (int) $file['size'], SQLITE3_INTEGER);
$stmt->execute();
echo json_encode(['success' => true, 'message' => translate('success', $i18n)]);
