<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/paperless.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$query = trim($data['q'] ?? '');
$cfg = paperlessConfig($db, $userId);
if ($cfg === null || $query === '') {
    paperlessError('paperless_not_configured', $i18n);
}

$error = null;
$docs = paperlessJson($cfg, $userId, '/api/documents/', [
    'title_content' => $query,
    'page_size' => 15,
    'ordering' => '-created',
    'fields' => 'id,title,created,correspondent',
], $error);
if ($docs === null) {
    paperlessError(strpos($error, 'paperless_') === 0 ? $error : 'paperless_' . $error, $i18n);
}

$correspondents = paperlessCorrespondents($cfg, $userId);
echo json_encode([
    'success' => true,
    'documents' => array_map(fn($d) => paperlessSummary($d, $correspondents), $docs['results'] ?? []),
]);
$db->close();
