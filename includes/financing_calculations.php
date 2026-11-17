<?php
// Amortization calculations for the financing module.
// Schedules are always computed on the fly so edits reflow instantly.

/**
 * Computes the full amortization schedule of an annuity loan.
 * Supports an optional interest-only period at the start (tilgungsfreie Phase).
 *
 * $anchors: balances taken from a bank statement (['anchor_date', 'balance']).
 *   Everything before the date is calculated as usual, after it the schedule
 *   continues from the real balance, so calculation drift does not accumulate.
 * $redeemOn: date on which the loan is paid off in one go (e.g. by the payout of
 *   a Bausparvertrag); the remaining balance becomes a final "redemption" row.
 */
function computeLoanSchedule(array $loan, array $extraPayments = [], ?string $asOfDate = null, array $overrides = [], array $anchors = [], ?string $redeemOn = null): array
{
    $asOf = $asOfDate ?? date('Y-m-d');
    $monthlyInterest = ((float) $loan['interest_rate']) / 100.0 / 12.0;
    $balance = (float) $loan['principal'];
    $rate = (float) $loan['monthly_rate'];
    $termMonths = !empty($loan['term_months']) ? (int) $loan['term_months'] : null;
    $interestOnlyMonths = (int) ($loan['interest_only_months'] ?? 0);
    // A tilgungsfreies Darlehen without a set interest-only period is interest-only for its whole term
    // (or until a Bausparvertrag redeems it) and repaid in one sum at the end - it needs no repayment rate.
    $isInterestOnlyLoan = ($loan['loan_type'] ?? 'annuity') === 'interest_only';
    if ($isInterestOnlyLoan && $interestOnlyMonths <= 0) {
        $interestOnlyMonths = $termMonths ?? 1200;
    }

    $result = [
        'error' => null,
        'rows' => [],
        'payoff_date' => null,
        'total_interest' => 0.0,
        'total_extra' => 0.0,
        'balance_as_of' => (float) $loan['principal'],
        'paid_principal_as_of' => 0.0,
        'paid_interest_as_of' => 0.0,
        'months_total' => 0,
        'anchors_applied' => [],
        'redeemed_on' => null,
    ];

    if ($balance <= 0 || ($rate <= 0 && !$isInterestOnlyLoan)) {
        $result['error'] = 'invalid_loan_parameters';
        return $result;
    }

    // Index overrides by period number.
    $overrideMap = [];
    foreach ($overrides as $ov) {
        $overrideMap[(int)$ov['period_n']] = (float)$ov['payment_amount'];
    }

    // Group extra payments by the month (Y-m) they fall in.
    $extrasByMonth = [];
    foreach ($extraPayments as $extra) {
        $key = substr($extra['payment_date'], 0, 7);
        $extrasByMonth[$key] = ($extrasByMonth[$key] ?? 0.0) + (float) $extra['amount'];
    }

    usort($anchors, fn($a, $b) => strcmp($a['anchor_date'], $b['anchor_date']));
    $anchorIdx = 0;
    $pendingAnchorDiff = null;
    // Adds a row and tags it when a statement balance was applied just before it.
    $pushRow = function (array $row) use (&$result, &$pendingAnchorDiff) {
        if ($pendingAnchorDiff !== null) {
            $row['anchor_diff'] = $pendingAnchorDiff;
            $pendingAnchorDiff = null;
        }
        $result['rows'][] = $row;
    };

    $startDay = (int) (new DateTime($loan['start_date']))->format('d');
    $n = 0;

    while ($balance > 0.005 && $n < 1200) {
        $n++;
        $payDate = new DateTime($loan['start_date']);
        $payDate->modify('first day of this month');
        $payDate->modify('+' . $n . ' months');
        $payDate->setDate(
            (int) $payDate->format('Y'),
            (int) $payDate->format('m'),
            min($startDay, (int) $payDate->format('t'))
        );
        $payDateStr = $payDate->format('Y-m-d');
        $monthKey = substr($payDateStr, 0, 7);

        // Statement balances that fall before this payment replace the calculated balance.
        while ($anchorIdx < count($anchors) && $anchors[$anchorIdx]['anchor_date'] < $payDateStr) {
            $bankBalance = max(0.0, round((float) $anchors[$anchorIdx]['balance'], 2));
            $result['anchors_applied'][] = [
                'id' => $anchors[$anchorIdx]['id'] ?? null,
                'date' => $anchors[$anchorIdx]['anchor_date'],
                'bank' => $bankBalance,
                'calculated' => round($balance, 2),
                'diff' => round($bankBalance - $balance, 2),
            ];
            $pendingAnchorDiff = round($bankBalance - $balance, 2);
            $balance = $bankBalance;
            if ($anchors[$anchorIdx]['anchor_date'] <= $asOf) {
                $result['balance_as_of'] = $balance;
            }
            $anchorIdx++;
        }
        if ($balance <= 0.005) {
            break;
        }

        // Paid off in one go before this payment would be due (e.g. Bausparvertrag payout).
        if ($redeemOn !== null && $payDateStr > $redeemOn) {
            $pushRow([
                'n' => $n,
                'date' => $redeemOn,
                'payment' => round($balance, 2),
                'interest' => 0.0,
                'principal' => round($balance, 2),
                'extra' => 0.0,
                'balance' => 0.0,
                'override' => false,
                'redemption' => true,
            ]);
            $result['redeemed_on'] = $redeemOn;
            if ($redeemOn <= $asOf) {
                $result['balance_as_of'] = 0.0;
            }
            $balance = 0.0;
            $result['payoff_date'] = $redeemOn;
            break;
        }

        $interest = round($balance * $monthlyInterest, 2);
        $isInterestOnly = ($n <= $interestOnlyMonths);
        $overridePayment = $overrideMap[$n] ?? null;

        if ($isInterestOnly && $termMonths !== null && $n >= $termMonths) {
            // Endfällig: the last month of the term repays the whole balance.
            $result['total_interest'] = round($result['total_interest'] + $interest, 2);
            $pushRow(['n' => $n, 'date' => $payDateStr, 'payment' => round($interest + $balance, 2), 'interest' => $interest,
                'principal' => round($balance, 2), 'extra' => 0.0, 'balance' => 0.0, 'override' => false]);
            if ($payDateStr <= $asOf) {
                $result['balance_as_of'] = 0.0;
                $result['paid_interest_as_of'] = round($result['paid_interest_as_of'] + $interest, 2);
            }
            $balance = 0.0;
            $result['payoff_date'] = $payDateStr;
            break;
        }

        if ($isInterestOnly) {
            // Tilgungsfreie Phase: only interest is paid, balance stays unchanged.
            $actualPayment = $overridePayment !== null ? $overridePayment : $interest;
            // If overridden, treat the excess as principal reduction.
            $principalOverride = max(0.0, round($actualPayment - $interest, 2));
            if ($principalOverride > 0) {
                $balance = round($balance - $principalOverride, 2);
            }
            $result['total_interest'] = round($result['total_interest'] + $interest, 2);
            $pushRow([
                'n' => $n,
                'date' => $payDateStr,
                'payment' => $actualPayment,
                'interest' => $interest,
                'principal' => $principalOverride,
                'extra' => 0.0,
                'balance' => max($balance, 0.0),
                'interest_only' => $principalOverride === 0.0,
                'override' => $overridePayment !== null,
            ]);
            if ($payDateStr <= $asOf) {
                $result['balance_as_of'] = max($balance, 0.0);
                $result['paid_interest_as_of'] = round($result['paid_interest_as_of'] + $interest, 2);
                $result['paid_principal_as_of'] = round($result['paid_principal_as_of'] + $principalOverride, 2);
            }
            continue;
        }

        // Planned rate change ("from 2027 600 € instead of 500 €"): the latest one up to this payment.
        foreach ($loan['rate_changes'] ?? [] as $rateChange) {
            if ($rateChange['valid_from'] <= $payDateStr) {
                $rate = (float) $rateChange['monthly_rate'];
            }
        }
        $effectiveRate = $overridePayment !== null ? $overridePayment : $rate;
        $principalPart = round($effectiveRate - $interest, 2);

        if ($principalPart <= 0) {
            // A manually overridden rate may be lower than the interest (e.g. a
            // reduced first instalment). In that case the uncovered interest is
            // capitalised (the balance grows) instead of aborting the schedule.
            // Only an unedited, structurally too-low rate is a real error.
            if ($overridePayment === null) {
                $result['error'] = 'rate_below_interest';
                return $result;
            }
        }

        $isLastTermMonth = ($termMonths !== null && ($n - $interestOnlyMonths) >= ($termMonths - $interestOnlyMonths));
        if ($principalPart > $balance || $isLastTermMonth) {
            $principalPart = min($principalPart, $balance);
            if ($isLastTermMonth) {
                $principalPart = $balance;
            }
        }

        $extra = $extrasByMonth[$monthKey] ?? 0.0;
        $extra = round(min($extra, max($balance - $principalPart, 0)), 2);

        $balance = round($balance - $principalPart - $extra, 2);
        $result['total_interest'] = round($result['total_interest'] + $interest, 2);
        $result['total_extra'] = round($result['total_extra'] + $extra, 2);

        $pushRow([
            'n' => $n,
            'date' => $payDateStr,
            'payment' => round($interest + $principalPart, 2),
            'interest' => $interest,
            'principal' => $principalPart,
            'extra' => $extra,
            'balance' => max($balance, 0.0),
            'override' => $overridePayment !== null,
        ]);

        if ($payDateStr <= $asOf) {
            $result['balance_as_of'] = max($balance, 0.0);
            $result['paid_principal_as_of'] = round($result['paid_principal_as_of'] + $principalPart + $extra, 2);
            $result['paid_interest_as_of'] = round($result['paid_interest_as_of'] + $interest, 2);
        }

        if ($balance <= 0.005 || $isLastTermMonth) {
            $result['payoff_date'] = $payDateStr;
            break;
        }
    }

    if ($result['payoff_date'] === null && count($result['rows']) > 0) {
        $result['payoff_date'] = end($result['rows'])['date'];
    }
    if ($redeemOn !== null && $result['redeemed_on'] === null && $result['rows'] && end($result['rows'])['date'] === $redeemOn) {
        $result['rows'][count($result['rows']) - 1]['redemption'] = true;
        $result['redeemed_on'] = $redeemOn;
    }

    // Paid principal follows from the current balance, so anchors and redemption stay consistent.
    $result['paid_principal_as_of'] = max(0.0, round((float) $loan['principal'] - $result['balance_as_of'], 2));
    $result['months_total'] = count($result['rows']);
    return $result;
}

/** Date of the last instalment of a loan with a term (start + term months, same day of month). */
function loanTermEnd(array $loan): ?string
{
    $term = (int) ($loan['term_months'] ?? 0);
    if ($term <= 0 || empty($loan['start_date'])) {
        return null;
    }
    $date = new DateTime($loan['start_date']);
    $day = (int) $date->format('d');
    $date->modify('first day of this month')->modify('+' . $term . ' months');
    $date->setDate((int) $date->format('Y'), (int) $date->format('m'), min($day, (int) $date->format('t')));
    return $date->format('Y-m-d');
}

/** End of the term of the loan a Bausparvertrag replaces; null when none or without a term. */
function bausparPayoutFromLoan(SQLite3 $db, int $userId, array $bausparLoan): ?string
{
    $stmt = $db->prepare("SELECT * FROM loans WHERE user_id = :userId AND replaced_by_loan_id = :id AND archived = 0 ORDER BY id LIMIT 1");
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':id', $bausparLoan['id'], SQLITE3_INTEGER);
    $replaced = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $replaced ? loanTermEnd($replaced) : null;
}

/** Statement balances, extra payments and overrides of a loan, plus the redemption date. */
function loadLoanInputs(SQLite3 $db, int $userId, array $loan): array
{
    $fetch = function (string $table, string $order) use ($db, $userId, $loan): array {
        $stmt = $db->prepare("SELECT * FROM $table WHERE loan_id = :loanId AND user_id = :userId ORDER BY $order ASC");
        $stmt->bindValue(':loanId', $loan['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $rows = [];
        $result = $stmt->execute();
        while ($result && $row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    };

    $redeemOn = null;
    if (!empty($loan['replaced_by_loan_id'])) {
        $stmt = $db->prepare("SELECT * FROM loans WHERE id = :id AND user_id = :userId AND loan_type = 'bausparen'");
        $stmt->bindValue(':id', $loan['replaced_by_loan_id'], SQLITE3_INTEGER);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $bausparLoan = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($bausparLoan) {
            $redeemOn = loanTermEnd($loan) ?? (computeBausparSchedule($bausparLoan)['payout_date'] ?? null);
        }
    }

    return [
        'extras' => $fetch('loan_extra_payments', 'payment_date'),
        'overrides' => $fetch('loan_schedule_overrides', 'period_n'),
        'anchors' => $fetch('loan_balance_anchors', 'anchor_date'),
        'rate_changes' => $fetch('loan_rate_changes', 'valid_from'),
        'redeem_on' => $redeemOn,
    ];
}

/**
 * Schedule of any loan type with everything the user has entered applied.
 * $plan = true: the original plan without extra payments, overrides and statement balances.
 */
function loadLoanSchedule(SQLite3 $db, int $userId, array $loan, ?string $asOf = null, bool $plan = false): array
{
    if (($loan['loan_type'] ?? 'annuity') === 'bausparen') {
        return computeBausparSchedule($loan, $asOf, bausparPayoutFromLoan($db, $userId, $loan));
    }
    $in = loadLoanInputs($db, $userId, $loan);
    $loan['rate_changes'] = $in['rate_changes'];
    return $plan
        ? computeLoanSchedule($loan, [], $asOf, [], [], $in['redeem_on'])
        : computeLoanSchedule($loan, $in['extras'], $asOf, $in['overrides'], $in['anchors'], $in['redeem_on']);
}

/**
 * Computes the three-phase schedule for a Bausparvertrag.
 *
 * Phase 1 – Sparphase: Monthly deposits accumulate with Guthabenzins until
 *   savings reach bausparen_min_savings_pct % of the Bausparsumme (principal).
 * Phase 2 – Zuteilung: Savings liquidated, loan = principal - accumulated_savings.
 * Phase 3 – Tilgungsphase: Annuity repayment of the loan portion at
 *   bausparen_loan_rate with bausparen_loan_monthly monthly payment.
 *
 * $payoutOn: fixed payout date - the end of the term of the loan the contract replaces.
 *   The savings phase then runs until that date instead of until the minimum savings.
 */
function computeBausparSchedule(array $loan, ?string $asOfDate = null, ?string $payoutOn = null): array
{
    $asOf = $asOfDate ?? date('Y-m-d');

    $bausparsumme   = (float) $loan['principal'];           // total target
    $guthabenzins   = (float) $loan['interest_rate'] / 100 / 12; // monthly savings interest
    $sparrate       = (float) $loan['monthly_rate'];        // monthly deposit
    $minPct         = max(1.0, (float) ($loan['bausparen_min_savings_pct'] ?? 40));
    $minSavings     = $bausparsumme * $minPct / 100;
    $loanRateMonthly = (float) ($loan['bausparen_loan_rate'] ?? 0) / 100 / 12;
    $loanMonthly    = (float) ($loan['bausparen_loan_monthly'] ?? 0);
    $fee            = max(0.0, (float) ($loan['bausparen_fee'] ?? 0)); // Abschlussgebühr

    $result = [
        'error' => null,
        'loan_type' => 'bausparen',
        'savings_rows' => [],
        'fee' => $fee,
        'payout_date' => null,
        'accumulated_savings' => 0.0,
        'loan_amount' => 0.0,
        'total_savings_interest' => 0.0,
        'repayment_rows' => [],
        'payoff_date' => null,
        'total_loan_interest' => 0.0,
        'phase' => 'savings',
        'balance_as_of' => 0.0,
        'paid_principal_as_of' => 0.0,
        'paid_interest_as_of' => 0.0,
        'months_total' => 0,
    ];

    if ($bausparsumme <= 0 || $sparrate <= 0) {
        $result['error'] = 'invalid_loan_parameters';
        return $result;
    }

    // ── Phase 1: Sparphase ────────────────────────────────────────────────
    $startDay = (int) (new DateTime($loan['start_date']))->format('d');
    // The Abschlussgebühr is offset against the first deposits: the balance starts at minus the fee.
    $savings = -$fee;
    $totalSavingsInterest = 0.0;
    $n = 0;
    $payoutDateStr = null;
    if ($fee > 0) {
        $result['savings_rows'][] = ['n' => 0, 'date' => $loan['start_date'], 'deposit' => 0.0, 'interest' => 0.0, 'balance' => $savings, 'fee' => $fee];
        if ($loan['start_date'] <= $asOf) {
            $result['balance_as_of'] = $savings;
        }
    }

    while ($n < 1200 && ($payoutOn !== null || $savings < $minSavings)) {
        $n++;
        $payDate = new DateTime($loan['start_date']);
        $payDate->modify('first day of this month');
        $payDate->modify('+' . $n . ' months');
        $payDate->setDate(
            (int) $payDate->format('Y'),
            (int) $payDate->format('m'),
            min($startDay, (int) $payDate->format('t'))
        );
        $payDateStr = $payDate->format('Y-m-d');
        if ($payoutOn !== null && $payDateStr > $payoutOn) {
            break;
        }

        $interestEarned = $savings > 0 ? round($savings * $guthabenzins, 2) : 0.0; // no interest on a negative balance
        $savings = round($savings + $sparrate + $interestEarned, 2);
        $totalSavingsInterest = round($totalSavingsInterest + $interestEarned, 2);

        $result['savings_rows'][] = [
            'n' => $n,
            'date' => $payDateStr,
            'deposit' => $sparrate,
            'interest' => $interestEarned,
            'balance' => $savings,
        ];

        if ($payDateStr <= $asOf) {
            $result['balance_as_of'] = $savings;
        }
    }

    $payoutDateStr = $payoutOn ?? (end($result['savings_rows'])['date'] ?? null);
    $result['payout_date'] = $payoutDateStr;
    $result['payout_by_term'] = $payoutOn !== null;
    $result['accumulated_savings'] = $savings;
    $result['total_savings_interest'] = $totalSavingsInterest;

    if ($payoutDateStr !== null && $asOf >= $payoutDateStr) {
        $result['phase'] = 'repayment';
    }

    $loanAmount = max(0.0, round($bausparsumme - $savings, 2));
    $result['loan_amount'] = $loanAmount;

    if ($loanAmount <= 0.005) {
        $result['payoff_date'] = $payoutDateStr;
        $result['months_total'] = count($result['savings_rows']);
        return $result;
    }

    // ── Phase 3: Tilgungsphase ────────────────────────────────────────────
    if ($loanMonthly <= 0) {
        $result['error'] = 'no_repayment_rate';
        return $result;
    }

    $payoutDt = new DateTime($payoutDateStr);
    $payoutStartDay = (int) $payoutDt->format('d');
    $balance = $loanAmount;
    $m = 0;
    $totalLoanInterest = 0.0;

    // Reset repayment as_of tracking
    $result['paid_principal_as_of'] = 0.0;
    $result['paid_interest_as_of'] = 0.0;

    if ($result['phase'] === 'repayment') {
        $result['balance_as_of'] = $loanAmount; // refined below
    }

    while ($balance > 0.005 && $m < 1200) {
        $m++;
        $payDate = new DateTime($payoutDateStr);
        $payDate->modify('first day of this month');
        $payDate->modify('+' . $m . ' months');
        $payDate->setDate(
            (int) $payDate->format('Y'),
            (int) $payDate->format('m'),
            min($payoutStartDay, (int) $payDate->format('t'))
        );
        $payDateStr2 = $payDate->format('Y-m-d');

        $interest = round($balance * $loanRateMonthly, 2);
        $principalPart = round(min($loanMonthly - $interest, $balance), 2);

        if ($principalPart <= 0) {
            $result['error'] = 'rate_below_interest';
            return $result;
        }

        $balance = round($balance - $principalPart, 2);
        $totalLoanInterest = round($totalLoanInterest + $interest, 2);

        $result['repayment_rows'][] = [
            'n' => $m,
            'date' => $payDateStr2,
            'payment' => round($interest + $principalPart, 2),
            'interest' => $interest,
            'principal' => $principalPart,
            'balance' => max($balance, 0.0),
        ];

        if ($payDateStr2 <= $asOf) {
            $result['balance_as_of'] = max($balance, 0.0);
            $result['paid_principal_as_of'] = round($result['paid_principal_as_of'] + $principalPart, 2);
            $result['paid_interest_as_of'] = round($result['paid_interest_as_of'] + $interest, 2);
        }

        if ($balance <= 0.005) {
            $result['payoff_date'] = $payDateStr2;
            break;
        }
    }

    if ($result['payoff_date'] === null && count($result['repayment_rows']) > 0) {
        $result['payoff_date'] = end($result['repayment_rows'])['date'];
    }

    $result['total_loan_interest'] = $totalLoanInterest;
    $result['months_total'] = count($result['savings_rows']) + count($result['repayment_rows']);
    return $result;
}

/**
 * Extra repayment allowed in a calendar year and how much of it is used.
 * @return array{allowed: float, used: float, left: float}|null Null when no allowance is set.
 */
function extraRepaymentAllowance(array $loan, array $extraPayments, int $year): ?array
{
    $allowed = (float) ($loan['extra_allowance_amount'] ?? 0);
    if ($allowed <= 0 && (float) ($loan['extra_allowance_pct'] ?? 0) > 0) {
        $allowed = (float) $loan['principal'] * (float) $loan['extra_allowance_pct'] / 100;
    }
    if ($allowed <= 0) {
        return null;
    }
    $used = array_sum(array_map(fn($e) => (float) $e['amount'], array_filter($extraPayments, fn($e) => (int) substr($e['payment_date'], 0, 4) === $year)));
    return ['allowed' => round($allowed, 2), 'used' => round($used, 2), 'left' => round(max(0, $allowed - $used), 2)];
}

/**
 * All loans together: remaining debt, monthly rate, weighted interest, debt-free date and the
 * order in which extra repayments save most (highest interest first).
 * @param array $loans rows with 'schedule' (computeLoanSchedule) and interest_rate / monthly_rate
 */
function loansOverview(array $loans): array
{
    $active = array_filter($loans, fn($l) => ($l['loan_type'] ?? '') !== 'bausparen' && empty($l['schedule']['error']) && ($l['schedule']['balance_as_of'] ?? 0) > 0);
    $debt = array_sum(array_map(fn($l) => (float) $l['schedule']['balance_as_of'], $active));
    $weighted = $debt > 0 ? array_sum(array_map(fn($l) => (float) $l['schedule']['balance_as_of'] * (float) $l['interest_rate'], $active)) / $debt : 0.0;
    $payoffs = array_filter(array_map(fn($l) => $l['schedule']['payoff_date'] ?? null, $active));
    usort($active, fn($a, $b) => (float) $b['interest_rate'] <=> (float) $a['interest_rate']);
    return [
        'count' => count($active),
        'debt' => round($debt, 2),
        'monthly' => round(array_sum(array_map(fn($l) => (float) $l['monthly_rate'], $active)), 2),
        'interest' => round($weighted, 2),
        'debt_free' => $payoffs ? max($payoffs) : null,
        'priority' => array_map(fn($l) => ['id' => $l['id'], 'name' => $l['name'], 'rate' => (float) $l['interest_rate']], array_values($active)),
    ];
}
