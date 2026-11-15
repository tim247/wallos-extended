<?php
// Inactivity guard: the trusted person opens the emergency dossier with the link from the
// e-mail and the access code they got in person. No login; 10 wrong codes lock the link.
require_once 'includes/connect.php';
require_once 'includes/currency_formatter.php';
require_once 'includes/version.php';
require_once 'includes/deadman.php';
require_once 'includes/i18n/languages.php';

header('X-Robots-Tag: noindex');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store');

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$settings = deadmanByToken($db, $token);
$owner = $settings ? $db->querySingle('SELECT * FROM user WHERE id = ' . (int) $settings['user_id'], true) : null;
require_once 'includes/i18n/getlang.php'; // sets $lang from a cookie: the owner's language wins below
$lang = $owner && isset($languages[$owner['language']]) ? $owner['language'] : 'en';
require_once 'includes/i18n/' . $lang . '.php';

$error = '';
$unlocked = false;
if ($settings && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (password_verify((string) ($_POST['code'] ?? ''), (string) $settings['access_code_hash'])) {
        $unlocked = true;
    } else {
        sleep(1); // slows down guessing
        $attempts = (int) $settings['failed_attempts'] + 1;
        // The 10th wrong code withdraws the link for good.
        $stmt = $db->prepare('UPDATE deadman_settings SET failed_attempts = :attempts,
            access_token = CASE WHEN :attempts >= :max THEN NULL ELSE access_token END WHERE user_id = :userId');
        $stmt->bindValue(':attempts', $attempts, SQLITE3_INTEGER);
        $stmt->bindValue(':max', DEADMAN_MAX_ATTEMPTS, SQLITE3_INTEGER);
        $stmt->bindValue(':userId', $settings['user_id'], SQLITE3_INTEGER);
        $stmt->execute();
        if ($attempts >= DEADMAN_MAX_ATTEMPTS) {
            $settings = null;
        } else {
            $error = sprintf(translate('deadman_wrong_code', $i18n), DEADMAN_MAX_ATTEMPTS - $attempts);
        }
    }
}
if (!$settings) {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?= translate('emergency', $i18n) ?></title>
  <link rel="stylesheet" href="styles/theme.css?<?= $assetVersion ?>">
  <link rel="stylesheet" href="styles/styles.css?<?= $assetVersion ?>">
  <link rel="stylesheet" href="styles/font-awesome.min.css">
</head>
<body class="light">
<main>
  <section class="contain life">
    <?php if (!$settings): ?>
      <h2><?= translate('emergency', $i18n) ?></h2>
      <p><?= translate('deadman_link_invalid', $i18n) ?></p>
    <?php elseif (!$unlocked): ?>
      <form method="post" class="finance-dialog emergency-unlock">
        <h2><i class="fa-solid fa-lock"></i> <?= translate('emergency', $i18n) ?></h2>
        <p><?= sprintf(translate('deadman_access_intro', $i18n), htmlspecialchars(trim(($owner['firstname'] ?? '') . ' ' . ($owner['lastname'] ?? '')) ?: $owner['username'])) ?></p>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <label><?= translate('deadman_code', $i18n) ?><input type="password" name="code" required autofocus autocomplete="off"></label>
        <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <input type="submit" class="button" value="<?= translate('emergency_open_button', $i18n) ?>">
      </form>
    <?php else:
      $userId = (int) $settings['user_id'];
      $GLOBALS['userId'] = $userId;
      $main_currency = $owner['main_currency'];
      require 'includes/getdbkeys.php';
      ?>
      <div class="no-print"><button class="button thin" onclick="window.print()"><i class="fa-solid fa-print"></i> <?= translate('report_print', $i18n) ?></button></div>
      <?php if ($settings['message'] !== ''): ?>
        <div class="dossier-notes"><?= nl2br(htmlspecialchars($settings['message'])) ?></div>
      <?php endif; ?>
      <?php require 'includes/emergency_dossier.php'; ?>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
