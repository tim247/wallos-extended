<?php
// Create or delete read-only logins that see this user's data (Settings > Read-only access).
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$fail = fn($key) => die(json_encode(['success' => false, 'message' => translate($key, $i18n)]));

// A read-only account never manages other accounts (connect_endpoint already refuses it).
$stmt = $db->prepare('SELECT main_currency, language, readonly_of FROM user WHERE id = :id');
$stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
$owner = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!$owner || !empty($owner['readonly_of'])) {
    $fail('error');
}

if (($data['action'] ?? '') === 'delete') {
    $stmt = $db->prepare('DELETE FROM user WHERE id = :id AND readonly_of = :owner');
    $stmt->bindValue(':id', (int) ($data['id'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':owner', $userId, SQLITE3_INTEGER);
    if (!$stmt->execute()) {
        $fail('error');
    }
    die(json_encode(['success' => $db->changes() > 0, 'message' => translate('success', $i18n)]));
}

$username = trim((string) ($data['username'] ?? ''));
$password = (string) ($data['password'] ?? '');
if (!preg_match('/^[\p{L}\p{N}._-]{3,40}$/u', $username)) {
    $fail('readonly_username_invalid');
}
if (strlen($password) < 10) {
    $fail('readonly_password_short');
}
$stmt = $db->prepare('SELECT COUNT(*) FROM user WHERE username = :username COLLATE NOCASE OR email = :username COLLATE NOCASE');
$stmt->bindValue(':username', $username);
if ($stmt->execute()->fetchArray()[0] > 0) {
    $fail('username_exists');
}

$stmt = $db->prepare("INSERT INTO user (username, email, password, main_currency, language, avatar, readonly_of)
    VALUES (:username, '', :password, :currency, :language, '0', :owner)");
$stmt->bindValue(':username', $username);
$stmt->bindValue(':password', password_hash($password, PASSWORD_DEFAULT));
$stmt->bindValue(':currency', (int) $owner['main_currency'], SQLITE3_INTEGER);
$stmt->bindValue(':language', $owner['language'] ?: 'en');
$stmt->bindValue(':owner', $userId, SQLITE3_INTEGER);
echo json_encode(['success' => (bool) $stmt->execute(), 'message' => translate('success', $i18n)]);
