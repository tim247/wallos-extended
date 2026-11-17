<?php
// Insurance insights: premiums paid, claims balance, premium development against
// inflation, cover per household member, review and motor reminders, tax categories
// and reading policy data out of documents. Pure functions unless noted.

require_once __DIR__ . '/price_helpers.php';

const CLAIM_STATUSES = ['reported', 'in_review', 'settled', 'rejected'];

// German consumer price index, change against the previous year in % (Destatis, VPI).
const INFLATION_DE = [2015 => 0.5, 2016 => 0.5, 2017 => 1.5, 2018 => 1.8, 2019 => 1.4, 2020 => 0.5, 2021 => 3.1, 2022 => 6.9, 2023 => 5.9, 2024 => 2.2];

/**
 * Price per payment of the insurance on each day, from its price history (oldest first).
 * @return array<int, array{from: string, price: float}>
 */
function insurancePricePeriods(array $insurance, array $history): array
{
    usort($history, fn($a, $b) => strcmp($a['changed_at'], $b['changed_at']) ?: ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
    $start = $insurance['start_date'] ?: '1970-01-01';
    $periods = [['from' => $start, 'price' => $history ? (float) $history[0]['old_price'] : (float) $insurance['price']]];
    foreach ($history as $change) {
        $periods[] = ['from' => max($start, $change['changed_at']), 'price' => (float) $change['new_price']];
    }
    return $periods;
}

/** Premiums paid between two dates (monthly rate of each price period × months). */
function insurancePremiumsBetween(array $insurance, array $periods, string $from, string $to): float
{
    $total = 0.0;
    foreach ($periods as $i => $period) {
        $pFrom = max($period['from'], $from);
        $pTo = min($periods[$i + 1]['from'] ?? $to, $to);
        if ($pTo <= $pFrom) {
            continue;
        }
        $months = (strtotime($pTo) - strtotime($pFrom)) / 86400 / (365 / 12); // calendar months: a year counts exactly 12
        $total += getPricePerMonth((int) $insurance['cycle'], (int) $insurance['frequency'], $period['price']) * $months;
    }
    return round($total, 2);
}

/**
 * Premiums paid since the start against the benefits received from claims.
 * @return array{paid: float, benefits: float, ratio: float|null, claims: int, open: int}
 */
function insuranceBalance(array $insurance, array $periods, array $claims, string $today): array
{
    $paid = !empty($insurance['start_date']) ? insurancePremiumsBetween($insurance, $periods, $insurance['start_date'], $today) : 0.0;
    $benefits = array_sum(array_map(fn($c) => $c['status'] === 'settled' ? (float) $c['amount_paid'] : 0.0, $claims));
    return [
        'paid' => $paid,
        'benefits' => round($benefits, 2),
        'ratio' => $paid > 0 ? round($benefits / $paid * 100, 1) : null,
        'claims' => count($claims),
        'open' => count(array_filter($claims, fn($c) => in_array($c['status'], ['reported', 'in_review'], true))),
    ];
}

/**
 * Yearly premium (paid per year) with the change to the year before and the inflation.
 * @return array<int, array{premium: float, change: float|null, inflation: float|null}>
 */
function premiumDevelopment(array $insurance, array $periods, string $today): array
{
    if (empty($insurance['start_date'])) {
        return [];
    }
    $years = [];
    $previous = null;
    for ($y = (int) substr($insurance['start_date'], 0, 4); $y <= (int) substr($today, 0, 4); $y++) {
        // Monthly rate at the end of the year (or today), shown as a yearly premium.
        $at = min($y . '-12-31', $today);
        if ($at < $insurance['start_date']) {
            continue;
        }
        $price = $periods[0]['price'];
        foreach ($periods as $period) {
            if ($period['from'] <= $at) {
                $price = $period['price'];
            }
        }
        $yearly = round(getPricePerMonth((int) $insurance['cycle'], (int) $insurance['frequency'], $price) * 12, 2);
        $years[$y] = [
            'premium' => $yearly,
            'change' => $previous ? round(($yearly / $previous - 1) * 100, 1) : null,
            'inflation' => INFLATION_DE[$y] ?? null,
        ];
        $previous = $yearly;
    }
    return $years;
}

/** Member ids an insurance covers; empty = the payer only. */
function insuredMemberIds(array $insurance): array
{
    $ids = array_filter(array_map('intval', explode(',', (string) ($insurance['insured_persons'] ?? ''))));
    return $ids ?: [(int) $insurance['payer_user_id']];
}

/**
 * Basic cover per household member: health insurance and personal liability.
 * A liability insurance without persons named counts for the whole household (family policy).
 * @return array<int, string[]> member id => missing insurance types
 */
function coverageGaps(array $memberIds, array $insurances): array
{
    $gaps = [];
    foreach ($memberIds as $memberId) {
        $missing = [];
        $has = function (string $type, bool $householdWide) use ($insurances, $memberId): bool {
            foreach ($insurances as $insurance) {
                if ($insurance['insurance_type'] !== $type) {
                    continue;
                }
                if ($householdWide && trim((string) ($insurance['insured_persons'] ?? '')) === '') {
                    return true;
                }
                if (in_array((int) $memberId, insuredMemberIds($insurance), true)) {
                    return true;
                }
            }
            return false;
        };
        if (!$has('health', false)) {
            $missing[] = 'health';
        }
        if (!$has('liability', true)) {
            $missing[] = 'liability';
        }
        if ($missing) {
            $gaps[(int) $memberId] = $missing;
        }
    }
    return $gaps;
}

/** Household contents insurance below 650 € per m² of living area: amount missing, else null. */
function householdUnderinsurance(array $insurance, float $livingArea): ?float
{
    if ($insurance['insurance_type'] !== 'household' || $livingArea <= 0 || empty($insurance['coverage_amount'])) {
        return null;
    }
    $needed = $livingArea * 650;
    return (float) $insurance['coverage_amount'] < $needed ? round($needed - (float) $insurance['coverage_amount'], 2) : null;
}

/** Cover not checked for two years (from the last review, or the start). */
function insuranceReviewDue(array $insurance, string $today): bool
{
    $since = $insurance['last_review'] ?: ($insurance['start_date'] ?: null);
    return $since !== null && $since <= date('Y-m-d', strtotime($today . ' -2 years'));
}

/** Motor insurance: reminder to compare before the switching date 30 November (1 Oct - 30 Nov). */
function motorSwitchReminderDue(array $insurance, string $today): bool
{
    $md = substr($today, 5);
    return $insurance['insurance_type'] === 'car'
        && ($insurance['cancel_mode'] ?? 'cancellable') === 'cancellable'
        && empty($insurance['cancellation_status'])
        && $md >= '10-01' && $md <= '11-30'
        && (int) ($insurance['kfz_reminded_year'] ?? 0) < (int) substr($today, 0, 4);
}

/**
 * Report a claim or pay it yourself? A claim pays out (amount - deductible), but costs the
 * refund for a claim-free year. Below the break-even, paying yourself is cheaper.
 * @return array{payout: float, break_even: float, worth_it: bool}
 */
function claimWorthReporting(float $amount, float $deductible, float $refund): array
{
    $payout = max(0.0, $amount - $deductible);
    $breakEven = round($deductible + $refund, 2);
    return ['payout' => round($payout, 2), 'break_even' => $breakEven, 'worth_it' => $payout > $refund && $amount > $breakEven];
}

/** Term fields a building block takes over from its combined policy (one notice period for all). */
const POLICY_TERM_FIELDS = ['start_date', 'initial_term_months', 'renewal_term_months', 'auto_renews', 'end_date',
    'notice_period_months', 'notice_period_days', 'cancel_mode', 'cancellation_status'];

/**
 * Provision check per earner: disability insurance (BU) for everyone with an income, risk life
 * insurance for the main earner of a household with more than one person, and a BU pension of at
 * least 70 % of the net income (the coverage amount of a BU is its monthly pension).
 * @param array $incomeByMember member id => monthly net income
 * @return array<int, array{key: string, member: int, value?: float}>
 */
function provisionGaps(array $incomeByMember, array $insurances, int $householdSize): array
{
    $gaps = [];
    arsort($incomeByMember);
    $mainEarner = array_key_first($incomeByMember);
    foreach ($incomeByMember as $memberId => $income) {
        if ($income <= 0) {
            continue;
        }
        $covers = fn(string $type) => array_values(array_filter($insurances, fn($i) => $i['insurance_type'] === $type
            && empty($i['cancellation_status']) && in_array((int) $memberId, insuredMemberIds($i), true)));
        $bu = $covers('disability');
        if (!$bu) {
            $gaps[] = ['key' => 'provision_no_bu', 'member' => (int) $memberId];
        } else {
            $pension = array_sum(array_map(fn($i) => (float) ($i['coverage_amount'] ?? 0), $bu));
            if ($pension > 0 && $pension < $income * 0.7) {
                $gaps[] = ['key' => 'provision_bu_low', 'member' => (int) $memberId, 'value' => round($income * 0.7 - $pension, 2)];
            }
        }
        if ($householdSize > 1 && $memberId === $mainEarner && !$covers('life')) {
            $gaps[] = ['key' => 'provision_no_life', 'member' => (int) $memberId];
        }
    }
    return $gaps;
}

/** Where a premium goes in the German tax return. */
function insuranceTaxCategory(string $type): string
{
    return match ($type) {
        'health', 'pension' => 'tax_basic_provision',       // Basis-Kranken-/Pflege-, Rürup
        'disability', 'accident', 'liability', 'life', 'car' => 'tax_other_provision',
        'legal' => 'tax_work_related',                       // Berufsrechtsschutz share
        default => 'tax_check',
    };
}

/**
 * Policy data found in a document text: number, premium, start, term.
 * @return array{policy_number?: string, price?: float, start_date?: string, initial_term_months?: int}
 */
function extractPolicyData(string $text): array
{
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
    $found = [];
    if (preg_match('/(?:Versicherungs(?:schein)?[- ]?(?:nummer|nr\.?)|Vertrags[- ]?(?:nummer|nr\.?)|Police(?:n[- ]?nummer)?|Policy number)\s*:?\s*([A-Z0-9][A-Z0-9.\-\/]{3,24}(?: [A-Z0-9.\-\/]{1,10}){0,2})/iu', $text, $m)) {
        $found['policy_number'] = trim($m[1]);
    }
    if (preg_match('/(?:Gesamtbeitrag|Zahlbeitrag|Beitrag|Prämie|Premium)[^0-9\n]{0,40}?(\d{1,3}(?:\.\d{3})*,\d{2})\s*(?:€|EUR)/iu', $text, $m)) {
        $found['price'] = (float) str_replace(['.', ','], ['', '.'], $m[1]);
    }
    if (preg_match('/(?:Versicherungs)?[Bb]eginn[^0-9\n]{0,25}(\d{1,2})\.(\d{1,2})\.(\d{4})/u', $text, $m)) {
        $found['start_date'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    if (preg_match('/(?:Vertrags)?[Ll]aufzeit[^0-9\n]{0,25}(\d{1,2})\s*(Jahr|Monat)/u', $text, $m)) {
        $found['initial_term_months'] = (int) $m[1] * ($m[2] === 'Jahr' ? 12 : 1);
    }
    return $found;
}

/**
 * Plain text of a digital PDF without external tools: text operators of the
 * (Flate-compressed) content streams. Scanned PDFs have no text - then ''.
 */
function pdfPlainText(string $pdf): string
{
    $text = '';
    if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams)) {
        return '';
    }
    foreach ($streams[1] as $stream) {
        $data = @gzuncompress($stream);
        if ($data === false) {
            $data = @gzinflate(substr($stream, 2)) ?: $stream;
        }
        if (!preg_match_all('/\[(.*?)\]\s*TJ|\((.*?)(?<!\\\\)\)\s*Tj|(T\*|ET|Td|TD)/s', $data, $ops, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($ops as $op) {
            if (!empty($op[3])) {
                $text .= $op[3] === 'ET' || $op[3] === 'T*' ? "\n" : ' ';
                continue;
            }
            $chunk = $op[1] !== '' ? implode('', preg_match_all('/\((.*?)(?<!\\\\)\)/s', $op[1], $p) ? $p[1] : []) : ($op[2] ?? '');
            $text .= stripcslashes($chunk);
        }
    }
    // PDF strings are mostly Latin-1 / WinAnsi.
    return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
}
