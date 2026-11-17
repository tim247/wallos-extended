<?php
// Cancellation deadline helpers for contracts and insurances.
// German contracts define a notice period (Kündigungsfrist) relative to the
// end of the current term; the deadline is computed, never entered manually.

/**
 * Computes the next cancellation deadline and term end for a contract/insurance.
 *
 * @param array $item Row with start_date, initial_term_months, renewal_term_months,
 *                    auto_renews, end_date, notice_period_months, notice_period_days.
 * @param string|null $today Y-m-d, defaults to today.
 * @return array{deadline: string, term_end: string}|null Null when no dates can be derived.
 */
function getNextCancellationDeadline(array $item, ?string $today = null): ?array
{
    if (isset($item['cancel_mode']) && $item['cancel_mode'] !== 'cancellable') {
        return null; // health insurance (switch only) or not cancellable at all
    }
    $today = $today ?? date('Y-m-d');

    $noticeMonths = (int) ($item['notice_period_months'] ?? 0);
    $noticeDays = (int) ($item['notice_period_days'] ?? 0);
    $autoRenews = !empty($item['auto_renews']);

    $applyNotice = function (DateTime $termEnd) use ($noticeMonths, $noticeDays): DateTime {
        $deadline = clone $termEnd;
        if ($noticeMonths > 0) {
            $deadline->modify('-' . $noticeMonths . ' months');
        }
        if ($noticeDays > 0) {
            $deadline->modify('-' . $noticeDays . ' days');
        }
        return $deadline;
    };

    if (!$autoRenews) {
        if (empty($item['end_date'])) {
            return null;
        }
        $termEnd = new DateTime($item['end_date']);
        $deadline = $applyNotice($termEnd);
        return [
            'deadline' => $deadline->format('Y-m-d'),
            'term_end' => $termEnd->format('Y-m-d'),
        ];
    }

    if (empty($item['start_date'])) {
        return null;
    }

    $initialTerm = max((int) ($item['initial_term_months'] ?? 12), 1);
    $renewalTerm = max((int) ($item['renewal_term_months'] ?? 12), 1);

    $termEnd = new DateTime($item['start_date']);
    $termEnd->modify('+' . $initialTerm . ' months');

    $deadline = $applyNotice($termEnd);
    $guard = 0;
    while ($deadline->format('Y-m-d') < $today && $guard < 1200) {
        // Deadline already passed: contract auto-renewed into the next term.
        $termEnd->modify('+' . $renewalTerm . ' months');
        $deadline = $applyNotice($termEnd);
        $guard++;
    }

    return [
        'deadline' => $deadline->format('Y-m-d'),
        'term_end' => $termEnd->format('Y-m-d'),
    ];
}
