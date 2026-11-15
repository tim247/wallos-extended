<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';

$data = json_decode(file_get_contents("php://input"), true);
$keep = max(0, min(365, (int) ($data['keep'] ?? 14)));
$url = trim((string) ($data['webdav_url'] ?? ''));
if ($url !== '' && !preg_match('#^https?://#i', $url)) {
    die(json_encode(["success" => false, "message" => translate('error', $i18n) . ': WebDAV URL']));
}

$password = (string) ($data['webdav_password'] ?? '');
// Empty password field = keep the stored one; removing the URL forgets it.
$passwordSql = $url === '' ? ", backup_webdav_password = ''" : ($password !== '' ? ', backup_webdav_password = :password' : '');
$stmt = $db->prepare("UPDATE admin SET backup_keep = :keep, backup_webdav_url = :url, backup_webdav_user = :user$passwordSql");
$stmt->bindValue(':keep', $keep, SQLITE3_INTEGER);
$stmt->bindValue(':url', $url);
$stmt->bindValue(':user', trim((string) ($data['webdav_user'] ?? '')));
if (str_contains($passwordSql, ':password')) {
    $stmt->bindValue(':password', $password);
}
die(json_encode([
    "success" => (bool) $stmt->execute(),
    "message" => translate('success', $i18n)
]));
