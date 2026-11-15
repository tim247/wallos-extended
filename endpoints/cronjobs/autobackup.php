<?php
// Daily: consistent copy of the database to db/backups/, keeps the newest N (admin: backup_keep, 0 = off).

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';

$keep = (int) ($db->querySingle("SELECT backup_keep FROM admin WHERE id = 1") ?? 14);
if ($keep <= 0) {
    echo "Automatic backup disabled\n";
    exit;
}

$dir = __DIR__ . '/../../db/backups';
if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
    echo "Cannot create $dir\n";
    exit(1);
}
$file = $dir . '/wallos-' . date('Ymd-His') . '.db';
$stmt = $db->prepare('VACUUM INTO :file'); // consistent even while the app writes
$stmt->bindValue(':file', $file, SQLITE3_TEXT);
if (!$stmt->execute()) {
    echo "Backup failed: " . $db->lastErrorMsg() . "\n";
    exit(1);
}
$files = glob($dir . '/wallos-*.db') ?: [];
rsort($files);
foreach (array_slice($files, $keep) as $old) {
    unlink($old);
}
echo date('Y-m-d H:i:s') . " Backup written: " . basename($file) . " (" . min(count($files), $keep) . " kept)\n";

// Off-site copy (Nextcloud, NAS, ...): PUT the backup to a WebDAV folder when configured.
$webdav = $db->querySingle("SELECT backup_webdav_url, backup_webdav_user, backup_webdav_password FROM admin WHERE id = 1", true);
$webdavUrl = rtrim((string) ($webdav['backup_webdav_url'] ?? ''), '/');
if ($webdavUrl !== '' && preg_match('#^https?://#i', $webdavUrl)) {
    $handle = fopen($file, 'rb');
    $curl = curl_init($webdavUrl . '/' . basename($file));
    curl_setopt_array($curl, [
        CURLOPT_PUT => true,
        CURLOPT_INFILE => $handle,
        CURLOPT_INFILESIZE => filesize($file),
        CURLOPT_USERPWD => $webdav['backup_webdav_user'] . ':' . $webdav['backup_webdav_password'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
    ]);
    curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    fclose($handle);
    echo $status >= 200 && $status < 300
        ? "Uploaded to WebDAV (HTTP $status)\n"
        : "WebDAV upload failed: HTTP $status $error\n";
}
