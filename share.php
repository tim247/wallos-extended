<?php
// Read-only cost report behind a secret link (Kostenbericht > Lese-Link). No login, no actions.
require_once 'includes/connect.php';
require_once 'includes/currency_formatter.php';
require_once 'includes/version.php';

$token = (string) ($_GET['token'] ?? '');
$user = false;
if (preg_match('/^[a-f0-9]{48}$/', $token)) {
    $stmt = $db->prepare('SELECT u.id, u.language, c.code FROM user u LEFT JOIN currencies c ON c.id = u.main_currency WHERE u.share_token = :token');
    $stmt->bindValue(':token', $token);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}
if (!$user) {
    http_response_code(404);
    exit('Not found');
}

require_once 'includes/i18n/languages.php';
require_once 'includes/i18n/getlang.php'; // sets $lang from a cookie: the owner's language wins below
$lang = isset($languages[$user['language']]) ? $user['language'] : 'en';
require_once 'includes/i18n/' . $lang . '.php';
$userId = (int) $user['id'];
$GLOBALS['userId'] = $userId;
$mainCurrencyCode = $user['code'] ?: 'EUR';
$readOnly = true;

header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title>Wallos Extended – <?= translate('cost_report', $i18n) ?></title>
  <link rel="stylesheet" href="styles/theme.css?<?= $assetVersion ?>">
  <link rel="stylesheet" href="styles/styles.css?<?= $assetVersion ?>">
  <link rel="stylesheet" href="styles/font-awesome.min.css">
</head>
<body class="light">
<main>
<?php require 'includes/report_body.php'; ?>
</main>
</body>
</html>
