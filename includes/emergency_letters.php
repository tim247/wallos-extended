<?php
// Cancellation package for relatives: one letter per contract, insurance and subscription
// ("cancellation because of death, the death certificate is enclosed"), as a ZIP of PDFs.
// Used by the owner (emergency.php) and by the trusted person with full access (emergency_access.php).
require_once __DIR__ . '/cancellation_letter.php';

/** Letter text; {{verstorben}} is the deceased, the rest are the usual letter placeholders. */
function emergencyLetterTemplate(): array
{
    return [
        'subject' => 'Kündigung wegen Todesfall – {{bezeichnung}} {{nummer_info}}',
        'body' => "Sehr geehrte Damen und Herren,\n\n"
            . "hiermit teile ich Ihnen mit, dass {{verstorben}} verstorben ist. Als Angehörige/r kündige ich den "
            . "Vertrag „{{bezeichnung}}“ {{nummer_info}} außerordentlich zum nächstmöglichen Zeitpunkt, hilfsweise "
            . "zum Todestag.\n\n"
            . "Eine Kopie der Sterbeurkunde liegt diesem Schreiben bei. Bitte bestätigen Sie mir die Kündigung "
            . "schriftlich und erstatten Sie zu viel gezahlte Beiträge.\n\n"
            . "Mit freundlichen Grüßen\n\n{{absender_name}}",
    ];
}

/**
 * Items that get a letter: active contracts and insurances (not the building blocks of a combined
 * policy - the policy letter covers them) and active subscriptions.
 * @return array<int, array{name: string, provider: string, number: string, number_label: string, address: string}>
 */
function emergencyLetterItems(SQLite3 $db, int $userId): array
{
    $rows = function (string $sql) use ($db, $userId): array {
        $stmt = @$db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $out = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $out[] = $row;
        }
        return $out;
    };
    $decode = fn($v) => html_entity_decode(trim((string) $v));
    $items = [];
    foreach ($rows('SELECT * FROM contracts WHERE user_id = :userId AND archived = 0 AND cancellation_status = 0 ORDER BY name') as $row) {
        $items[] = ['name' => $decode($row['name']), 'provider' => $decode($row['provider']) ?: $decode($row['name']), 'number' => $decode($row['contract_number']),
            'number_label' => 'Vertragsnummer', 'address' => (string) ($row['provider_address'] ?? '')];
    }
    foreach ($rows('SELECT * FROM insurances WHERE user_id = :userId AND archived = 0 AND cancellation_status = 0 AND parent_id IS NULL ORDER BY name') as $row) {
        $items[] = ['name' => $decode($row['name']), 'provider' => $decode($row['insurer']) ?: $decode($row['name']), 'number' => $decode($row['policy_number']),
            'number_label' => 'Versicherungsscheinnummer', 'address' => (string) ($row['provider_address'] ?? '')];
    }
    foreach ($rows('SELECT * FROM subscriptions WHERE user_id = :userId AND archived = 0 AND inactive = 0 AND cycle != 5 ORDER BY name') as $row) {
        $items[] = ['name' => $decode($row['name']), 'provider' => $decode($row['name']), 'number' => '', 'number_label' => '', 'address' => ''];
    }
    return $items;
}

/**
 * Builds the ZIP and returns its path (a temp file the caller sends and deletes), or null.
 * $sender: name, address (lines), place; $deceased: name of the deceased.
 */
function emergencyLetterZip(SQLite3 $db, int $userId, array $sender, string $deceased): ?string
{
    $items = emergencyLetterItems($db, $userId);
    if (!$items || !class_exists('ZipArchive')) {
        return null;
    }
    $template = emergencyLetterTemplate();
    $template['subject'] = str_replace('{{verstorben}}', $deceased, $template['subject']);
    $template['body'] = str_replace('{{verstorben}}', $deceased, $template['body']);
    $path = tempnam(sys_get_temp_dir(), 'wallos-letters');
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
        return null;
    }
    $used = [];
    foreach ($items as $item) {
        $pdf = cancellationLetterPdf($template, [
            'sender_name' => $sender['name'], 'sender_address' => $sender['address'],
            'provider' => $item['provider'], 'recipient_address' => $item['address'],
            'item_name' => $item['name'], 'number' => $item['number'], 'number_label' => $item['number_label'] ?: 'Vertragsnummer',
            'terminate_on' => '', 'deadline' => '', 'date' => date('Y-m-d'), 'place' => $sender['place'] ?? '',
        ]);
        $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $item['name'])), '-') ?: 'Vertrag';
        $file = $slug;
        for ($n = 2; isset($used[$file]); $n++) {
            $file = "$slug-$n";
        }
        $used[$file] = true;
        $zip->addFromString("Kuendigung-$file.pdf", $pdf);
    }
    $zip->close();
    return $path;
}

/** Sends the ZIP to the browser and removes the temp file. */
function emergencySendLetterZip(string $path): void
{
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="Kuendigungen-Todesfall.zip"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    unlink($path);
}
