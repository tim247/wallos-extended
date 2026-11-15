<?php
// All data of the logged-in user as ZIP: one CSV per table plus the uploaded documents.
// GET link, so no CSRF; needs a login.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/item_files.php';

if (empty($userId)) {
    http_response_code(403);
    exit;
}

$tables = ['subscriptions', 'contracts', 'insurances', 'loans', 'loan_extra_payments', 'loan_schedule_overrides',
    'meters', 'meter_readings', 'household', 'categories', 'payment_methods', 'currencies', 'price_history',
    'subscription_price_changes', 'item_files', 'audit_log', 'incomes', 'variable_expenses', 'savings_goals',
    'reminders', 'inventory', 'emergency_notes'];
$zipFile = tempnam(sys_get_temp_dir(), 'wallos_export_');
$zip = new ZipArchive();
if ($zipFile === false || $zip->open($zipFile, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit;
}

foreach ($tables as $table) {
    $stmt = @$db->prepare("SELECT * FROM $table WHERE user_id = :userId");
    if (!$stmt) {
        continue; // table missing on this installation
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $csv = fopen('php://temp', 'w+');
    fwrite($csv, "\xEF\xBB\xBF");
    $header = false;
    // A leading =, +, - or @ would run as a formula when the CSV is opened in Excel.
    $cell = fn($value) => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!$header) {
            fputcsv($csv, array_keys($row), ';');
            $header = true;
        }
        fputcsv($csv, array_map($cell, $row), ';');
    }
    rewind($csv);
    $zip->addFromString("$table.csv", stream_get_contents($csv));
    fclose($csv);
}

$stmt = $db->prepare('SELECT id, filename, stored_name FROM item_files WHERE user_id = :userId');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $path = itemFilesDir() . '/' . basename($row['stored_name']);
    if (is_file($path)) {
        $zip->addFile($path, 'files/' . $row['id'] . '-' . basename($row['filename']));
    }
}
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="wallos-export-' . date('Y-m-d') . '.zip"');
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: private, no-store');
readfile($zipFile);
unlink($zipFile);
