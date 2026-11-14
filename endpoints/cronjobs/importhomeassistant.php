<?php
// Daily: store today's reading of every meter that has a Home Assistant sensor.

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require_once __DIR__ . '/../../includes/homeassistant.php';

require 'settimezone.php';

$date = new DateTime('now');
echo "\n" . $date->format('Y-m-d') . " " . $date->format('H:i:s') . "<br />\n";

$users = $db->query("SELECT user_id FROM homeassistant_settings WHERE enabled = 1");
$userIds = [];
while ($row = $users->fetchArray(SQLITE3_ASSOC)) {
    $userIds[] = (int) $row['user_id'];
}

foreach ($userIds as $userId) {
    $cfg = homeAssistantConfig($db, $userId);
    if ($cfg === null) {
        continue;
    }
    $stmt = $db->prepare("SELECT * FROM meters WHERE user_id = :userId AND archived = 0 AND ha_entity_id != ''");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $meters = [];
    while ($meter = $result->fetchArray(SQLITE3_ASSOC)) {
        $meters[] = $meter;
    }
    foreach ($meters as $meter) {
        $import = homeAssistantImportReading($db, $userId, $meter, $cfg);
        echo "User $userId, meter '" . $meter['name'] . "' (" . $meter['ha_entity_id'] . "): " . $import['status']
            . (isset($import['value']) ? " " . $import['value'] : '')
            . (isset($import['error']) ? " (" . $import['error'] . ")" : '') . "<br />\n";
    }
}

echo "Home Assistant import finished";
