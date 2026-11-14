<?php
// Suggests documents for an item without any manual linking:
//  1. documents of a Paperless correspondent that matches the provider (or the item name)
//  2. documents tagged "wallos-<type>-<id>" (e.g. wallos-contract-3)
//  3. documents that mention the contract / policy number
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/paperless.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$type = (string) ($data['type'] ?? '');
$itemId = intval($data['item_id'] ?? 0);
$cfg = paperlessConfig($db, $userId);
$item = paperlessItem($db, $userId, $type, $itemId);
if ($cfg === null || $item === null) {
    paperlessError('paperless_not_configured', $i18n);
}

$providerField = ['contract' => 'provider', 'insurance' => 'insurer', 'loan' => 'lender'][$type];
$numberField = ['contract' => 'contract_number', 'insurance' => 'policy_number', 'loan' => null][$type];
$fields = 'id,title,created,correspondent';
$found = []; // paperless id => document

$add = function (?array $docs) use (&$found) {
    foreach (($docs['results'] ?? []) as $doc) {
        $found[(int) $doc['id']] = $found[(int) $doc['id']] ?? $doc;
    }
};

// 1. Correspondent by provider name, falling back to the item name.
$names = array_values(array_unique(array_filter([trim((string) $item[$providerField]), trim((string) $item['name'])])));
$correspondentIds = [];
foreach ($names as $name) {
    $list = paperlessJson($cfg, $userId, '/api/correspondents/', ['name__icontains' => $name, 'page_size' => 5, 'fields' => 'id']);
    foreach (($list['results'] ?? []) as $c) {
        $correspondentIds[(int) $c['id']] = true;
    }
}
if ($correspondentIds) {
    $add(paperlessJson($cfg, $userId, '/api/documents/', [
        'correspondent__id__in' => implode(',', array_keys($correspondentIds)),
        'ordering' => '-created', 'page_size' => 20, 'fields' => $fields,
    ]));
}

// 2. Tag convention.
$tags = paperlessJson($cfg, $userId, '/api/tags/', ['name__iexact' => "wallos-$type-$itemId", 'page_size' => 1, 'fields' => 'id']);
if (!empty($tags['results'])) {
    $add(paperlessJson($cfg, $userId, '/api/documents/', [
        'tags__id__all' => (int) $tags['results'][0]['id'],
        'ordering' => '-created', 'page_size' => 20, 'fields' => $fields,
    ]));
}

// 3. Contract / policy number in title or content.
$number = $numberField ? trim((string) $item[$numberField]) : '';
if ($number !== '') {
    $add(paperlessJson($cfg, $userId, '/api/documents/', [
        'title_content' => $number, 'ordering' => '-created', 'page_size' => 10, 'fields' => $fields,
    ]));
}

// Hide what is already attached.
$stmt = $db->prepare("SELECT paperless_id FROM item_documents WHERE user_id = :userId AND item_type = :type AND item_id = :itemId");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':type', $type, SQLITE3_TEXT);
$stmt->bindValue(':itemId', $itemId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    unset($found[(int) $row['paperless_id']]);
}

usort($found, fn($a, $b) => strcmp((string) $b['created'], (string) $a['created']));
$correspondents = $found ? paperlessCorrespondents($cfg, $userId) : [];
echo json_encode([
    'success' => true,
    'documents' => array_map(fn($d) => paperlessSummary($d, $correspondents), array_slice($found, 0, 20)),
]);
$db->close();
