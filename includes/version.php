<?php
$version = "v5.11.1";
// Wallos Extended release (written by the release build into RELEASE). $version stays the
// upstream version for the update check; $assetVersion busts the browser cache on every release.
$releaseFile = __DIR__ . '/../RELEASE';
$extRelease = is_file($releaseFile) ? trim((string) file_get_contents($releaseFile)) : '';
$assetVersion = $extRelease !== '' ? preg_replace('/[^A-Za-z0-9._-]/', '', $extRelease) : $version . '-dev';
?>
