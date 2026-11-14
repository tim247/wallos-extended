<?php
// Streams a document, preview or thumbnail from Paperless. GET (used for <img>
// and download links), so no CSRF token; it needs a logged-in session and only
// reads, using the token of the logged-in user.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/paperless.php';

$id = intval($_GET['id'] ?? 0);
$mode = $_GET['mode'] ?? '';
$cfg = $userId > 0 ? paperlessConfig($db, $userId) : null;
if ($cfg === null || $id <= 0 || !in_array($mode, ['thumb', 'preview', 'download'], true)) {
    http_response_code(404);
    exit;
}

$response = paperlessRequest($cfg, $userId, "/api/documents/$id/$mode/");
if ($response['status'] !== 200) {
    http_response_code($response['status'] >= 400 ? $response['status'] : 502);
    exit;
}

// Only serve types that cannot run scripts in our origin.
$type = strtolower(trim(explode(';', $response['type'])[0]));
$safe = $type === 'application/pdf' || in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . ($safe ? $type : 'application/octet-stream'));
$attachment = $mode === 'download' || !$safe;
if (preg_match('/filename="?([^";]+)"?/i', $response['disposition'], $m)) {
    header('Content-Disposition: ' . ($attachment ? 'attachment' : 'inline') . '; filename="' . str_replace(['"', "\r", "\n"], '', basename($m[1])) . '"');
} elseif ($attachment) {
    header('Content-Disposition: attachment');
}
header('Cache-Control: private, max-age=3600');
echo $response['body'];
