<?php
// API key check for the extension endpoints: parameter api_key or header X-API-Key.
// Sets $userId (and $GLOBALS['userId'] for the price helpers) or answers 401.

function apiAuthenticate(SQLite3 $db): int
{
    $apiKey = $_REQUEST['api_key'] ?? $_REQUEST['apiKey'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
    $stmt = $db->prepare("SELECT id FROM user WHERE api_key = :apiKey AND api_key != ''");
    $stmt->bindValue(':apiKey', (string) $apiKey);
    $user = $apiKey ? $stmt->execute()->fetchArray(SQLITE3_ASSOC) : false;
    if (!$user) {
        http_response_code(401);
        die(json_encode(['success' => false, 'title' => 'Invalid API key']));
    }
    $GLOBALS['userId'] = (int) $user['id'];
    return (int) $user['id'];
}
