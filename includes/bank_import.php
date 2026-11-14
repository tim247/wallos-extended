<?php
// Bank statement (CSV) against the recorded costs. Nothing is stored: the file
// is parsed, compared and shown once.
// Works with the usual German exports (Sparkasse, DKB, ING, comdirect, Volksbank,
// N26, Postbank ...): delimiter, header row and columns are detected.

/** Parses a statement into bookings: [date (Y-m-d), amount (float, negative = debit), payee, text]. */
function bankParseCsv(string $content): array
{
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $lines = preg_split('/\r\n|\r|\n/', $content);

    // Header = first line with a date and an amount column.
    foreach ($lines as $index => $line) {
        $delimiter = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
        $cells = array_map(fn($c) => mb_strtolower(trim((string) $c)), str_getcsv($line, $delimiter, '"', ''));
        $date = $amount = null;
        $payee = $text = [];
        foreach ($cells as $i => $cell) {
            if ($date === null && preg_match('/^(buchungstag|buchungsdatum|buchung|datum|date|booking date)/u', $cell)) {
                $date = $i;
            } elseif ($amount === null && preg_match('/^(betrag|amount|umsatz)/u', $cell) && !str_contains($cell, 'saldo')) {
                $amount = $i;
            } elseif (preg_match('/(empf|auftraggeber|zahlungspflichtig|beg(ü|ue)nstigt|payee|partner|^name)/u', $cell)) {
                $payee[] = $i;
            } elseif (preg_match('/(verwendung|buchungstext|purpose|reference|beschreibung)/u', $cell)) {
                $text[] = $i;
            }
        }
        if ($date !== null && $amount !== null) {
            return bankRows(array_slice($lines, $index + 1), $delimiter, $date, $amount, $payee, $text);
        }
    }
    return [];
}

function bankRows(array $lines, string $delimiter, int $date, int $amount, array $payee, array $text): array
{
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, $delimiter, '"', '');
        $parsedDate = bankParseDate($cells[$date] ?? '');
        $parsedAmount = bankParseAmount($cells[$amount] ?? '');
        if ($parsedDate === null || $parsedAmount === null) {
            continue;
        }
        $pick = fn($indexes) => trim(implode(' ', array_map(fn($i) => trim($cells[$i] ?? ''), $indexes)));
        $rows[] = ['date' => $parsedDate, 'amount' => $parsedAmount, 'payee' => $pick($payee), 'text' => $pick($text)];
    }
    return $rows;
}

function bankParseDate(string $value): ?string
{
    $value = trim($value);
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $value, $m)) {
        $year = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
        return sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
        return "$m[1]-$m[2]-$m[3]";
    }
    return null;
}

/** "-1.234,56", "1,234.56", "-12.99 €" -> float */
function bankParseAmount(string $value): ?float
{
    $value = preg_replace('/[^\d,.\-+]/', '', $value);
    if ($value === '' || !preg_match('/\d/', $value)) {
        return null;
    }
    $comma = strrpos($value, ',');
    $dot = strrpos($value, '.');
    if ($comma !== false && ($dot === false || $comma > $dot)) {
        $value = str_replace(['.', ','], ['', '.'], $value); // German
    } else {
        $value = str_replace(',', '', $value);
    }
    return (float) $value;
}

/** Significant lowercase words of a name ("Vodafone GmbH" -> ["vodafone"]). */
function bankTokens(string $name): array
{
    $stop = ['gmbh', 'vertrag', 'versicherung', 'kredit', 'darlehen', 'plus', 'premium', 'online', 'service', 'deutschland'];
    preg_match_all('/[\p{L}\d]{4,}/u', mb_strtolower($name), $m);
    return array_values(array_diff(array_unique($m[0]), $stop));
}

/**
 * Compares the debits with the recorded items (householdCostItems()).
 * @return array{matched: array, missing: array, untracked: array, from: ?string, to: ?string}
 */
function bankCompare(array $bookings, array $items, float $tolerance = 0.02): array
{
    $debits = array_values(array_filter($bookings, fn($b) => $b['amount'] < 0));
    $used = [];
    $matched = $missing = [];

    foreach ($items as $item) {
        $tokens = bankTokens($item['match']);
        $hits = [];
        foreach ($debits as $i => $booking) {
            $haystack = mb_strtolower($booking['payee'] . ' ' . $booking['text']);
            foreach ($tokens as $token) {
                if (str_contains($haystack, $token)) {
                    $hits[] = $i;
                    break;
                }
            }
        }
        if ($hits === []) {
            $missing[] = $item;
            continue;
        }
        // Prefer the booking closest to the expected amount, the newest on ties.
        usort($hits, fn($a, $b) => [abs(-$debits[$a]['amount'] - $item['amount']), $debits[$b]['date']]
            <=> [abs(-$debits[$b]['amount'] - $item['amount']), $debits[$a]['date']]);
        $best = $debits[$hits[0]];
        foreach ($hits as $i) {
            $used[$i] = true;
        }
        $booked = -$best['amount'];
        $matched[] = [
            'item' => $item,
            'date' => $best['date'],
            'booked' => $booked,
            'count' => count($hits),
            'changed' => $item['amount'] > 0 && abs($booked - $item['amount']) > max(0.05, $item['amount'] * $tolerance),
        ];
    }

    // Debits to the same payee with about the same amount at least twice = recurring but not recorded.
    $groups = [];
    foreach ($debits as $i => $booking) {
        if (isset($used[$i]) || $booking['payee'] === '') {
            continue;
        }
        $key = mb_strtolower(preg_replace('/\s+/', ' ', mb_substr($booking['payee'], 0, 40))) . '|' . round(-$booking['amount']);
        $groups[$key][] = $booking;
    }
    $untracked = [];
    foreach ($groups as $group) {
        if (count($group) >= 2) {
            usort($group, fn($a, $b) => strcmp($b['date'], $a['date']));
            $untracked[] = ['payee' => $group[0]['payee'], 'amount' => -$group[0]['amount'], 'count' => count($group),
                'last' => $group[0]['date'], 'text' => $group[0]['text']];
        }
    }
    usort($untracked, fn($a, $b) => $b['amount'] <=> $a['amount']);

    $dates = array_column($bookings, 'date');
    return ['matched' => $matched, 'missing' => $missing, 'untracked' => $untracked,
        'from' => $dates ? min($dates) : null, 'to' => $dates ? max($dates) : null];
}
