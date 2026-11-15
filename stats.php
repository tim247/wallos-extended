<?php
require_once 'includes/header.php';
require_once 'includes/logo_theme_variant.php';
require_once 'includes/default_names.php';
require_once 'includes/html_text.php';


// Get code of main currency to display on statistics
$query = "SELECT c.code
          FROM currencies c
          INNER JOIN user u ON c.id = u.main_currency
          WHERE u.id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$code = $row['code'];

require_once 'includes/stats_calculations.php';
require_once 'includes/financing_calculations.php';
require_once 'includes/consumption_calculations.php';

/* ---------- Household finance extension: module aggregates ---------- */
$hfToday = date('Y-m-d');
$hfEndOfYear = date('Y') . '-12-31';

// Collected per-item data for the 12-month all-area cost trend.
$hfTrendContracts = [];   // [{start, monthly}]
$hfTrendInsurances = [];  // [{start, monthly}]
$hfTrendLoans = [];       // [{row, extra, currency_id}]
$hfTrendMeters = [];      // [{start, monthly}] (consumption only, excl. PV)

// Contracts
$hfContractsMonthly = 0.0;
$hfContractCount = 0;
$stmt = $db->prepare("SELECT * FROM contracts WHERE user_id = :userId AND archived = 0");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$res = $stmt->execute();
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
  $hfContractCount++;
  $price = getPriceConverted(floatval($row['price']), $row['currency_id'], $db, $userId);
  $monthly = getPricePerMonth($row['cycle'], $row['frequency'], $price);
  $hfContractsMonthly += $monthly;
  $hfTrendContracts[] = ['start' => $row['start_date'] ?? null, 'monthly' => $monthly];
}

// Insurances
$hfInsurancesMonthly = 0.0;
$hfInsuranceCount = 0;
$stmt = $db->prepare("SELECT * FROM insurances WHERE user_id = :userId AND archived = 0");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$res = $stmt->execute();
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
  $hfInsuranceCount++;
  $price = getPriceConverted(floatval($row['price']), $row['currency_id'], $db, $userId);
  $monthly = getPricePerMonth($row['cycle'], $row['frequency'], $price);
  $hfInsurancesMonthly += $monthly;
  $hfTrendInsurances[] = ['start' => $row['start_date'] ?? null, 'monthly' => $monthly];
}

// Loans
$hfLoansMonthly = 0.0;
$hfLoansRemaining = 0.0;
$hfLoanCount = 0;
$stmt = $db->prepare("SELECT * FROM loans WHERE user_id = :userId AND archived = 0");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$res = $stmt->execute();
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
  $loanType = $row['loan_type'] ?? 'annuity';
  $schedule = loadLoanSchedule($db, $userId, $row, $hfToday);
  if ($schedule['error'] !== null) {
    continue;
  }
  $hfLoanCount++;
  $monthlyRate = floatval($row['monthly_rate']);
  if ($schedule['balance_as_of'] > 0) {
    $hfLoansMonthly += getPriceConverted($monthlyRate, $row['currency_id'], $db, $userId);
  }
  $hfLoansRemaining += getPriceConverted($schedule['balance_as_of'], $row['currency_id'], $db, $userId);
  $hfTrendLoans[] = ['row' => $row, 'currency_id' => $row['currency_id'], 'type' => $loanType];
}

// Meters: estimated monthly consumption cost / PV earnings
$hfConsumptionMonthly = 0.0;
$hfPvEarningsMonthly = 0.0;
$hfMeterCount = 0;
$stmt = $db->prepare("SELECT * FROM meters WHERE user_id = :userId AND archived = 0");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$res = $stmt->execute();
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
  $hfMeterCount++;
  $rStmt = $db->prepare("SELECT reading_date, reading_value, is_meter_change FROM meter_readings WHERE meter_id = :meterId AND user_id = :userId ORDER BY reading_date ASC");
  $rStmt->bindValue(':meterId', $row['id'], SQLITE3_INTEGER);
  $rStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $rRes = $rStmt->execute();
  $readings = [];
  while ($r = $rRes->fetchArray(SQLITE3_ASSOC)) {
    $readings[] = $r;
  }
  $computed = computeConsumptionSegments($readings);
  $forecast = computeForecast($computed['segments'], $hfEndOfYear);
  if ($forecast === null || $forecast['per_day'] <= 0) {
    continue;
  }
  $isPv = $row['meter_type'] === 'pv_feed_in';
  $conversion = ($row['meter_type'] === 'gas' && !empty($row['conversion_factor'])) ? floatval($row['conversion_factor']) : 1.0;
  $unitsPerMonth = $forecast['per_day'] * 30.44 * $conversion;
  $price = getPriceConverted($unitsPerMonth * floatval($row['price_per_unit']), $row['currency_id'], $db, $userId);
  if ($isPv) {
    $hfPvEarningsMonthly += $price;
  } else {
    $baseFee = getPriceConverted(floatval($row['base_fee_monthly']), $row['currency_id'], $db, $userId);
    $hfConsumptionMonthly += $price + $baseFee;
    $firstReading = count($readings) > 0 ? $readings[0]['reading_date'] : null;
    $hfTrendMeters[] = ['start' => $firstReading, 'monthly' => $price + $baseFee];
  }
}

$hfSubscriptionsMonthly = $totalCostPerMonth ?? 0.0;
$hfTotalMonthlyBurden = $hfSubscriptionsMonthly + $hfContractsMonthly + $hfInsurancesMonthly + $hfLoansMonthly + $hfConsumptionMonthly;

// Doughnut: monthly burden by module.
$hfBurdenSplitDataPoints = [];
if ($hfSubscriptionsMonthly > 0) {
  $hfBurdenSplitDataPoints[] = ['label' => translate('subscriptions', $i18n), 'y' => round($hfSubscriptionsMonthly, 2)];
}
if ($hfContractsMonthly > 0) {
  $hfBurdenSplitDataPoints[] = ['label' => translate('contracts', $i18n), 'y' => round($hfContractsMonthly, 2)];
}
if ($hfInsurancesMonthly > 0) {
  $hfBurdenSplitDataPoints[] = ['label' => translate('insurances', $i18n), 'y' => round($hfInsurancesMonthly, 2)];
}
if ($hfLoansMonthly > 0) {
  $hfBurdenSplitDataPoints[] = ['label' => translate('financing', $i18n), 'y' => round($hfLoansMonthly, 2)];
}
if ($hfConsumptionMonthly > 0) {
  $hfBurdenSplitDataPoints[] = ['label' => translate('consumption', $i18n), 'y' => round($hfConsumptionMonthly, 2)];
}
$showBurdenSplitGraph = count($hfBurdenSplitDataPoints) > 1;
$hasHouseholdFinanceData = ($hfContractCount + $hfInsuranceCount + $hfLoanCount + $hfMeterCount) > 0;

/* ---------- All-area monthly cost trend (last 12 months) ----------
 * Reconstructs the total monthly burden across every module per month-end.
 * Subscriptions are rebuilt from the recorded price history; contracts,
 * insurances and consumption use their current monthly burden but only count
 * from the month they started/were first measured; loans use the actual
 * remaining schedule so they correctly drop out once paid off. This replaces
 * the subscription-only snapshot trend from stats_calculations.php. */
$subPriceHistory = [];
$stmt = $db->prepare("SELECT subscription_id, price, currency_id, effective_date FROM subscription_price_changes WHERE user_id = :userId AND applied = 1 ORDER BY effective_date ASC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$r = $stmt->execute();
while ($h = $r->fetchArray(SQLITE3_ASSOC)) {
  $subPriceHistory[$h['subscription_id']][] = $h;
}
$trendSubs = [];
$stmt = $db->prepare("SELECT id, cycle, frequency, currency_id, price, start_date FROM subscriptions WHERE user_id = :userId AND inactive = 0 AND archived = 0 AND cycle != 5");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$r = $stmt->execute();
while ($s = $r->fetchArray(SQLITE3_ASSOC)) {
  $trendSubs[] = $s;
}

$allAreaTrendDataPoints = [];
for ($mIdx = 11; $mIdx >= 0; $mIdx--) {
  $monthEnd = date('Y-m-t', strtotime("first day of -$mIdx months"));
  $monthLabel = date('m/Y', strtotime($monthEnd));
  $total = 0.0;

  // Subscriptions (price-history aware).
  foreach ($trendSubs as $sub) {
    if (!empty($sub['start_date']) && $sub['start_date'] > $monthEnd) {
      continue;
    }
    $priceAt = null;
    $currencyAt = $sub['currency_id'];
    foreach ($subPriceHistory[$sub['id']] ?? [] as $entry) {
      if ($entry['effective_date'] <= $monthEnd) {
        $priceAt = $entry['price'];
        $currencyAt = $entry['currency_id'];
      }
    }
    if ($priceAt === null) {
      $priceAt = $sub['price']; // no history recorded: fall back to current price
    }
    $priceAt = getPriceConverted($priceAt, $currencyAt, $db, $userId);
    $total += getPricePerMonth($sub['cycle'], $sub['frequency'], $priceAt);
  }

  // Contracts / insurances / consumption: active from their start month.
  foreach (array_merge($hfTrendContracts, $hfTrendInsurances, $hfTrendMeters) as $item) {
    if (empty($item['start']) || $item['start'] <= $monthEnd) {
      $total += $item['monthly'];
    }
  }

  // Loans: include the rate only while the loan is active in that month.
  foreach ($hfTrendLoans as $ln) {
    $lrow = $ln['row'];
    if (!empty($lrow['start_date']) && $lrow['start_date'] > $monthEnd) {
      continue;
    }
    $sched = loadLoanSchedule($db, $userId, $lrow, $monthEnd);
    if ($sched['error'] !== null) {
      continue;
    }
    if ($sched['balance_as_of'] > 0) {
      $total += getPriceConverted(floatval($lrow['monthly_rate']), $ln['currency_id'], $db, $userId);
    }
  }

  $allAreaTrendDataPoints[] = ['label' => $monthLabel, 'y' => round($total, 2)];
}

// Replace the subscription-only trend with the all-area trend.
if ($hasHouseholdFinanceData) {
  $totalMonthlyCostDataPoints = $allAreaTrendDataPoints;
  $showTotalMonthlyCostGraph = count($allAreaTrendDataPoints) > 1;
}
require_once 'includes/stats_extra_calculations.php';
require_once 'includes/upcoming_cancellations.php';

// Subscriptions flagged for cancellation, and what cancelling them would stop costing.
$upcomingCancellations = get_upcoming_cancellations($db, $userId);
$upcomingCancellationsCount = count($upcomingCancellations);
$potentialMonthlySavings = get_upcoming_cancellations_monthly_value($upcomingCancellations, $db, $userId);

?>
<section class="contain">
  <?php
  if ($showCantConverErrorMessage) {
    ?>
    <div class="error-box">
      <div class="error-message">
        <i class="fa-solid fa-exclamation-circle"></i>
        <?= translate('cant_convert_currency', $i18n) ?>
      </div>
    </div>
    <?php
  }
  ?>
  <div class="split-header">
    <h2>
      <?= translate('general_statistics', $i18n) ?> <span class="header-subtitle"><?= $statsSubtitle ?></span>
    </h2>
    <div class="filtermenu">
      <button class="button secondary-button" id="filtermenu-button" title="<?= translate("filter", $i18n) ?>">
        <i class="fa-solid fa-filter"></i>
      </button>
      <div class="filtermenu-content">
        <?php
        if (count($members) > 1) {
          ?>
          <div class="filtermenu-submenu">
            <div class="filter-title" onClick="toggleSubMenu('member')"><?= translate("member", $i18n) ?></div>
            <div class="filtermenu-submenu-content" id="filter-member">
              <?php
              foreach ($members as $member) {
                $isSelected = isset($_GET['member']) && in_array($member['id'], array_map('intval', explode(',', $_GET['member'])));
                if (($menuMemberCounts[$member['id']] ?? 0) == 0 && !$isSelected) {
                  continue;
                }
                $selectedClass = $isSelected ? 'selected' : '';
                ?>
                <div class="filter-item <?= $selectedClass ?>" data-memberid="<?= $member['id'] ?>"><?= htmlSafe($member['name']) ?>
                </div>
                <?php
              }
              ?>
            </div>
          </div>
          <?php
        }
        ?>
        <?php
        if (count($categories) > 1) {
          // sort categories by order
          usort($categories, function ($a, $b) {
            return $a['order'] - $b['order'];
          });
          ?>
          <div class="filtermenu-submenu">
            <div class="filter-title" onClick="toggleSubMenu('category')"><?= translate("category", $i18n) ?></div>
            <div class="filtermenu-submenu-content" id="filter-category">
              <?php
              // A category still carrying its untouched default name is
              // recognised against the account's own creation-language
              // default, not a literal English string - #1216 gives every
              // language its own default, so an account created in German
              // never had an English name to match here.
              $noCategoryDefaultName = default_no_category_name($userData['language'] ?? 'en');
              foreach ($categories as $category) {
                $isSelected = isset($_GET['category']) && in_array($category['id'], array_map('intval', explode(',', $_GET['category'])));
                if (($menuCategoryCounts[$category['id']] ?? 0) == 0 && !$isSelected) {
                  continue;
                }
                $categoryName = categoryLabel($category['name'], $i18n);
                $selectedClass = $isSelected ? 'selected' : '';
                ?>
                <div class="filter-item <?= $selectedClass ?>" data-categoryid="<?= $category['id'] ?>">
                  <?= htmlSafe($categoryName) ?>
                </div>
                <?php
              }
              ?>
            </div>
          </div>
          <?php
        }
        ?>
        <?php
        if (count($paymentMethods) > 1) {

          usort($paymentMethods, function ($a, $b) {
            return $a['order'] <=> $b['order'];
          });
          ?>
          <div class="filtermenu-submenu">
            <div class="filter-title" onClick="toggleSubMenu('payment')"><?= translate("payment_method", $i18n) ?></div>
            <div class="filtermenu-submenu-content" id="filter-payment">
              <?php
              foreach ($paymentMethods as $payment) {
                $isSelected = isset($_GET['payment']) && in_array($payment['id'], array_map('intval', explode(',', $_GET['payment'])));
                if (($menuPaymentCounts[$payment['id']] ?? 0) == 0 && !$isSelected) {
                  continue;
                }
                $selectedClass = $isSelected ? 'selected' : '';
                ?>
                <div class="filter-item <?= $selectedClass ?>" data-paymentid="<?= $payment['id'] ?>">
                  <?= htmlSafe($payment['name']) ?>
                </div>
                <?php
              }
              ?>
            </div>
          </div>
          <?php
        }
        ?>
        <?php
        if (isset($_GET['member']) || isset($_GET['category']) || isset($_GET['payment'])) {
          ?>
          <div class="filtermenu-submenu">
            <div class="filter-title filter-clear" onClick="clearFilters()">
              <i class="fa-solid fa-times-circle"></i> <?= translate("clear", $i18n) ?>
            </div>
          </div>
          <?php
        }
        ?>
      </div>
    </div>
  </div>
  </div>
  </div>
  <?php
  // Graph datasets for the split views
  $categoryDataPoints = [];
  if (isset($categoryCost)) {
    foreach ($categoryCost as $category) {
      if ($category['cost'] != 0) {
        $categoryDataPoints[] = [
          "label" => html_entity_decode(categoryLabel($category['name'], $i18n)),
          "y" => $category["cost"],
        ];
      }
    }
  }
  usort($categoryDataPoints, fn($a, $b) => $b['y'] <=> $a['y']);
  $showCategoryCostGraph = count($categoryDataPoints) > 1;

  $memberDataPoints = [];
  if (isset($memberCost)) {
    foreach ($memberCost as $member) {
      if ($member['cost'] != 0) {
        $memberDataPoints[] = [
          "label" => html_entity_decode($member['name']),
          "y" => $member["cost"],
        ];
      }
    }
  }
  usort($memberDataPoints, fn($a, $b) => $b['y'] <=> $a['y']);
  $showMemberCostGraph = count($memberDataPoints) > 1;

  $paymentMethodDataPoints = [];
  foreach ($paymentMethodCost as $paymentMethodId => $cost) {
    if ($cost > 0 && isset($paymentMethodsCount[$paymentMethodId])) {
      $paymentMethodDataPoints[] = [
        "label" => html_entity_decode($paymentMethodsCount[$paymentMethodId]['name']),
        "y" => round($cost, 2),
      ];
    }
  }
  usort($paymentMethodDataPoints, fn($a, $b) => $b['y'] <=> $a['y']);
  $showPaymentMethodsGraph = count($paymentMethodDataPoints) > 1;

  // Price development: reconstruct the total monthly cost of the current
  // active subscriptions over the last 12 months from the price history.
  $priceDevelopmentDataPoints = [];
  $priceHistoryBySubscription = [];
  $stmt = $db->prepare("SELECT subscription_id, price, effective_date FROM subscription_price_changes WHERE user_id = :userId AND applied = 1 ORDER BY effective_date ASC");
  $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $historyResult = $stmt->execute();
  while ($historyRow = $historyResult->fetchArray(SQLITE3_ASSOC)) {
    $priceHistoryBySubscription[$historyRow['subscription_id']][] = $historyRow;
  }

  $stmt = $db->prepare("SELECT id, cycle, frequency, currency_id, start_date FROM subscriptions WHERE user_id = :userId AND inactive = 0 AND archived = 0 AND cycle != 5");
  $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $subsResult = $stmt->execute();
  $historySubs = [];
  while ($subRow = $subsResult->fetchArray(SQLITE3_ASSOC)) {
    $historySubs[] = $subRow;
  }

  $hasMultipleHistoryEntries = false;
  foreach ($priceHistoryBySubscription as $entries) {
    if (count($entries) > 1) {
      $hasMultipleHistoryEntries = true;
      break;
    }
  }

  if ($hasMultipleHistoryEntries && count($historySubs) > 0) {
    for ($m = 11; $m >= 0; $m--) {
      $monthEnd = date('Y-m-t', strtotime("-$m months"));
      $monthLabel = date('m/Y', strtotime($monthEnd));
      $monthTotal = 0.0;
      foreach ($historySubs as $sub) {
        if (!empty($sub['start_date']) && $sub['start_date'] > $monthEnd) {
          continue;
        }
        $priceAt = null;
        foreach ($priceHistoryBySubscription[$sub['id']] ?? [] as $entry) {
          if ($entry['effective_date'] <= $monthEnd) {
            $priceAt = $entry['price'];
          }
        }
        if ($priceAt === null) {
          continue;
        }
        $priceAt = getPriceConverted($priceAt, $sub['currency_id'], $db, $userId);
        $monthTotal += getPricePerMonth($sub['cycle'], $sub['frequency'], $priceAt);
      }
      $priceDevelopmentDataPoints[] = [
        'label' => $monthLabel,
        'y' => round($monthTotal, 2),
      ];
    }
  }
  $showPriceDevelopmentGraph = count($priceDevelopmentDataPoints) > 1;

  $showMonthlyStats = !isset($_GET['budget']) || $_GET['budget'] === 'monthly';
  $showPeriodStats = !isset($_GET['budget']) || $_GET['budget'] === 'period';

  $showAnyGraph = $showCategoryCostGraph || $showMemberCostGraph || $showPaymentMethodsGraph || $showTotalMonthlyCostGraph
    || ($showMonthlyStats && $showVsMonthlyBudgetGraph) || ($showPeriodStats && $showVsPeriodBudgetGraph)
    || $showProjectionGraph || $showLifetimeGraph || $showCycleGraph || $showCurrencyGraph || $showHistogramGraph || $showYearlyNewGraph
    || $showPriceDevelopmentGraph || $showBurdenSplitGraph;

  $showTrendsSection = $showTotalMonthlyCostGraph || $showProjectionGraph || $monthOverMonthDelta !== null;
  $showBudgetSection = ($showMonthlyStats && (isset($monthlyBudgetUsed) || isset($monthlyBudgetLeft) || isset($monthlyOverBudgetAmount) || $showVsMonthlyBudgetGraph))
    || ($showPeriodStats && (isset($periodBudgetUsed) || isset($periodBudgetLeft) || isset($periodOverBudgetAmount) || $showVsPeriodBudgetGraph));
  $showSplitSection = $showMemberCostGraph || $showCategoryCostGraph || $showPaymentMethodsGraph || $showCycleGraph || $showCurrencyGraph || $showHistogramGraph;
  $showHistorySection = $totalLifetimeSpend > 0 || $oldestSubscription !== null || $averageSubscriptionAge !== null || $showLifetimeGraph || $showYearlyNewGraph;
  ?>

  <section class="stats-section">
    <h2><?= translate('overview', $i18n) ?></h2>
    <div class="statistics">
      <div class="statistic">
        <span><?= $activeSubscriptions ?></span>
        <div class="title"><?= translate('active_subscriptions', $i18n) ?></div>
      </div>
      <div class="statistic">
        <span><?= CurrencyFormatter::format($totalCostPerMonth, $code) ?></span>
        <div class="title"><?= translate('monthly_cost', $i18n) ?></div>
      </div>
      <div class="statistic">
        <span><?= CurrencyFormatter::format($totalCostPerYear, $code) ?></span>
        <div class="title"><?= translate('yearly_cost', $i18n) ?></div>
      </div>
      <?php
      if ($totalCostPerMonth > 0) {
        ?>
        <div class="statistic">
          <span><?= CurrencyFormatter::format($costPerDay, $code) ?></span>
          <div class="title"><?= translate('cost_per_day', $i18n) ?></div>
        </div>
        <?php
      }
      ?>
      <div class="statistic">
        <span><?= CurrencyFormatter::format($averageSubscriptionCost, $code) ?></span>
        <div class="title"><?= translate('average_monthly', $i18n) ?></div>
      </div>
      <div class="statistic short">
        <span><?= CurrencyFormatter::format($mostExpensiveSubscription['price'], $code) ?></span>
        <div class="title"><?= translate('most_expensive', $i18n) ?></div>
        <?php
        if (isset($mostExpensiveSubscription['logo']) && $mostExpensiveSubscription['logo'] != '') {
          $mostExpensiveLogoSrc = "images/uploads/logos/" . $mostExpensiveSubscription['logo'];
          $mostExpensiveLogoVariantSrc = !empty($mostExpensiveSubscription['logo_variant']) ? "images/uploads/logos/" . $mostExpensiveSubscription['logo_variant'] : null;
          ?>
          <div class="subtitle">
            <?= renderThemedLogoImg($mostExpensiveLogoSrc, $mostExpensiveLogoVariantSrc, $mostExpensiveSubscription['logo_text_color'] ?? null, '', 'alt="' . htmlSafe($mostExpensiveSubscription['name']) . '" title="' . htmlSafe($mostExpensiveSubscription['name']) . '"') ?>
          </div>
          <?php
        } else if (isset($mostExpensiveSubscription['name']) && $mostExpensiveSubscription['name'] != '') {
          ?>
          <div class="subtitle"><?= htmlSafe($mostExpensiveSubscription['name']) ?></div>
          <?php
        }
        ?>
      </div>
      <?php
      if ($cheapestSubscription !== null) {
        ?>
        <div class="statistic short">
          <span><?= CurrencyFormatter::format($cheapestSubscription['price'], $code) ?></span>
          <div class="title"><?= translate('cheapest_subscription', $i18n) ?></div>
          <?php
          if (!empty($cheapestSubscription['logo'])) {
            $cheapestLogoSrc = "images/uploads/logos/" . $cheapestSubscription['logo'];
            $cheapestLogoVariantSrc = !empty($cheapestSubscription['logo_variant']) ? "images/uploads/logos/" . $cheapestSubscription['logo_variant'] : null;
            ?>
            <div class="subtitle">
              <?= renderThemedLogoImg($cheapestLogoSrc, $cheapestLogoVariantSrc, $cheapestSubscription['logo_text_color'] ?? null, '', 'alt="' . htmlSafe($cheapestSubscription['name']) . '" title="' . htmlSafe($cheapestSubscription['name']) . '"') ?>
            </div>
            <?php
          } else if (!empty($cheapestSubscription['name'])) {
            ?>
            <div class="subtitle"><?= htmlSafe($cheapestSubscription['name']) ?></div>
            <?php
          }
          ?>
        </div>
        <?php
      }
      ?>
      <?php
      // A custom budget period takes over from the calendar month here: what is
      // left to pay only answers "can this pay cheque cover it" when it is
      // measured against the period the user actually budgets in.
      if ($periodScopesStatistics) {
        ?>
        <div class="statistic">
          <span><?= CurrencyFormatter::format($amountNeededThisPeriod, $code) ?></span>
          <div class="title"><?= translate('amount_due_this_period', $i18n) ?></div>
          <div class="period-range"><?= htmlspecialchars($budgetPeriodLabel, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <?php
      } else {
        ?>
        <div class="statistic">
          <span><?= CurrencyFormatter::format($amountDueThisMonth, $code) ?></span>
          <div class="title"><?= translate('amount_due', $i18n) ?></div>
        </div>
        <?php
      }
      ?>
      <?php
      if ($manualRenewalsCount > 0) {
        ?>
        <div class="statistic">
          <span><?= $manualRenewalsCount ?></span>
          <div class="title"><?= translate('manual_renewals', $i18n) ?></div>
        </div>
        <?php
      }
      ?>
    </div>
  </section>

  <?php
  ?>
  <?php if ($hasHouseholdFinanceData || $showPriceDevelopmentGraph) { ?>
    <section class="stats-section">
      <h2><?= translate('household', $i18n) ?></h2>
      <?php if ($hasHouseholdFinanceData) { ?>
      <div class="statistics">
      <div class="statistic">
        <span><?= CurrencyFormatter::format($hfTotalMonthlyBurden, $code) ?></span>
        <div class="title"><?= translate('total_monthly_burden', $i18n) ?></div>
      </div>
      <?php if ($hfContractCount > 0): ?>
        <a class="statistic" href="contracts.php">
          <span><?= CurrencyFormatter::format($hfContractsMonthly, $code) ?></span>
          <div class="title"><i class="fa-solid fa-file-signature"></i> <?= translate('contracts', $i18n) ?> (<?= $hfContractCount ?>)</div>
        </a>
      <?php endif; ?>
      <?php if ($hfInsuranceCount > 0): ?>
        <a class="statistic" href="insurance.php">
          <span><?= CurrencyFormatter::format($hfInsurancesMonthly, $code) ?></span>
          <div class="title"><i class="fa-solid fa-shield-halved"></i> <?= translate('insurances', $i18n) ?> (<?= $hfInsuranceCount ?>)</div>
        </a>
      <?php endif; ?>
      <?php if ($hfLoanCount > 0): ?>
        <a class="statistic" href="financing.php">
          <span><?= CurrencyFormatter::format($hfLoansMonthly, $code) ?></span>
          <div class="title"><i class="fa-solid fa-landmark"></i> <?= translate('financing_rates', $i18n) ?></div>
        </a>
        <?php if ($hfLoansRemaining > 0): ?>
          <a class="statistic" href="financing.php">
            <span><?= CurrencyFormatter::format($hfLoansRemaining, $code) ?></span>
            <div class="title"><i class="fa-solid fa-landmark"></i> <?= translate('remaining_debt', $i18n) ?></div>
          </a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($hfConsumptionMonthly > 0): ?>
        <a class="statistic" href="consumption.php">
          <span><?= CurrencyFormatter::format($hfConsumptionMonthly, $code) ?></span>
          <div class="title"><i class="fa-solid fa-gauge"></i> <?= translate('consumption', $i18n) ?></div>
        </a>
      <?php endif; ?>
      <?php if ($hfPvEarningsMonthly > 0): ?>
        <a class="statistic positive" href="consumption.php">
          <span><?= CurrencyFormatter::format($hfPvEarningsMonthly, $code) ?></span>
          <div class="title"><i class="fa-solid fa-solar-panel"></i> <?= translate('pv_earnings_monthly', $i18n) ?></div>
        </a>
      <?php endif; ?>
      </div>
      <?php } ?>
      <?php if ($showPriceDevelopmentGraph || $showBurdenSplitGraph) { ?>
        <div class="graphs">
          <?php if ($showBurdenSplitGraph) { ?>
            <section class="graph">
              <header>
                <?= translate('monthly_burden_split', $i18n) ?>
                <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
              </header>
              <div id="burdenSplitChart" style="width: 100%;"></div>
            </section>
          <?php } ?>
          <?php if ($showPriceDevelopmentGraph) { ?>
            <section class="graph x2">
              <header>
                <?= translate('price_development', $i18n) ?>
                <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
              </header>
              <div id="priceDevelopmentChart" style="width: 100%;"></div>
            </section>
          <?php } ?>
        </div>
      <?php } ?>
    </section>
  <?php } ?>
  <?php
  if ($showTrendsSection) {
    ?>
    <section class="stats-section">
      <h2><?= translate('trends_and_forecast', $i18n) ?></h2>
      <?php
      if ($monthOverMonthDelta !== null || ($showProjectionGraph && $heaviestMonth !== null) || $monthsOverBudget !== null) {
        ?>
        <div class="statistics">
          <?php
          if ($monthOverMonthDelta !== null) {
            $momSign = $monthOverMonthDelta >= 0 ? '+' : '-';
            ?>
            <div class="statistic short">
              <span><?= $momSign ?><?= CurrencyFormatter::format(abs($monthOverMonthDelta), $code) ?></span>
              <div class="title"><?= translate('vs_last_month', $i18n) ?></div>
              <?php
              if ($monthOverMonthPercentage !== null) {
                ?>
                <div class="subtitle"><?= $momSign ?><?= number_format(abs($monthOverMonthPercentage), 1) ?>%</div>
                <?php
              }
              ?>
            </div>
            <?php
          }
          if ($showProjectionGraph && $heaviestMonth !== null) {
            ?>
            <div class="statistic short">
              <span><?= CurrencyFormatter::format($heaviestMonth['total'], $code) ?></span>
              <div class="title"><?= translate('heaviest_month', $i18n) ?></div>
              <div class="subtitle capitalize"><?= $heaviestMonth['label'] ?></div>
            </div>
            <?php
          }
          if ($monthsOverBudget !== null) {
            ?>
            <div class="statistic">
              <span><?= $monthsOverBudget ?></span>
              <div class="title"><?= translate('months_over_budget', $i18n) ?></div>
            </div>
            <?php
          }
          ?>
        </div>
        <?php
      }
      if ($showTotalMonthlyCostGraph || $showProjectionGraph) {
        ?>
        <div class="graphs">
          <?php
          if ($showTotalMonthlyCostGraph) {
            ?>
            <section class="graph x2">
              <header>
                <?= translate('total_cost_trend', $i18n) ?>
                <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
              </header>
              <div id="totalMonthlyCostChart" style="width: 100%;"></div>
            </section>
            <?php
          }
          if ($showProjectionGraph) {
            ?>
            <section class="graph x2">
              <header>
                <?= translate('projected_cost', $i18n) ?>
              </header>
              <div id="projectionChart" style="width: 100%;"></div>
            </section>
            <?php
          }
          ?>
        </div>
        <?php
      }
      ?>
    </section>
    <?php
  }

  if ($showBudgetSection) {
    ?>
    <section class="stats-section">
      <h2><?= translate('budget', $i18n) ?></h2>
      <div class="statistics">
        <?php
        if ($showMonthlyStats && isset($monthlyBudgetUsed)) {
          ?>
          <div class="statistic">
            <span><?= number_format($monthlyBudgetUsed, 2) ?>%</span>
            <div class="title"><?= translate('monthly_budget', $i18n) ?> - <?= translate('percentage_budget_used', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($monthlyBudgetLeft, $code) ?></span>
            <div class="title"><?= translate('monthly_budget', $i18n) ?> - <?= translate('budget_remaining', $i18n) ?></div>
          </div>
          <?php
          if (isset($monthlyOverBudgetAmount)) {
            ?>
            <div class="statistic">
              <span><?= CurrencyFormatter::format($monthlyOverBudgetAmount, $code) ?></span>
              <div class="title"><?= translate('monthly_budget', $i18n) ?> - <?= translate('amount_over_budget', $i18n) ?></div>
            </div>
            <?php
          }
        }

        if ($showPeriodStats && isset($periodBudgetUsed)) {
          $periodRangeLabel = htmlspecialchars($budgetPeriodLabel, ENT_QUOTES, 'UTF-8');
          ?>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($amountNeededThisPeriod, $code) ?></span>
            <div class="title"><?= translate('amount_needed_this_period', $i18n) ?></div>
            <div class="period-range"><?= $periodRangeLabel ?></div>
          </div>
          <div class="statistic">
            <span><?= number_format($periodBudgetUsed, 2) ?>%</span>
            <div class="title"><?= translate('period_budget', $i18n) ?> - <?= translate('percentage_budget_used', $i18n) ?></div>
            <div class="period-range"><?= $periodRangeLabel ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($periodBudgetLeft, $code) ?></span>
            <div class="title"><?= translate('period_budget', $i18n) ?> - <?= translate('budget_remaining', $i18n) ?></div>
            <div class="period-range"><?= $periodRangeLabel ?></div>
          </div>
          <?php
          if (isset($periodOverBudgetAmount)) {
            ?>
            <div class="statistic">
              <span><?= CurrencyFormatter::format($periodOverBudgetAmount, $code) ?></span>
              <div class="title"><?= translate('period_budget', $i18n) ?> - <?= translate('amount_over_budget', $i18n) ?></div>
              <div class="period-range"><?= $periodRangeLabel ?></div>
            </div>
            <?php
          }
        }
        ?>
      </div>
      <?php
      if (($showMonthlyStats && $showVsMonthlyBudgetGraph) || ($showPeriodStats && $showVsPeriodBudgetGraph)) {
        ?>
        <div class="graphs">
          <?php if ($showMonthlyStats && $showVsMonthlyBudgetGraph) { ?>
            <section class="graph">
              <header>
                <?= translate('cost_vs_monthly_budget', $i18n) ?> (<?= CurrencyFormatter::format($monthlyBudget, $code) ?>)
                <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
              </header>
              <div id="monthlyBudgetVsCostChart" style="width: 100%;"></div>
            </section>
          <?php } ?>
          <?php if ($showPeriodStats && $showVsPeriodBudgetGraph) { ?>
            <section class="graph">
              <header>
                <?= translate('cost_vs_period_budget', $i18n) ?> (<?= CurrencyFormatter::format($periodBudget, $code) ?>)
                <div class="sub-header">(<?= translate('amount_needed_this_period', $i18n) ?>)</div>
              </header>
              <div id="periodBudgetVsCostChart" style="width: 100%;"></div>
            </section>
          <?php } ?>
        </div>
        <?php
      }
      ?>
    </section>
    <?php
  }

  if ($showSplitSection) {
    ?>
    <section class="stats-section">
      <h2><?= translate('split_views', $i18n) ?></h2>
      <div class="graphs">
        <?php
        if ($showMemberCostGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('household_split', $i18n) ?>
              <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
            </header>
            <div id="memberSplitChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        if ($showCategoryCostGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('category_split', $i18n) ?>
              <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
            </header>
            <div id="categorySplitChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        if ($showPaymentMethodsGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('payment_method_split', $i18n) ?>
              <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
            </header>
            <div id="paymentMethidSplitChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        if ($showCycleGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('billing_cycle_split', $i18n) ?>
              <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
            </header>
            <div id="cycleSplitChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        if ($showCurrencyGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('currency_split', $i18n) ?>
              <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>)</div>
            </header>
            <div id="currencySplitChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        if ($showHistogramGraph) {
          ?>
          <section class="graph">
            <header>
              <?= translate('price_distribution', $i18n) ?>
              <div class="sub-header">(<?= translate('monthly_cost', $i18n) ?>, <?= $code ?>)</div>
            </header>
            <div id="priceHistogramChart" style="width: 100%;"></div>
          </section>
          <?php
        }
        ?>
      </div>
    </section>
    <?php
  }

  if ($showHistorySection) {
    ?>
    <section class="stats-section">
      <h2><?= translate('history_and_lifetime', $i18n) ?></h2>
      <?php
      if ($totalLifetimeSpend > 0 || $oldestSubscription !== null || $averageSubscriptionAge !== null) {
        ?>
        <div class="statistics">
          <?php
          if ($totalLifetimeSpend > 0) {
            ?>
            <div class="statistic">
              <span><?= CurrencyFormatter::format($totalLifetimeSpend, $code) ?></span>
              <div class="title"><?= translate('all_time_spend', $i18n) ?></div>
            </div>
            <?php
          }
          if ($oldestSubscription !== null) {
            ?>
            <div class="statistic short">
              <span><?= number_format($oldestSubscription['years'], 1) ?></span>
              <div class="title"><?= translate('oldest_subscription', $i18n) ?></div>
              <?php
              if (!empty($oldestSubscription['logo'])) {
                $oldestLogoSrc = "images/uploads/logos/" . $oldestSubscription['logo'];
                $oldestLogoVariantSrc = !empty($oldestSubscription['logo_variant']) ? "images/uploads/logos/" . $oldestSubscription['logo_variant'] : null;
                ?>
                <div class="subtitle">
                  <?= renderThemedLogoImg($oldestLogoSrc, $oldestLogoVariantSrc, $oldestSubscription['logo_text_color'] ?? null, '', 'alt="' . htmlSafe($oldestSubscription['name']) . '" title="' . htmlSafe($oldestSubscription['name']) . '"') ?>
                </div>
                <?php
              } else if (!empty($oldestSubscription['name'])) {
                ?>
                <div class="subtitle"><?= htmlSafe($oldestSubscription['name']) ?></div>
                <?php
              }
              ?>
            </div>
            <?php
          }
          if ($averageSubscriptionAge !== null) {
            ?>
            <div class="statistic">
              <span><?= number_format($averageSubscriptionAge, 1) ?></span>
              <div class="title"><?= translate('average_subscription_age', $i18n) ?></div>
            </div>
            <?php
          }
          ?>
        </div>
        <?php
      }
      if ($showLifetimeGraph || $showYearlyNewGraph) {
        ?>
        <div class="graphs">
          <?php
          if ($showYearlyNewGraph) {
            ?>
            <section class="graph">
              <header>
                <?= translate('new_subscriptions_per_year', $i18n) ?>
              </header>
              <div id="newPerYearChart" style="width: 100%;"></div>
            </section>
            <?php
          }
          if ($showLifetimeGraph) {
            ?>
            <section class="graph">
              <header>
                <?= translate('lifetime_spend', $i18n) ?>
                <div class="sub-header"><?= translate('estimated_from_current_prices', $i18n) ?></div>
              </header>
              <div id="lifetimeSpendChart" style="width: 100%;"></div>
            </section>
            <?php
          }
          ?>
        </div>
        <?php
      }
      ?>
    </section>
    <?php
  }

  if ($upcomingCancellationsCount > 0) {
    ?>
    <section class="stats-section">
      <h2><?= translate('upcoming_cancellations', $i18n) ?></h2>
      <div class="statistics">
        <div class="statistic">
          <span><?= $upcomingCancellationsCount ?></span>
          <div class="title"><?= translate('subscriptions', $i18n) ?></div>
        </div>
        <?php
        if ($potentialMonthlySavings > 0) {
          ?>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($potentialMonthlySavings, $code) ?></span>
            <div class="title"><?= translate('potential_monthly_savings', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($potentialMonthlySavings * 12, $code) ?></span>
            <div class="title"><?= translate('potential_yearly_savings', $i18n) ?></div>
          </div>
          <?php
        }
        ?>
      </div>
    </section>
    <?php
  }

  if ($inactiveSubscriptions > 0) {
    ?>
    <section class="stats-section">
      <h2><?= translate('your_savings', $i18n) ?></h2>
      <div class="statistics">
        <div class="statistic">
          <span><?= $inactiveSubscriptions ?></span>
          <div class="title"><?= translate('inactive_subscriptions', $i18n) ?></div>
        </div>
        <?php
        if ($totalSavingsPerMonth > 0) {
          ?>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($totalSavingsPerMonth, $code) ?></span>
            <div class="title"><?= translate('monthly_savings', $i18n) ?></div>
          </div>
          <div class="statistic">
            <span><?= CurrencyFormatter::format($totalSavingsPerMonth * 12, $code) ?></span>
            <div class="title"><?= translate('yearly_savings', $i18n) ?></div>
          </div>
          <?php
        }
        ?>
      </div>
    </section>
    <?php
  }
  ?>

</section>
<?php
if ($showAnyGraph) {
  ?>
  <script src="scripts/libs/apexcharts.min.js"></script>
  <script type="text/javascript">
    window.onload = function () {
      loadLineGraph("totalMonthlyCostChart", <?php echo json_encode($totalMonthlyCostDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showTotalMonthlyCostGraph ? 1 : 0 ?>);
      loadLineGraph("priceDevelopmentChart", <?php echo json_encode($priceDevelopmentDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showPriceDevelopmentGraph ? 1 : 0 ?>);
      loadGraph("burdenSplitChart", <?php echo json_encode($hfBurdenSplitDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showBurdenSplitGraph ? 1 : 0 ?>);
      loadBarGraph("projectionChart", <?php echo json_encode($projectionDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showProjectionGraph ? 1 : 0 ?>, <?= isset($budget) && $budget > 0 ? $budget : 'null' ?>);
      loadGraph("categorySplitChart", <?php echo json_encode($categoryDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showCategoryCostGraph ? 1 : 0 ?>);
      loadGraph("memberSplitChart", <?php echo json_encode($memberDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showMemberCostGraph ? 1 : 0 ?>);
      loadGraph("paymentMethidSplitChart", <?php echo json_encode($paymentMethodDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showPaymentMethodsGraph ? 1 : 0 ?>);
      <?php if ($showMonthlyStats && $showVsMonthlyBudgetGraph) { ?>
      loadGraph("monthlyBudgetVsCostChart", <?php echo json_encode($vsMonthlyBudgetDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", 1);
      <?php } ?>
      <?php if ($showPeriodStats && $showVsPeriodBudgetGraph) { ?>
      loadGraph("periodBudgetVsCostChart", <?php echo json_encode($vsPeriodBudgetDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", 1);
      <?php } ?>
      loadGraph("cycleSplitChart", <?php echo json_encode($cycleDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showCycleGraph ? 1 : 0 ?>);
      loadGraph("currencySplitChart", <?php echo json_encode($currencyDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showCurrencyGraph ? 1 : 0 ?>);
      loadBarGraph("priceHistogramChart", <?php echo json_encode($histogramDataPoints, JSON_NUMERIC_CHECK); ?>, "", <?= $showHistogramGraph ? 1 : 0 ?>, null);
      loadBarGraph("newPerYearChart", <?php echo json_encode($newPerYearDataPoints, JSON_NUMERIC_CHECK); ?>, "", <?= $showYearlyNewGraph ? 1 : 0 ?>, null);
      loadHorizontalBarGraph("lifetimeSpendChart", <?php echo json_encode($lifetimeDataPoints, JSON_NUMERIC_CHECK); ?>, "<?= $code ?>", <?= $showLifetimeGraph ? 1 : 0 ?>);
    }
  </script>
  <?php
}
?>
<script src="scripts/stats.js?<?= $assetVersion ?>"></script>
<?php
require_once 'includes/footer.php';
?>