<?php
// Cancellation letters: editable text template (admin area) + PDF rendering.

require_once __DIR__ . '/pdf_letter.php';

const CANCELLATION_ITEM_TABLES = ['contract' => 'contracts', 'insurance' => 'insurances'];

/** Placeholders usable in the template, with a short description (shown in the admin area). */
function cancellationPlaceholders(): array
{
    return [
        'absender_name' => 'Name des Absenders',
        'absender_adresse' => 'Adresse des Absenders',
        'anbieter' => 'Anbieter / Versicherer',
        'bezeichnung' => 'Bezeichnung des Vertrags',
        'nummer' => 'Vertrags- bzw. Versicherungsscheinnummer',
        'nummer_info' => 'z. B. „Vertragsnummer 123“ (leer ohne Nummer)',
        'kuendigung_zum' => 'Datum, ggf. mit „hilfsweise zum nächstmöglichen Zeitpunkt“',
        'frist' => 'Ablauf der Kündigungsfrist',
        'datum' => 'Datum des Schreibens',
        'ort' => 'Ort des Schreibens',
    ];
}

function cancellationDefaultTemplate(): array
{
    return [
        'subject' => 'Kündigung {{bezeichnung}} {{nummer_info}}',
        'body' => "Sehr geehrte Damen und Herren,\n\n"
            . "hiermit kündige ich den oben genannten Vertrag ordentlich und fristgerecht zum {{kuendigung_zum}}.\n\n"
            . "Bitte bestätigen Sie mir die Kündigung und das Vertragsende schriftlich unter Angabe des Beendigungsdatums.\n\n"
            . "Mit freundlichen Grüßen\n\n{{absender_name}}",
    ];
}

function cancellationTemplate(SQLite3 $db): array
{
    $row = $db->querySingle("SELECT subject, body FROM cancellation_template WHERE id = 1", true);
    return $row ? $row : cancellationDefaultTemplate();
}

function cancellationDate(?string $date): string
{
    return $date ? date('d.m.Y', strtotime($date)) : '';
}

/** Replaces {{placeholders}}; unknown ones are removed, repeated blanks collapsed. */
function cancellationFill(string $text, array $values): string
{
    $text = preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn($m) => $values[$m[1]] ?? '', $text);
    return trim(preg_replace('/[ \t]{2,}/', ' ', $text));
}

/** @param array $ctx see keys below */
function cancellationLetterPdf(array $template, array $ctx): string
{
    [$senderLines, $recipientLines, $dateLine, $subject, $body] = cancellationLetterParts($template, $ctx);
    return (new LetterPdf())->letter($senderLines, $recipientLines, $dateLine, $subject, $body, $subject);
}

/** File name "26 11 19 - Grenzland Fitness - Kündigung.pdf": letter date (YY MM DD) - provider - Kündigung. */
function cancellationFileName(string $date, string $provider): string
{
    $provider = trim(preg_replace('/\s+/u', ' ', preg_replace('#[\\\\/:*?"<>|\x00-\x1f]+#u', ' ', $provider)));
    return date('y m d', strtotime($date)) . ' - ' . ($provider !== '' ? $provider : 'Vertrag') . ' - Kündigung.pdf';
}

/** @return array{0: string[], 1: string[], 2: string, 3: string, 4: string} sender, recipient, date line, subject, body */
function cancellationLetterParts(array $template, array $ctx): array
{
    $address = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($ctx['sender_address'] ?? ''))), 'strlen'));
    $senderLines = array_merge([(string) ($ctx['sender_name'] ?? '')], $address);
    $recipientLines = array_merge(
        [(string) ($ctx['provider'] ?? '')],
        array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($ctx['recipient_address'] ?? ''))), 'strlen'))
    );

    $terminateOn = cancellationDate($ctx['terminate_on'] ?? null);
    $number = trim((string) ($ctx['number'] ?? ''));
    $values = [
        'absender_name' => (string) ($ctx['sender_name'] ?? ''),
        'absender_adresse' => implode(', ', $address),
        'anbieter' => (string) ($ctx['provider'] ?? ''),
        'bezeichnung' => (string) ($ctx['item_name'] ?? ''),
        'nummer' => $number,
        'nummer_info' => $number !== '' ? ($ctx['number_label'] ?? 'Vertragsnummer') . ' ' . $number : '',
        'kuendigung_zum' => $terminateOn !== '' ? $terminateOn . ', hilfsweise zum nächstmöglichen Zeitpunkt' : 'nächstmöglichen Zeitpunkt',
        'frist' => cancellationDate($ctx['deadline'] ?? null),
        'datum' => cancellationDate($ctx['date'] ?? date('Y-m-d')),
        'ort' => (string) ($ctx['place'] ?? ''),
    ];

    $subject = cancellationFill($template['subject'], $values);
    $body = implode("\n", array_map(fn($line) => cancellationFill($line, $values), preg_split('/\R/u', $template['body'])));
    $place = trim((string) ($ctx['place'] ?? ''));
    $dateLine = ($place !== '' ? $place . ', den ' : '') . $values['datum'];

    return [$senderLines, $recipientLines, $dateLine, $subject, $body];
}

/** Item of the current user from contracts / insurances, with provider and number resolved. */
function cancellationItem(SQLite3 $db, int $userId, string $type, int $id): ?array
{
    if (!isset(CANCELLATION_ITEM_TABLES[$type])) {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM " . CANCELLATION_ITEM_TABLES[$type] . " WHERE id = :id AND user_id = :userId");
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $item = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$item) {
        return null;
    }
    $item['_provider'] = trim((string) ($type === 'contract' ? $item['provider'] : $item['insurer'])) ?: $item['name'];
    $item['_number'] = trim((string) ($type === 'contract' ? $item['contract_number'] : $item['policy_number']));
    $item['_number_label'] = $type === 'contract' ? 'Vertragsnummer' : 'Versicherungsscheinnummer';
    return $item;
}
