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
if (!$due) {
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
    $update = $db->prepare("UPDATE deadman_settings SET triggered_at = datetime('now', 'localtime'), access_token = :token,
        token_expires = :expires, failed_attempts = 0 WHERE user_id = :userId");
    $update->bindValue(':token', $token);
    $update->bindValue(':expires', $expires);
    $update->bindValue(':userId', $row['user_id'], SQLITE3_INTEGER);
    $update->execute();
    echo date('Y-m-d H:i:s') . " Inactivity guard: link sent for user {$row['user_id']}\n";
}
