<?php
// Every 6 hours: newest Wallos Extended release on GitHub, shown as an update hint to the admin.

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/version.php';

$url = "https://api.github.com/repos/$extRepository/releases/latest";
$headers = ['User-Agent: Wallos-Extended', 'Accept: application/vnd.github+json'];
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true]);
    $fetch = curl_exec($ch);
    $fetch = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 ? $fetch : false;
    curl_close($ch);
} else {
    $context = stream_context_create(['http' => ['header' => implode("\r\n", $headers) . "\r\n", 'timeout' => 15]]);
    $fetch = @file_get_contents($url, false, $context);
}
if ($fetch === false) {
    die("Error fetching data from GitHub API\n");
}

$latestVersion = (string) (json_decode($fetch, true)['tag_name'] ?? '');
// Only accept tags in the release format (v5.11.1-ext.6), nothing else reaches the database.
if (!preg_match('/^v\d+\.\d+\.\d+(-ext\.\d+)?$/', $latestVersion)) {
    die("Error: Invalid version number from GitHub API\n");
}

$stmt = $db->prepare('UPDATE admin SET latest_version = :latest');
$stmt->bindValue(':latest', $latestVersion, SQLITE3_TEXT);
$stmt->execute();

echo extVersionCompare($latestVersion, $installedVersion) > 0
    ? "New version available: $latestVersion (installed: $installedVersion)\n"
    : "No new version available, currently on $installedVersion\n";
