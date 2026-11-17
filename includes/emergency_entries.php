<?php
// Emergency dossier entries and the checklist for relatives.

/** Sections: icon, whether a limited trusted person sees them. */
const EMERGENCY_SECTIONS = [
    'contacts' => ['fa-address-book', true],
    'directives' => ['fa-file-contract', true],
    'accounts' => ['fa-building-columns', false],
    'password_manager' => ['fa-key', false],
    'digital' => ['fa-cloud', false],
    'pets' => ['fa-paw', true],
    'places' => ['fa-key', true],
    'medical' => ['fa-notes-medical', false],
];

/** Account numbers are never stored in full: IBANs and card numbers keep their last 4 digits. */
function emergencyMaskNumbers(string $text): string
{
    return preg_replace_callback('/\b(?:[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){10,30}|\d(?:[ -]?\d){11,22})\b/', function ($m) {
        $digits = preg_replace('/[^A-Z0-9]/', '', $m[0]);
        return '…' . substr($digits, -4);
    }, $text);
}

/** Entries of a user grouped by section. */
function emergencyEntries(SQLite3 $db, int $userId): array
{
    $grouped = array_fill_keys(array_keys(EMERGENCY_SECTIONS), []);
    $stmt = @$db->prepare('SELECT * FROM emergency_entries WHERE user_id = :userId ORDER BY section, name COLLATE NOCASE');
    if (!$stmt) {
        return $grouped;
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (isset($grouped[$row['section']])) {
            $grouped[$row['section']][] = $row;
        }
    }
    return $grouped;
}

/** Directives (living will, power of attorney, will) not updated for two years. */
function emergencyDirectivesDue(array $directives, string $today): array
{
    return array_values(array_filter($directives, fn($d) => !empty($d['updated_at']) && $d['updated_at'] <= date('Y-m-d', strtotime($today . ' -2 years'))));
}

/** Dossier not checked for six months (from the last review or, never reviewed, from the first entry). */
function emergencyReviewDue(?string $reviewedAt, ?string $firstEntry, string $today): bool
{
    $since = $reviewedAt ?: $firstEntry;
    return $since !== null && $since <= date('Y-m-d', strtotime($today . ' -6 months'));
}

/**
 * Checklist for relatives: fixed steps plus what to do with the items in Wallos.
 * @return array<string, array<int, array{text: string, items?: string[]}>> phase => steps
 */
function emergencyChecklist(array $insurances, array $contracts, array $subscriptions, array $loans, array $i18n): array
{
    $t = fn(string $key) => $i18n[$key] ?? $key; // same as translate(), without the page context
    $names = fn(array $rows) => array_values(array_map(fn($r) => html_entity_decode((string) $r['name']), $rows));
    $isLife = fn($i) => in_array($i['insurance_type'], ['life', 'accident', 'pension'], true);
    return [
        'now' => [
            ['text' => $t('checklist_now_certificate')],
            ['text' => $t('checklist_now_employer')],
            ['text' => $t('checklist_now_life_insurance'), 'items' => $names(array_filter($insurances, $isLife))],
            ['text' => $t('checklist_now_directives')],
        ],
        'weeks' => [
            ['text' => $t('checklist_weeks_bank'), 'items' => $names($loans)],
            ['text' => $t('checklist_weeks_pension')],
            ['text' => $t('checklist_weeks_insurances'), 'items' => $names(array_filter($insurances, fn($i) => !$isLife($i)))],
            ['text' => $t('checklist_weeks_contracts'), 'items' => $names($contracts)],
            ['text' => $t('checklist_weeks_subscriptions'), 'items' => $names($subscriptions)],
        ],
        'later' => [
            ['text' => $t('checklist_later_digital')],
            ['text' => $t('checklist_later_tax')],
            ['text' => $t('checklist_later_estate')],
        ],
    ];
}
