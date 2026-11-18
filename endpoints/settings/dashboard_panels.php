<?php
// Saves which dashboard panels are switched off. POST JSON: hidden = list of panel keys.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/dashboard_panels.php';

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$hidden = array_values(array_intersect(array_keys(DASHBOARD_PANELS), (array) ($data['hidden'] ?? [])));
$stmt = $db->prepare('UPDATE settings SET dashboard_hidden = :hidden WHERE user_id = :userId');
$stmt->bindValue(':hidden', implode(',', $hidden));
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
die(json_encode(['success' => (bool) $stmt->execute(), 'message' => translate('success', $i18n)]));
