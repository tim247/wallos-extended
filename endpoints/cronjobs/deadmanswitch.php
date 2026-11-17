<?php
// Daily: inactivity guard. Sends the trusted person the link to the emergency dossier once
// the owner has been inactive for the set number of days. (The reminders to the owner go out
// with the other notifications, see sendcancellationnotifications.php.)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';
require 'settimezone.php';
require_once __DIR__ . '/../../includes/instance_config.php';
require_once __DIR__ . '/../../includes/deadman.php';
require __DIR__ . '/../../libs/PHPMailer/PHPMailer.php';
require __DIR__ . '/../../libs/PHPMailer/SMTP.php';
require __DIR__ . '/../../libs/PHPMailer/Exception.php';

$admin = wallos_get_admin_settings($db);
$serverUrl = rtrim((string) ($admin['server_url'] ?? ''), '/');
$today = date('Y-m-d');

$stmt = $db->prepare("SELECT d.*, u.last_seen, u.username, u.firstname, u.lastname, u.language FROM deadman_settings d
    JOIN user u ON u.id = d.user_id WHERE d.enabled = 1 AND d.triggered_at IS NULL AND d.trusted_email != ''
    AND d.access_code_hash IS NOT NULL AND u.last_seen IS NOT NULL AND u.readonly_of IS NULL");
$result = $stmt->execute();
$due = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $left = deadmanDaysLeft($row, $row['last_seen'], $today);
    if ($left !== null && $left <= 0) {
        $due[] = $row;
    }
}
// Links already sent, but the mail to the second trusted person failed: try that one again.
$retry = [];
$retryStmt = @$db->prepare("SELECT d.*, u.username, u.firstname, u.lastname, u.language FROM deadman_settings d JOIN user u ON u.id = d.user_id
    WHERE d.enabled = 1 AND d.triggered_at IS NOT NULL AND d.access_token IS NOT NULL AND d.token_expires >= date('now')
    AND d.trusted2_email != '' AND d.access_code2_hash IS NOT NULL AND d.access_token2 IS NULL");
if ($retryStmt) {
    $retryResult = $retryStmt->execute();
    while ($row = $retryResult->fetchArray(SQLITE3_ASSOC)) {
        $retry[] = $row;
    }
}
if (!$due && !$retry) {
    echo date('Y-m-d H:i:s') . " Inactivity guard: nothing due\n";
    exit;
}
if ($serverUrl === '') {
    echo "Inactivity guard: server URL missing in the admin settings, cannot build the link\n";
    exit(1);
}

/** Admin SMTP, else the user's own e-mail notification settings. */
function deadmanMailer(SQLite3 $db, array $admin, int $userId): ?PHPMailer
{
    $config = null;
    if (!empty($admin['smtp_address'])) {
        $config = ['host' => $admin['smtp_address'], 'port' => $admin['smtp_port'], 'user' => $admin['smtp_username'],
            'password' => $admin['smtp_password'], 'encryption' => $admin['encryption'], 'from' => $admin['from_email']];
    } else {
        $row = $db->querySingle('SELECT * FROM email_notifications WHERE user_id = ' . $userId . " AND smtp_address != ''", true);
        if ($row) {
            $config = ['host' => $row['smtp_address'], 'port' => $row['smtp_port'], 'user' => $row['smtp_username'],
                'password' => $row['smtp_password'], 'encryption' => $row['encryption'], 'from' => $row['from_email']];
        }
    }
    if (!$config) {
        return null;
    }
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Timeout = 20;
    $mail->CharSet = 'UTF-8';
    $mail->Host = $config['host'];
    $mail->Port = (int) $config['port'];
    $mail->SMTPAuth = $config['user'] !== '' || $config['password'] !== '';
    if ($mail->SMTPAuth) {
        $mail->Username = $config['user'];
        $mail->Password = $config['password'];
    }
    if (($config['encryption'] ?? 'none') !== 'none') {
        $mail->SMTPSecure = $config['encryption'];
    }
    $mail->setFrom($config['from'] ?: 'wallos@wallosapp.com', 'Wallos Extended');
    return $mail;
}

foreach ($due as $row) {
    $userLanguage = resolve_language($row['language'] ?? 'en');
    require __DIR__ . '/../../includes/i18n/' . $userLanguage . '.php';
    $owner = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: $row['username'];
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d', strtotime('+' . DEADMAN_LINK_DAYS . ' days'));
    $link = $serverUrl . '/emergency_access.php?token=' . $token;
    $h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES);

    $mail = deadmanMailer($db, $admin, (int) $row['user_id']);
    if (!$mail) {
        echo "Inactivity guard: no SMTP configured for user {$row['user_id']}\n";
        continue;
    }
    try {
        $mail->addAddress($row['trusted_email'], $row['trusted_name']);
        $mail->isHTML(true);
        $mail->Subject = sprintf(translate('deadman_mail_subject', $i18n), $owner);
        $mail->Body = '<p>' . sprintf($h(translate('deadman_mail_intro', $i18n)), $h($row['trusted_name']), $h($owner), (int) $row['days']) . '</p>'
            . ($row['message'] !== '' ? '<blockquote style="border-left:3px solid #888;padding-left:10px">' . nl2br($h($row['message'])) . '</blockquote>' : '')
            . '<p>' . $h(translate('deadman_mail_link', $i18n)) . '<br><a href="' . $h($link) . '">' . $h($link) . '</a></p>'
            . '<p>' . sprintf($h(translate('deadman_mail_code', $i18n)), date('d.m.Y', strtotime($expires))) . '</p>';
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $mail->Body));
        $mail->send();
    } catch (Exception $e) {
        echo "Inactivity guard: mail to the trusted person of user {$row['user_id']} failed: {$mail->ErrorInfo}\n";
        continue; // try again tomorrow
    }
    // Second trusted person: own link, same message; a failure here does not stop the first link.
    $token2 = null;
    if (!empty($row['trusted2_email']) && !empty($row['access_code2_hash'])) {
        $token2 = bin2hex(random_bytes(32));
        $link2 = $serverUrl . '/emergency_access.php?token=' . $token2;
        $mail2 = deadmanMailer($db, $admin, (int) $row['user_id']);
        try {
            $mail2->addAddress($row['trusted2_email'], $row['trusted2_name']);
            $mail2->isHTML(true);
            $mail2->Subject = sprintf(translate('deadman_mail_subject', $i18n), $owner);
            $mail2->Body = '<p>' . sprintf($h(translate('deadman_mail_intro', $i18n)), $h($row['trusted2_name']), $h($owner), (int) $row['days']) . '</p>'
                . ($row['message'] !== '' ? '<blockquote style="border-left:3px solid #888;padding-left:10px">' . nl2br($h($row['message'])) . '</blockquote>' : '')
                . '<p>' . $h(translate('deadman_mail_link', $i18n)) . '<br><a href="' . $h($link2) . '">' . $h($link2) . '</a></p>'
                . '<p>' . sprintf($h(translate('deadman_mail_code', $i18n)), date('d.m.Y', strtotime($expires))) . '</p>';
            $mail2->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $mail2->Body));
            $mail2->send();
        } catch (Exception $e) {
            echo "Inactivity guard: mail to the second trusted person of user {$row['user_id']} failed: {$mail2->ErrorInfo}\n";
            $token2 = null;
        }
    }
    $update = $db->prepare("UPDATE deadman_settings SET triggered_at = datetime('now', 'localtime'), access_token = :token,
        token_expires = :expires, failed_attempts = 0, access_token2 = :token2, token2_expires = :expires, failed_attempts2 = 0 WHERE user_id = :userId");
    $update->bindValue(':token2', $token2, $token2 === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $update->bindValue(':token', $token);
    $update->bindValue(':expires', $expires);
    $update->bindValue(':userId', $row['user_id'], SQLITE3_INTEGER);
    $update->execute();
    echo date('Y-m-d H:i:s') . " Inactivity guard: link sent for user {$row['user_id']}\n";
}

foreach ($retry as $row) {
    $userLanguage = resolve_language($row['language'] ?? 'en');
    require __DIR__ . '/../../includes/i18n/' . $userLanguage . '.php';
    $owner = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: $row['username'];
    $h = fn($value) => htmlspecialchars((string) $value, ENT_QUOTES);
    $token2 = bin2hex(random_bytes(32));
    $link2 = $serverUrl . '/emergency_access.php?token=' . $token2;
    $mail2 = deadmanMailer($db, $admin, (int) $row['user_id']);
    if (!$mail2) {
        continue;
    }
    try {
        $mail2->addAddress($row['trusted2_email'], $row['trusted2_name']);
        $mail2->isHTML(true);
        $mail2->Subject = sprintf(translate('deadman_mail_subject', $i18n), $owner);
        $mail2->Body = '<p>' . sprintf($h(translate('deadman_mail_intro', $i18n)), $h($row['trusted2_name']), $h($owner), (int) $row['days']) . '</p>'
            . ($row['message'] !== '' ? '<blockquote style="border-left:3px solid #888;padding-left:10px">' . nl2br($h($row['message'])) . '</blockquote>' : '')
            . '<p>' . $h(translate('deadman_mail_link', $i18n)) . '<br><a href="' . $h($link2) . '">' . $h($link2) . '</a></p>'
            . '<p>' . sprintf($h(translate('deadman_mail_code', $i18n)), date('d.m.Y', strtotime($row['token_expires']))) . '</p>';
        $mail2->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $mail2->Body));
        $mail2->send();
    } catch (Exception $e) {
        echo "Inactivity guard: retry to the second trusted person of user {$row['user_id']} failed: {$mail2->ErrorInfo}\n";
        continue; // again tomorrow
    }
    $update = $db->prepare('UPDATE deadman_settings SET access_token2 = :token, token2_expires = token_expires, failed_attempts2 = 0 WHERE user_id = :userId');
    $update->bindValue(':token', $token2);
    $update->bindValue(':userId', $row['user_id'], SQLITE3_INTEGER);
    $update->execute();
    echo date('Y-m-d H:i:s') . " Inactivity guard: link sent to the second trusted person of user {$row['user_id']}\n";
}
