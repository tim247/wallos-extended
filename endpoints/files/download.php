<?php
// GET (used by links), so no CSRF token; needs the logged-in owner.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/item_files.php';

$file = !empty($userId) ? itemFileGet($db, (int) $userId, (int) ($_GET['id'] ?? 0)) : null;
$path = $file ? itemFilesDir() . '/' . basename($file['stored_name']) : '';
if (!$file || !is_file($path)) {
    http_response_code(404);
    exit;
}
// Only types that cannot run scripts in our origin are shown inline.
$inlineTypes = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
$inline = isset($inlineTypes[$ext]) && empty($_GET['download']);
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . ($inlineTypes[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $file['filename'] . '"; filename*=UTF-8\'\'' . rawurlencode($file['filename']));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
