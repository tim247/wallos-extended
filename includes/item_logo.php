<?php
// Logo of a contract or insurance: from the web search (logo-url) or an uploaded file, like subscriptions.
// The previous file is removed when nothing else uses it. Returns an error text or '' (no logo sent counts as fine).
require_once __DIR__ . '/ssrf_helper.php';
require_once __DIR__ . '/logo_upload.php';
require_once __DIR__ . '/logo_cleanup.php';

function saveItemLogo(SQLite3 $db, string $table, int $id, int $userId, string $name, array $settings, array $i18n): string
{
    if (!in_array($table, ['contracts', 'insurances'], true)) {
        return '';
    }
    $dir = __DIR__ . '/../images/uploads/logos/';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $logo = '';
    $logoUrl = trim((string) ($_POST['logo-url'] ?? ''));
    if ($logoUrl !== '') {
        $result = getLogoFromUrl($logoUrl, $dir, $name, $settings, $i18n);
        if (!$result['success']) {
            return $result['message'];
        }
        $logo = $result['filename'];
    } elseif (!empty($_FILES['logo']['name'])) {
        if (@getimagesize($_FILES['logo']['tmp_name']) === false) { // core PHP, unlike mime_content_type (fileinfo)
            return translate('error_saving_logo', $i18n);
        }
        $logo = resizeAndUploadLogo($_FILES['logo'], $dir, $name, $settings);
        if ($logo === '') {
            return translate('error_saving_logo', $i18n);
        }
    }
    if ($logo === '') {
        return '';
    }
    $old = $db->prepare("SELECT logo FROM $table WHERE id = :id AND user_id = :userId");
    $old->bindValue(':id', $id, SQLITE3_INTEGER);
    $old->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $oldLogo = (string) ($old->execute()->fetchArray(SQLITE3_NUM)[0] ?? '');
    $stmt = $db->prepare("UPDATE $table SET logo = :logo WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':logo', $logo);
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    if ($oldLogo !== '' && $oldLogo !== $logo) {
        deleteLogoFileIfUnused($db, $oldLogo, $dir);
    }
    return '';
}
