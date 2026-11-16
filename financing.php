<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/price_helpers.php';
require_once 'includes/financing_calculations.php';

require_once 'includes/paperless.php';
$paperlessEnabled = paperlessConfig($db, $userId) !== null;
$paperlessDocCounts = $paperlessEnabled ? paperlessDocCounts($db, $userId, 'loan') : [];
$showArchived = isset($_GET['state']) && $_GET['state'] === 'archived';
$detailId = isset($_GET['id']) ? intval($_GET['id']) : 0;

$mainCurrencyId = $main_currency;
$mainCurrencyCode = $currencies[$mainCurrencyId]['code'] ?? 'EUR';
$today = date('Y-m-d');

$sql = "SELECT * FROM loans WHERE user_id = :userId AND archived = :archived ORDER BY name COLLATE NOCASE ASC";
$stmt = $db->prepare($sql);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':archived', $showArchived ? 1 : 0, SQLITE3_INTEGER);
$result = $stmt->execute();

$loans = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $loans[] = $row;
}

// Compute schedule summaries for all loans (for the card list).
foreach ($loans as &$loan) {
  $loanTypeVal = $loan['loan_type'] ?? 'annuity';
  if ($loanTypeVal === 'bausparen') {
    $loan['extra_payments'] = [];
    $loan['schedule'] = computeBausparSchedule($loan, $today);
  } else {
    $loan['inputs'] = loadLoanInputs($db, $userId, $loan);
    $loan['extra_payments'] = $loan['inputs']['extras'];
    $loan['schedule'] = computeLoanSchedule($loan, $loan['extra_payments'], $today, $loan['inputs']['overrides'], $loan['inputs']['anchors'], $loan['inputs']['redeem_on']);
  }
}
unset($loan);

// Links between loans and the Bausparverträge that pay them off.
$bausparNames = [];
$replacesMap = [];
$linkStmt = $db->prepare("SELECT id, name, loan_type, replaced_by_loan_id FROM loans WHERE user_id = :userId AND archived = 0");
$linkStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$linkResult = $linkStmt->execute();
while ($linkRow = $linkResult->fetchArray(SQLITE3_ASSOC)) {
  if ($linkRow['loan_type'] === 'bausparen') {
    $bausparNames[$linkRow['id']] = $linkRow['name'];
  }
  if (!empty($linkRow['replaced_by_loan_id'])) {
    $replacesMap[$linkRow['replaced_by_loan_id']][] = $linkRow['name'];
  }
}

$detailLoan = null;
if ($detailId > 0) {
  foreach ($loans as $loan) {
    if ($loan['id'] == $detailId) {
      $detailLoan = $loan;
      $loanTypeDetail = $detailLoan['loan_type'] ?? 'annuity';
      if ($loanTypeDetail !== 'bausparen') {
        $detailLoan['overrides'] = $loan['inputs']['overrides'];
        $detailLoan['anchors'] = $loan['inputs']['anchors'];
        $detailLoan['schedule_plan'] = computeLoanSchedule($loan, [], $today, [], [], $loan['inputs']['redeem_on']);
      } else {
        $detailLoan['schedule_plan'] = null;
      }
      break;
    }
  }
}

$headerClass = count($loans) > 0 ? "main-actions" : "main-actions hidden";

function loanCurrencyFormat($amount, $loan, $currencies, $mainCurrencyCode)
{
  $code = $currencies[$loan['currency_id']]['code'] ?? $mainCurrencyCode;
  return CurrencyFormatter::format($amount, $code);
}
?>

<section class="contain">
  <header class="<?= $headerClass ?>" id="main-actions">
    <button class="button" onClick="addLoan()">
      <i class="fa-solid fa-circle-plus"></i>
      <?= translate('new_loan', $i18n) ?>
    </button>
    <div class="top-actions">
      <a href="financing.php<?= $showArchived ? '' : '?state=archived' ?>" class="button secondary-button"
        title="<?= translate($showArchived ? 'show_active' : 'show_archive', $i18n) ?>">
        <i class="fa-solid <?= $showArchived ? 'fa-list' : 'fa-box-archive' ?>"></i>
        <span class="hideOnMobile"><?= translate($showArchived ? 'show_active' : 'show_archive', $i18n) ?></span>
      </a>
    </div>
  </header>

  <?php if ($showArchived): ?>
    <h2 class="archive-title"><i class="fa-solid fa-box-archive"></i> <?= translate('archive', $i18n) ?></h2>
  <?php endif; ?>

  <div class="module-cards" id="loans">
    <?php foreach ($loans as $loan):
      $loanTypeVal = $loan['loan_type'] ?? 'annuity';
      $isBausparen = ($loanTypeVal === 'bausparen');
      $isInterestOnly = ($loanTypeVal === 'interest_only');
      $schedule = $loan['schedule'];
      $hasError = $schedule['error'] !== null;
      $isDetail = $detailLoan !== null && $loan['id'] == $detailLoan['id'];

      if ($isBausparen && !$hasError) {
        $phase = $schedule['phase'] ?? 'savings';
        $minPct = max(1.0, (float)($loan['bausparen_min_savings_pct'] ?? 40));
        $targetSavings = $loan['principal'] * $minPct / 100;
        if ($phase === 'repayment') {
          $paidPercent = $schedule['loan_amount'] > 0
            ? min(100, round($schedule['paid_principal_as_of'] / $schedule['loan_amount'] * 100, 1))
            : 100;
        } else {
          $paidPercent = $targetSavings > 0
            ? max(0, min(100, round($schedule['balance_as_of'] / $targetSavings * 100, 1)))
            : 0;
        }
      } else {
        $paidPercent = $hasError || $loan['principal'] <= 0
          ? 0
          : min(100, round($schedule['paid_principal_as_of'] / $loan['principal'] * 100, 1));
        $phase = 'repayment';
      }
      ?>
      <div class="module-card loan-card <?= $loan['archived'] ? 'archived' : '' ?> <?= $isDetail ? 'selected' : '' ?>"
        data-id="<?= $loan['id'] ?>">
        <div class="module-card-main"
          onClick="window.location='financing.php?id=<?= $loan['id'] ?><?= $showArchived ? '&state=archived' : '' ?>'">
          <div class="module-card-icon">
            <i class="fa-solid <?= $isBausparen ? 'fa-piggy-bank' : 'fa-landmark' ?>"></i>
          </div>
          <div class="module-card-info">
            <span class="module-card-title">
              <?= htmlspecialchars($loan['name']) ?>
              <?php if ($isInterestOnly && !$hasError): ?>
                <span class="interest-only-badge"><?= translate('interest_only_badge', $i18n) ?></span>
              <?php endif; ?>
            </span>
            <span class="module-card-subtitle"><?= htmlspecialchars($loan['lender'] ?? '') ?></span>
            <?php if (!empty($loan['replaced_by_loan_id']) && isset($bausparNames[$loan['replaced_by_loan_id']])): ?>
              <span class="module-card-detail loan-link"><i class="fa-solid fa-link"></i>
                <?= sprintf(translate('loan_replaced_by_card', $i18n), htmlspecialchars($bausparNames[$loan['replaced_by_loan_id']]), !empty($loan['inputs']['redeem_on']) ? date('m/Y', strtotime($loan['inputs']['redeem_on'])) : '—') ?></span>
            <?php endif; ?>
            <?php if (!empty($replacesMap[$loan['id']])): ?>
              <span class="module-card-detail loan-link"><i class="fa-solid fa-link"></i>
                <?= sprintf(translate('loan_replaces_card', $i18n), htmlspecialchars(implode(', ', $replacesMap[$loan['id']]))) ?></span>
            <?php endif; ?>
            <?php if ($hasError): ?>
              <span class="module-card-detail error-text"><?= translate('loan_rate_below_interest', $i18n) ?></span>
            <?php else: ?>
              <?php if ($isBausparen): ?>
                <span class="deadline-badge" style="margin:3px 0 2px; width:fit-content;">
                  <?= translate($phase === 'repayment' ? 'repayment_phase' : 'savings_phase', $i18n) ?>
                </span>
              <?php endif; ?>
              <div class="progress-bar">
                <div class="progress-fill" style="width: <?= $paidPercent ?>%"></div>
              </div>
              <span class="module-card-detail">
                <?php if ($isBausparen): ?>
                  <?= $paidPercent ?> % ·
                  <?= translate($phase === 'repayment' ? 'payoff_date' : 'bausparen_payout_date', $i18n) ?>:
                  <?php
                  $dateToShow = ($phase === 'repayment' && $schedule['payoff_date'])
                    ? $schedule['payoff_date']
                    : $schedule['payout_date'];
                  echo $dateToShow ? date('m/Y', strtotime($dateToShow)) : '—';
                  ?>
                <?php else: ?>
                  <?= $paidPercent ?> % <?= translate('repaid', $i18n) ?> ·
                  <?= translate('payoff_date', $i18n) ?>: <?= date('m/Y', strtotime($schedule['payoff_date'])) ?>
                <?php endif; ?>
              </span>
            <?php endif; ?>
          </div>
          <div class="module-card-price">
            <span>
              <?php if ($isBausparen && !$hasError && $phase === 'savings'): ?>
                <?= loanCurrencyFormat($schedule['balance_as_of'], $loan, $currencies, $mainCurrencyCode) ?>
              <?php else: ?>
                <?= loanCurrencyFormat($schedule['balance_as_of'], $loan, $currencies, $mainCurrencyCode) ?>
              <?php endif; ?>
            </span>
            <span class="module-card-cycle">
              <?= $isBausparen && $phase === 'savings' ? translate('savings_balance', $i18n) : translate('remaining_debt', $i18n) ?>
            </span>
          </div>
        </div>
        <div class="module-card-footer">
          <?php if (!empty($paperlessDocCounts[$loan['id']])): ?>
            <span class="deadline-badge doc-badge" title="<?= translate('paperless_documents', $i18n) ?>"><i class="fa-solid fa-paperclip"></i> <?= $paperlessDocCounts[$loan['id']] ?></span>
          <?php endif; ?>
          <span class="module-card-detail">
            <?php if ($isBausparen): ?>
              <?= translate('monthly_rate', $i18n) ?>:
              <?= loanCurrencyFormat($loan['monthly_rate'], $loan, $currencies, $mainCurrencyCode) ?>
              <?= translate('savings_phase', $i18n) ?>
            <?php else: ?>
              <?= translate('monthly_rate', $i18n) ?>:
              <?= loanCurrencyFormat($loan['monthly_rate'], $loan, $currencies, $mainCurrencyCode) ?>
            <?php endif; ?>
          </span>
          <span class="module-card-actions">
            <button class="image-button" title="<?= translate('edit', $i18n) ?>"
              onClick="openEditLoan(event, <?= $loan['id'] ?>)">
              <i class="fa-solid fa-pen"></i>
            </button>
            <?php if ($loan['archived']): ?>
              <button class="image-button" title="<?= translate('unarchive', $i18n) ?>"
                onClick="archiveLoan(event, <?= $loan['id'] ?>, 0)">
                <i class="fa-solid fa-box-open"></i>
              </button>
            <?php else: ?>
              <button class="image-button" title="<?= translate('archive', $i18n) ?>"
                onClick="archiveLoan(event, <?= $loan['id'] ?>, 1)">
                <i class="fa-solid fa-box-archive"></i>
              </button>
            <?php endif; ?>
          </span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (count($loans) == 0): ?>
    <div class="empty-page">
      <img src="images/siteimages/empty.png" alt="<?= translate('empty_page', $i18n) ?>" />
      <p>
        <?= translate($showArchived ? 'no_archived_items' : 'no_loans_yet', $i18n) ?>
      </p>
      <?php if (!$showArchived): ?>
        <button class="button" onClick="addLoan()">
          <i class="fa-solid fa-circle-plus"></i>
          <?= translate('add_first_loan', $i18n) ?>
        </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($detailLoan !== null && $detailLoan['schedule']['error'] === null):
    $schedule = $detailLoan['schedule'];
    $loanTypeDetail = $detailLoan['loan_type'] ?? 'annuity';
    $isBausparenDetail = ($loanTypeDetail === 'bausparen');
    $loanCode = $currencies[$detailLoan['currency_id']]['code'] ?? $mainCurrencyCode;
    ?>

    <?php if ($isBausparenDetail):
      $bsPhase = $schedule['phase'];
      $savingsRows = $schedule['savings_rows'];
      $repaymentRows = $schedule['repayment_rows'];

      // Build chart data: savings line + debt line
      $bsLabels = [];
      $bsSavings = [];
      $bsDebt = [];
      foreach ($savingsRows as $row) {
        $bsLabels[] = date('m/Y', strtotime($row['date']));
        $bsSavings[] = $row['balance'];
        $bsDebt[] = null;
      }
      foreach ($repaymentRows as $row) {
        $bsLabels[] = date('m/Y', strtotime($row['date']));
        $bsSavings[] = null;
        $bsDebt[] = $row['balance'];
      }
      ?>
      <div class="loan-detail" id="loan-detail">
        <h2><?= htmlspecialchars($detailLoan['name']) ?><?= $detailLoan['lender'] ? ' — ' . htmlspecialchars($detailLoan['lender']) : '' ?></h2>

        <div class="bausparen-phases">
          <div class="phase-bar <?= $bsPhase === 'savings' ? 'active' : '' ?>">
            <i class="fa-solid fa-piggy-bank"></i>
            <?= translate('savings_phase', $i18n) ?>
          </div>
          <div class="phase-bar">
            <i class="fa-solid fa-arrow-right-arrow-left"></i>
            <?= translate('payout_phase', $i18n) ?>
          </div>
          <div class="phase-bar <?= $bsPhase === 'repayment' ? 'active' : '' ?>">
            <i class="fa-solid fa-landmark"></i>
            <?= translate('repayment_phase', $i18n) ?>
          </div>
        </div>

        <div class="statistics">
          <div class="statistic">
            <span><?= CurrencyFormatter::format($schedule['balance_as_of'], $loanCode) ?></span>
            <div class="title">
              <?= $bsPhase === 'savings' ? translate('savings_balance', $i18n) : translate('remaining_debt_today', $i18n) ?>
            </div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($detailLoan['principal'], $loanCode) ?></span>
            <div class="title"><?= translate('bausparen_target', $i18n) ?></div>
          </div>
          <?php if ($schedule['fee'] > 0): ?>
            <div class="statistic">
              <span><?= CurrencyFormatter::format(-$schedule['fee'], $loanCode) ?></span>
              <div class="title"><?= translate('bausparen_fee', $i18n) ?></div>
            </div>
          <?php endif; ?>
          <div class="statistic">
            <span><?= $schedule['payout_date'] ? date('m/Y', strtotime($schedule['payout_date'])) : '—' ?></span>
            <div class="title"><?= translate('bausparen_payout_date', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($schedule['accumulated_savings'], $loanCode) ?></span>
            <div class="title"><?= translate('savings_balance', $i18n) ?> <?= translate('payout_phase', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($schedule['loan_amount'], $loanCode) ?></span>
            <div class="title"><?= translate('loan_portion', $i18n) ?></div>
          </div>
          <?php if ($schedule['payoff_date']): ?>
            <div class="statistic">
              <span><?= date('m/Y', strtotime($schedule['payoff_date'])) ?></span>
              <div class="title"><?= translate('payoff_date', $i18n) ?></div>
            </div>
          <?php endif; ?>
        </div>

        <div class="graphs">
          <div class="graph x2">
            <h3><?= translate('balance_over_time', $i18n) ?></h3>
            <div style="position:relative; height:250px; width:100%;">
              <div id="loanBalanceChart" class="apex-chart"></div>
            </div>
          </div>
        </div>

        <?php if (count($repaymentRows) > 0): ?>
          <div class="loan-schedule">
            <h3><?= translate('amortization_schedule', $i18n) ?> — <?= translate('repayment_phase', $i18n) ?></h3>
            <table class="schedule-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th><?= translate('date', $i18n) ?></th>
                  <th><?= translate('rate', $i18n) ?></th>
                  <th><?= translate('interest', $i18n) ?></th>
                  <th><?= translate('principal_portion', $i18n) ?></th>
                  <th><?= translate('remaining_debt', $i18n) ?></th>
                </tr>
              </thead>
              <tbody>
                <?php
                $currentMonth = date('Y-m');
                $lastYear = null;
                foreach ($repaymentRows as $row):
                  $rowYear = substr($row['date'], 0, 4);
                  $rowMonth = substr($row['date'], 0, 7);
                  if ($rowYear !== $lastYear):
                    $lastYear = $rowYear;
                    ?>
                    <tr class="schedule-year-row" onclick="toggleScheduleYear('r<?= $rowYear ?>')">
                      <td colspan="6"><i class="fa-solid fa-chevron-down"></i> <?= $rowYear ?></td>
                    </tr>
                  <?php endif; ?>
                  <tr class="schedule-row schedule-year-r<?= $rowYear ?> <?= $rowMonth === $currentMonth ? 'current' : '' ?>">
                    <td><?= $row['n'] ?></td>
                    <td><?= date('d.m.Y', strtotime($row['date'])) ?></td>
                    <td><?= CurrencyFormatter::format($row['payment'], $loanCode) ?></td>
                    <td><?= CurrencyFormatter::format($row['interest'], $loanCode) ?></td>
                    <td><?= CurrencyFormatter::format($row['principal'], $loanCode) ?></td>
                    <td><?= CurrencyFormatter::format($row['balance'], $loanCode) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    <?php else:
      // ── Annuity / Interest-only detail view ────────────────────────
      $plan = $detailLoan['schedule_plan'];
      $savedInterest = max(0, round($plan['total_interest'] - $schedule['total_interest'], 2));

      $chartLabels = [];
      $chartActual = [];
      foreach ($schedule['rows'] as $row) {
        $chartLabels[] = date('m/Y', strtotime($row['date']));
        $chartActual[] = $row['balance'];
      }
      $chartPlan = [];
      foreach ($plan['rows'] as $row) {
        $chartPlan[] = ['label' => date('m/Y', strtotime($row['date'])), 'y' => $row['balance']];
      }
      // Repayment of the Bauspardarlehen that follows the redemption, drawn in its own colour.
      $chartBauspar = [];
      if (!empty($detailLoan['replaced_by_loan_id']) && !empty($schedule['redeemed_on'])) {
        $bsStmt = $db->prepare("SELECT * FROM loans WHERE id = :id AND user_id = :userId AND loan_type = 'bausparen'");
        $bsStmt->bindValue(':id', $detailLoan['replaced_by_loan_id'], SQLITE3_INTEGER);
        $bsStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $bsLoan = $bsStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $bsSchedule = $bsLoan ? computeBausparSchedule($bsLoan) : null;
        if ($bsSchedule && $bsSchedule['error'] === null && !empty($bsSchedule['repayment_rows'])) {
          $chartBauspar[] = ['label' => date('m/Y', strtotime($schedule['redeemed_on'])), 'y' => $bsSchedule['loan_amount']];
          foreach ($bsSchedule['repayment_rows'] as $bsRow) {
            $chartBauspar[] = ['label' => date('m/Y', strtotime($bsRow['date'])), 'y' => $bsRow['balance']];
          }
        }
      }
      $todayIndex = 0;
      foreach ($schedule['rows'] as $i => $row) {
        if ($row['date'] <= $today) $todayIndex = $i;
      }
      $interestOnlyMonths = (int)($detailLoan['interest_only_months'] ?? 0);
      ?>
      <div class="loan-detail" id="loan-detail">
        <h2>
          <?= htmlspecialchars($detailLoan['name']) ?><?= $detailLoan['lender'] ? ' — ' . htmlspecialchars($detailLoan['lender']) : '' ?>
          <?php if ($loanTypeDetail === 'interest_only'): ?>
            <span class="interest-only-badge"><?= translate('interest_only_badge', $i18n) ?></span>
          <?php endif; ?>
        </h2>

        <div class="statistics">
          <div class="statistic">
            <span><?= CurrencyFormatter::format($schedule['balance_as_of'], $loanCode) ?></span>
            <div class="title"><?= translate('remaining_debt_today', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($schedule['paid_principal_as_of'], $loanCode) ?></span>
            <div class="title"><?= translate('already_repaid', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($schedule['paid_interest_as_of'], $loanCode) ?></span>
            <div class="title"><?= translate('interest_paid', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($savedInterest, $loanCode) ?></span>
            <div class="title"><?= translate('interest_saved_extra_payments', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= date('m/Y', strtotime($schedule['payoff_date'])) ?></span>
            <div class="title"><?= translate('payoff_date', $i18n) ?></div>
          </div>
        </div>

        <?php
        $fixedEnd = $detailLoan['fixed_interest_end'] ?? '';
        $fixedBalance = floatval($detailLoan['principal']);
        foreach ($schedule['rows'] as $fixedRow) {
          if ($fixedRow['date'] > $fixedEnd) {
            break;
          }
          $fixedBalance = $fixedRow['balance'];
        }
        if (!empty($fixedEnd) && $fixedEnd >= $today && $fixedBalance > 0):
          $fixedMonths = (int) round((strtotime($fixedEnd) - strtotime($today)) / 86400 / 30.44);
          ?>
          <div class="fixed-interest-box" id="fixed-interest-box" data-balance="<?= $fixedBalance ?>"
            data-rate="<?= floatval($detailLoan['monthly_rate']) ?>" data-end="<?= $fixedEnd ?>"
            data-currency="<?= $loanCode ?>">
            <h3>
              <i class="fa-solid fa-percent"></i>
              <?= translate('fixed_interest_end', $i18n) ?>: <?= date('d.m.Y', strtotime($fixedEnd)) ?>
              <span class="fixed-interest-months">(<?= $fixedMonths ?> <?= translate('months_remaining', $i18n) ?>)</span>
            </h3>
            <p>
              <?= translate('remaining_debt_at_fixed_end', $i18n) ?>:
              <strong><?= CurrencyFormatter::format($fixedBalance, $loanCode) ?></strong>
            </p>
            <div class="inline-form">
              <label for="followup-rate"><?= translate('followup_interest_rate', $i18n) ?></label>
              <input type="number" step="0.01" min="0" id="followup-rate"
                value="<?= floatval($detailLoan['interest_rate']) ?>">
            </div>
            <p id="followup-result">
              <?= translate('followup_payoff', $i18n) ?>: <strong id="followup-payoff">–</strong>
              · <?= translate('followup_interest_total', $i18n) ?>: <strong id="followup-interest">–</strong>
            </p>
            <p id="followup-error" class="followup-error" hidden><?= translate('followup_rate_too_low', $i18n) ?></p>
          </div>
        <?php endif; ?>

        <div class="graphs">
          <div class="graph x2">
            <h3><?= translate('balance_over_time', $i18n) ?></h3>
            <div style="position:relative; height:250px; width:100%;">
              <div id="loanBalanceChart" class="apex-chart"></div>
            </div>
          </div>
        </div>

        <?php if (!empty($detailLoan['replaced_by_loan_id']) && isset($bausparNames[$detailLoan['replaced_by_loan_id']]) && $schedule['redeemed_on']): ?>
          <p class="redemption-note"><i class="fa-solid fa-link"></i>
            <?php
            $redemptionAmount = 0.0;
            foreach ($schedule['rows'] as $redemptionRow) {
              if (!empty($redemptionRow['redemption'])) {
                $redemptionAmount = $redemptionRow['payment'];
              }
            }
            echo sprintf(translate('loan_redemption_note', $i18n), date('d.m.Y', strtotime($schedule['redeemed_on'])), htmlspecialchars($bausparNames[$detailLoan['replaced_by_loan_id']]), CurrencyFormatter::format($redemptionAmount, $loanCode));
            ?></p>
        <?php endif; ?>

        <div class="loan-anchors">
          <h3><?= translate('loan_anchor_title', $i18n) ?></h3>
          <p class="loan-anchor-hint"><?= translate('loan_anchor_hint', $i18n) ?></p>
          <form id="anchor-form" class="inline-form">
            <div class="date-wrapper">
              <input type="date" id="anchor-date" value="<?= $today ?>" required>
            </div>
            <input type="number" step="0.01" min="0" id="anchor-balance" placeholder="<?= translate('loan_anchor_balance', $i18n) ?>" required>
            <button type="submit" class="button thin">
              <i class="fa-solid fa-circle-plus"></i> <?= translate('add', $i18n) ?>
            </button>
          </form>
          <?php if (!empty($detailLoan['anchors'])):
            $appliedByDate = [];
            foreach ($schedule['anchors_applied'] as $applied) {
              $appliedByDate[$applied['date']] = $applied;
            }
            ?>
            <table class="schedule-table extra-payments-table">
              <thead>
                <tr>
                  <th><?= translate('date', $i18n) ?></th>
                  <th><?= translate('loan_anchor_balance', $i18n) ?></th>
                  <th><?= translate('loan_anchor_calculated', $i18n) ?></th>
                  <th><?= translate('loan_anchor_diff', $i18n) ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($detailLoan['anchors'] as $anchor): $applied = $appliedByDate[$anchor['anchor_date']] ?? null; ?>
                  <tr>
                    <td><?= date('d.m.Y', strtotime($anchor['anchor_date'])) ?></td>
                    <td><?= CurrencyFormatter::format($anchor['balance'], $loanCode) ?></td>
                    <td><?= $applied ? CurrencyFormatter::format($applied['calculated'], $loanCode) : '—' ?></td>
                    <td><?= $applied ? ($applied['diff'] > 0 ? '+' : '') . CurrencyFormatter::format($applied['diff'], $loanCode) : '—' ?></td>
                    <td>
                      <button class="image-button" title="<?= translate('delete', $i18n) ?>"
                        onClick="deleteAnchor(event, <?= $anchor['id'] ?>)">
                        <i class="fa-solid fa-trash"></i>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="loan-extra-payments">
          <h3><?= translate('extra_payments', $i18n) ?> (<?= translate('extra_payments_hint', $i18n) ?>)</h3>
          <form id="extra-payment-form" class="inline-form">
            <div class="date-wrapper">
              <input type="date" id="extra-payment-date" value="<?= $today ?>" required>
            </div>
            <input type="number" step="0.01" min="0.01" id="extra-payment-amount"
              placeholder="<?= translate('amount', $i18n) ?>" required>
            <button type="submit" class="button thin">
              <i class="fa-solid fa-circle-plus"></i> <?= translate('add', $i18n) ?>
            </button>
          </form>
          <?php if (count($detailLoan['extra_payments']) > 0): ?>
            <table class="schedule-table extra-payments-table">
              <thead>
                <tr>
                  <th><?= translate('date', $i18n) ?></th>
                  <th><?= translate('amount', $i18n) ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($detailLoan['extra_payments'] as $ep): ?>
                  <tr>
                    <td><?= date('d.m.Y', strtotime($ep['payment_date'])) ?></td>
                    <td><?= CurrencyFormatter::format($ep['amount'], $loanCode) ?></td>
                    <td>
                      <button class="image-button" title="<?= translate('delete', $i18n) ?>"
                        onClick="deleteExtraPayment(event, <?= $ep['id'] ?>)">
                        <i class="fa-solid fa-trash"></i>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="loan-schedule">
          <h3><?= translate('amortization_schedule', $i18n) ?></h3>
          <table class="schedule-table">
            <thead>
              <tr>
                <th>#</th>
                <th><?= translate('date', $i18n) ?></th>
                <th><?= translate('rate', $i18n) ?></th>
                <th><?= translate('interest', $i18n) ?></th>
                <th><?= translate('principal_portion', $i18n) ?></th>
                <th><?= translate('extra_payment', $i18n) ?></th>
                <th><?= translate('remaining_debt', $i18n) ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php
              $currentMonth = date('Y-m');
              $lastYear = null;
              foreach ($schedule['rows'] as $row):
                $rowYear = substr($row['date'], 0, 4);
                $rowMonth = substr($row['date'], 0, 7);
                $isOverride = !empty($row['override']);
                if ($rowYear !== $lastYear):
                  $lastYear = $rowYear;
                  ?>
                  <tr class="schedule-year-row" onclick="toggleScheduleYear('<?= $rowYear ?>')">
                    <td colspan="8"><i class="fa-solid fa-chevron-down"></i> <?= $rowYear ?></td>
                  </tr>
                <?php endif; ?>
                <tr class="schedule-row schedule-year-<?= $rowYear ?>
                  <?= $rowYear !== date('Y') ? 'collapsed' : '' ?>
                  <?= $rowMonth === $currentMonth ? 'current' : '' ?>
                  <?= !empty($row['interest_only']) && !$isOverride ? 'io-row' : '' ?>
                  <?= $isOverride ? 'override-row' : '' ?> <?= !empty($row['redemption']) ? 'redemption-row' : '' ?>">
                  <td><?= $row['n'] ?><?php if (isset($row['anchor_diff'])): ?> <i class="fa-solid fa-scale-balanced anchor-icon" title="<?= translate('loan_anchor_marker', $i18n) ?>: <?= ($row['anchor_diff'] > 0 ? '+' : '') . number_format($row['anchor_diff'], 2, ',', '.') ?>"></i><?php endif; ?></td>
                  <td><?= date('d.m.Y', strtotime($row['date'])) ?><?php if (!empty($row['redemption'])): ?> <small class="redemption-tag"><?= translate('loan_redemption_row', $i18n) ?></small><?php endif; ?></td>
                  <td><?= CurrencyFormatter::format($row['payment'], $loanCode) ?><?= $isOverride ? ' <i class="fa-solid fa-pen-to-square override-icon" title="custom"></i>' : '' ?></td>
                  <td><?= CurrencyFormatter::format($row['interest'], $loanCode) ?></td>
                  <td><?= (!empty($row['interest_only']) && !$isOverride) ? '<span style="opacity:0.5">—</span>' : CurrencyFormatter::format($row['principal'], $loanCode) ?></td>
                  <td><?= (!empty($row['extra']) && $row['extra'] > 0) ? CurrencyFormatter::format($row['extra'], $loanCode) : '—' ?></td>
                  <td><?= CurrencyFormatter::format($row['balance'], $loanCode) ?></td>
                  <td class="schedule-edit-cell">
                    <?php if (empty($row['redemption'])): ?>
                    <button class="icon-button" title="<?= translate('edit', $i18n) ?>"
                      onclick="openOverrideModal(<?= $row['n'] ?>, <?= $row['payment'] ?>, <?= $isOverride ? 'true' : 'false' ?>)">
                      <i class="fa-solid fa-pen"></i>
                    </button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="subscription-form" id="loan-form">
  <header>
    <h3 id="loan-form-title"><?= translate('add_loan', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeLoanForm()"></span>
  </header>
  <form action="endpoints/financing/add.php" method="post" id="loan-form-data">
    <input type="hidden" id="loan-id" name="id">

    <div class="form-group">
      <label for="loan-type"><?= translate('loan_type', $i18n) ?></label>
      <select id="loan-type" name="loan_type" onchange="toggleLoanTypeFields(this.value)">
        <option value="annuity"><?= translate('annuity_loan', $i18n) ?></option>
        <option value="interest_only"><?= translate('interest_only_loan', $i18n) ?></option>
        <option value="bausparen"><?= translate('bausparen', $i18n) ?></option>
      </select>
    </div>

    <div class="form-group">
      <input type="text" id="loan-name" name="name" autocomplete="off"
        placeholder="<?= translate('loan_name', $i18n) ?>" required>
    </div>

    <div class="form-group">
      <input type="text" id="loan-lender" name="lender" autocomplete="off"
        placeholder="<?= translate('lender', $i18n) ?>">
    </div>

    <div class="form-group-inline">
      <input type="number" step="0.01" min="0.01" id="loan-principal" name="principal" autocomplete="off"
        placeholder="<?= translate('principal', $i18n) ?>" required>
      <select id="loan-currency" name="currency_id">
        <?php foreach ($currencies as $currency): ?>
          <option value="<?= $currency['id'] ?>" <?= $currency['id'] == $main_currency ? 'selected' : '' ?>>
            <?= $currency['name'] ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Annuity / Interest-only fields -->
    <div id="fields-annuity" class="loan-type-fields visible">
      <div class="form-group">
        <div class="inline">
          <div class="split50">
            <label for="loan-interest-rate" id="label-interest-rate"><?= translate('interest_rate_pa', $i18n) ?></label>
            <input type="number" step="0.001" min="0" id="loan-interest-rate" name="interest_rate" autocomplete="off">
          </div>
          <div class="split50">
            <label for="loan-monthly-rate" id="label-monthly-rate"><?= translate('monthly_rate', $i18n) ?></label>
            <input type="number" step="0.01" min="0.01" id="loan-monthly-rate" name="monthly_rate" autocomplete="off">
          </div>
        </div>
      </div>
    </div>

    <!-- Interest-only extra field -->
    <div id="fields-interest-only" class="loan-type-fields">
      <div class="form-group">
        <label for="loan-io-months"><?= translate('interest_only_months', $i18n) ?></label>
        <input type="number" min="0" id="loan-io-months" name="interest_only_months" autocomplete="off"
          placeholder="<?= translate('interest_only_months', $i18n) ?>">
      </div>
    </div>

    <!-- Bausparvertrag fields -->
    <div id="fields-bausparen" class="loan-type-fields">
      <div class="form-group">
        <div class="inline">
          <div class="split50">
            <label for="loan-bausparen-sparrate"><?= translate('monthly_rate', $i18n) ?> (<?= translate('savings_phase', $i18n) ?>)</label>
            <input type="number" step="0.01" min="0.01" id="loan-bausparen-sparrate"
              autocomplete="off" placeholder="z.B. 200">
          </div>
          <div class="split50">
            <label for="loan-bausparen-savings-interest"><?= translate('bausparen_savings_interest', $i18n) ?></label>
            <input type="number" step="0.001" min="0" id="loan-bausparen-savings-interest"
              autocomplete="off" placeholder="z.B. 0.1">
          </div>
        </div>
      </div>
      <div class="form-group">
        <div class="inline">
          <div class="split50">
            <label for="loan-bausparen-min-pct"><?= translate('bausparen_min_savings_pct', $i18n) ?></label>
            <input type="number" step="0.1" min="1" max="100" id="loan-bausparen-min-pct"
              name="bausparen_min_savings_pct" autocomplete="off" value="40" placeholder="40">
          </div>
          <div class="split50">
            <label for="loan-bausparen-loan-rate"><?= translate('bausparen_loan_rate', $i18n) ?></label>
            <input type="number" step="0.001" min="0" id="loan-bausparen-loan-rate"
              name="bausparen_loan_rate" autocomplete="off" placeholder="z.B. 2.5">
          </div>
        </div>
      </div>
      <div class="form-group">
        <div class="inline">
          <div class="split50">
            <label for="loan-bausparen-loan-monthly"><?= translate('bausparen_loan_monthly', $i18n) ?></label>
            <input type="number" step="0.01" min="0" id="loan-bausparen-loan-monthly"
              name="bausparen_loan_monthly" autocomplete="off">
          </div>
          <div class="split50">
            <label for="loan-bausparen-fee"><?= translate('bausparen_fee', $i18n) ?></label>
            <input type="number" step="0.01" min="0" id="loan-bausparen-fee"
              name="bausparen_fee" autocomplete="off" placeholder="<?= translate('bausparen_fee_placeholder', $i18n) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="loan-start-date"><?= translate('start_date', $i18n) ?></label>
          <div class="date-wrapper">
            <input type="date" id="loan-start-date" name="start_date" autocomplete="off" required>
          </div>
        </div>
        <div class="split50" id="field-term-months">
          <label for="loan-term-months"><?= translate('term_months_optional', $i18n) ?></label>
          <input type="number" min="1" id="loan-term-months" name="term_months" autocomplete="off">
        </div>
      </div>
    </div>

    <div class="form-group" id="field-fixed-interest-end">
      <label for="loan-fixed-interest-end"><?= translate('fixed_interest_end_optional', $i18n) ?></label>
      <div class="date-wrapper">
        <input type="date" id="loan-fixed-interest-end" name="fixed_interest_end" autocomplete="off">
      </div>
    </div>

    <?php
    $bausparOptions = $db->prepare("SELECT id, name FROM loans WHERE user_id = :userId AND loan_type = 'bausparen' AND archived = 0 ORDER BY name COLLATE NOCASE");
    $bausparOptions->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $bausparOptionsResult = $bausparOptions->execute();
    $bausparChoices = [];
    while ($choice = $bausparOptionsResult->fetchArray(SQLITE3_ASSOC)) {
      $bausparChoices[] = $choice;
    }
    ?>
    <div class="form-group" id="field-replaced-by" <?= count($bausparChoices) === 0 ? 'data-empty="1" style="display:none"' : '' ?>>
      <label for="loan-replaced-by"><?= translate('loan_replaced_by', $i18n) ?></label>
      <select id="loan-replaced-by" name="replaced_by_loan_id">
        <option value=""><?= translate('loan_not_replaced', $i18n) ?></option>
        <?php foreach ($bausparChoices as $choice): ?>
          <option value="<?= $choice['id'] ?>"><?= htmlspecialchars($choice['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <small class="loan-anchor-hint"><?= translate('loan_replaced_by_hint', $i18n) ?></small>
    </div>

    <div class="form-group">
      <input type="text" id="loan-notes" name="notes" autocomplete="off" placeholder="<?= translate('notes', $i18n) ?>">
    </div>

    <?php $paperlessType = 'loan'; require 'includes/paperless_panel.php'; ?>
    <?php $filesType = 'loan'; require 'includes/files_panel.php'; ?>

    <div class="buttons">
      <input type="button" value="<?= translate('delete', $i18n) ?>" class="warning-button left thin" id="delete-loan"
        style="display: none">
      <input type="button" value="<?= translate('cancel', $i18n) ?>" class="secondary-button thin"
        onClick="closeLoanForm()">
      <input type="submit" value="<?= translate('save', $i18n) ?>" class="thin" id="loan-save-button">
    </div>
  </form>
</section>

<?php if ($detailLoan !== null): ?>
<section class="subscription-form" id="override-modal">
  <header>
    <h3 id="override-modal-title"><?= translate('edit_payment', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onclick="closeOverrideModal()"></span>
  </header>
  <div class="form-group">
    <label><?= translate('rate', $i18n) ?> #<span id="override-period-label"></span></label>
    <input type="number" step="0.01" min="0" id="override-amount" autocomplete="off">
  </div>
  <div class="buttons">
    <input type="button" id="override-delete-btn" value="<?= translate('delete', $i18n) ?>"
      class="warning-button left thin" style="display:none" onclick="deleteOverride()">
    <input type="button" value="<?= translate('cancel', $i18n) ?>"
      class="secondary-button thin" onclick="closeOverrideModal()">
    <input type="button" value="<?= translate('save', $i18n) ?>"
      class="thin" onclick="saveOverride()">
  </div>
</section>
<?php endif; ?>

<script src="scripts/libs/apexcharts.min.js"></script>
<script src="scripts/apex-base.js?<?= $assetVersion ?>"></script>
<script src="scripts/paperless.js?<?= $assetVersion ?>"></script>
<script src="scripts/files.js?<?= $assetVersion ?>"></script>
<script src="scripts/financing.js?<?= $assetVersion ?>"></script>
<?php if ($detailLoan !== null && $detailLoan['schedule']['error'] === null):
  $loanTypeDetail2 = $detailLoan['loan_type'] ?? 'annuity';
  $isBausparenScript = ($loanTypeDetail2 === 'bausparen');
  ?>
  <script type="text/javascript">
    window.loanDetailId = <?= $detailLoan['id'] ?>;
    window.loanDetailType = "<?= $loanTypeDetail2 ?>";
    <?php if ($isBausparenScript): ?>
    window.onload = function () {
      loadBausparChart(
        "loanBalanceChart",
        <?= json_encode($bsLabels) ?>,
        <?= json_encode($bsSavings) ?>,
        <?= json_encode($bsDebt) ?>,
        "<?= $loanCode ?>"
      );
    };
    <?php else: ?>
    window.onload = function () {
      loadLoanBalanceChart(
        "loanBalanceChart",
        <?= json_encode($chartLabels) ?>,
        <?= json_encode($chartActual) ?>,
        <?= json_encode($chartPlan) ?>,
        <?= json_encode($todayIndex) ?>,
        "<?= $loanCode ?>",
        <?= json_encode($chartBauspar) ?>
      );
    };
    <?php endif; ?>
  </script>
<?php endif; ?>
<?php
$db->close();
require_once 'includes/footer.php';
?>
