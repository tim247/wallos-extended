<?php
// Paperless-ngx integration: read-only API client and document link helpers.
// Wallos never writes to Paperless; it only searches and fetches documents.

const PAPERLESS_ITEM_TABLES = ['contract' => 'contracts', 'insurance' => 'insurances', 'loan' => 'loans'];

/** Connection settings of a user, or null when not configured / disabled. */
function paperlessConfig(SQLite3 $db, int $userId): ?array
{
    $stmt = $db->prepare("SELECT url, token FROM paperless_settings WHERE user_id = :userId AND enabled = 1");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row || $row['url'] === '' || $row['token'] === '') {
        return null;
    }
    return $row;
}

/** Number of attached documents per item id, for the badge on the cards. */
function paperlessDocCounts(SQLite3 $db, int $userId, string $type): array
{
    $stmt = $db->prepare("SELECT item_id, COUNT(*) AS n FROM item_documents WHERE user_id = :userId AND item_type = :type GROUP BY item_id");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':type', $type, SQLITE3_TEXT);
    $result = $stmt->execute();
    $counts = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $counts[(int) $row['item_id']] = (int) $row['n'];
    }
    return $counts;
}

/** The item as stored for this user, or null (also validates the type). */
function paperlessItem(SQLite3 $db, int $userId, string $type, int $itemId): ?array
{
    if (!isset(PAPERLESS_ITEM_TABLES[$type])) {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM " . PAPERLESS_ITEM_TABLES[$type] . " WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':id', $itemId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
}

/**
 * Checks the target of a Paperless URL. Paperless usually runs in the home
 * network, so private addresses are allowed - but only for the admin (user 1),
 * like the webhook notifications. Link-local addresses (cloud metadata) are
 * always refused. Returns ['host','ip','port'] or an error string.
 */
function paperlessCheckUrl(string $url, int $userId)
{
    $parts = parse_url($url);
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!$parts || !isset($parts['host']) || !in_array($scheme, ['http', 'https'], true)) {
        return 'invalid_url';
    }
    $host = $parts['host'];
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return 'host_not_resolved';
    }
    if (strpos($ip, '169.254.') === 0) {
        return 'address_not_allowed';
    }
    $isPrivate = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($isPrivate && $userId !== 1) {
        return 'private_address_admin_only';
    }
    return ['host' => $host, 'ip' => $ip, 'port' => $parts['port'] ?? ($scheme === 'https' ? 443 : 80)];
}

/**
 * GET request against the Paperless API with token authentication.
 * @return array{status:int, body:string, type:string, disposition:string, error:string}
 */
/** $jsonBody: POST it as JSON instead of a GET (Home Assistant notify services). */
function paperlessRequest(array $cfg, int $userId, string $path, array $query = [], string $authScheme = 'Token', ?array $jsonBody = null): array
{
    $result = ['status' => 0, 'body' => '', 'type' => '', 'disposition' => '', 'error' => ''];
    $base = rtrim($cfg['url'], '/');
    $target = paperlessCheckUrl($base, $userId);
    if (is_string($target)) {
        $result['error'] = $target;
        return $result;
    }

    $url = $base . $path . ($query ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_MAXFILESIZE => 50 * 1024 * 1024,
        // Pin the address we validated, so DNS cannot change between check and request.
        CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $target['ip']],
        CURLOPT_HTTPHEADER => ['Authorization: ' . $authScheme . ' ' . $cfg['token'], 'Accept: application/json'],
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$result) {
            if (stripos($line, 'content-type:') === 0) {
                $result['type'] = trim(substr($line, 13));
            } elseif (stripos($line, 'content-disposition:') === 0) {
                $result['disposition'] = trim(substr($line, 20));
            }
            return strlen($line);
        },
    ]);
    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $authScheme . ' ' . $cfg['token'], 'Accept: application/json', 'Content-Type: application/json']);
    }
    $body = curl_exec($ch);
    if ($body === false) {
        $result['error'] = 'connection_failed';
    } else {
        $result['body'] = $body;
        $result['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    }
    curl_close($ch);
    return $result;
}

/** JSON of a successful API call, or null (with the error in $error). */
function paperlessJson(array $cfg, int $userId, string $path, array $query = [], ?string &$error = null): ?array
{
    $response = paperlessRequest($cfg, $userId, $path, $query);
    if ($response['error'] !== '') {
        $error = $response['error'];
        return null;
    }
    if ($response['status'] === 401 || $response['status'] === 403) {
        $error = 'paperless_auth_failed';
        return null;
    }
    $data = json_decode($response['body'], true);
    if ($response['status'] !== 200 || !is_array($data)) {
        $error = 'paperless_unexpected_response';
        return null;
    }
    return $data;
}

/** id => name of all correspondents (names are shown next to documents). */
function paperlessCorrespondents(array $cfg, int $userId): array
{
    $data = paperlessJson($cfg, $userId, '/api/correspondents/', ['page_size' => 1000, 'fields' => 'id,name']);
    $names = [];
    foreach (($data['results'] ?? []) as $c) {
        $names[(int) $c['id']] = (string) $c['name'];
    }
    return $names;
}

/** Compact document description for the frontend. */
function paperlessSummary(array $doc, array $correspondents): array
{
    return [
        'id' => (int) $doc['id'],
        'title' => (string) ($doc['title'] ?? ''),
        'date' => substr((string) ($doc['created'] ?? ''), 0, 10),
        'correspondent' => $correspondents[(int) ($doc['correspondent'] ?? 0)] ?? '',
    ];
}

function paperlessError(string $key, array $i18n): void
{
    echo json_encode(['success' => false, 'message' => translate($key, $i18n)]);
    exit;
}
