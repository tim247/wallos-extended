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
