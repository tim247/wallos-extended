<?php
// Update check now follows the Wallos Extended releases: forget the stored upstream version
// (e.g. v5.12.0 of the original would otherwise show as an update until the next check).
$db->exec("UPDATE admin SET latest_version = NULL WHERE latest_version IS NOT NULL AND latest_version NOT LIKE '%-ext.%'");
