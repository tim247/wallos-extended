<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/consumption_calculations.php';
require_once 'includes/consumption_insights.php';
require_once 'includes/price_helpers.php';
require_once 'includes/homeassistant.php';
$haEnabled = homeAssistantConfig($db, $userId) !== null;

$showArchived = isset($_GET['state']) && $_GET['state'] === 'archived';

$meterTypes = [
  'electricity' => ['label' => 'meter_type_electricity', 'icon' => 'fa-bolt', 'unit' => 'kWh'],
  'water' => ['label' => 'meter_type_water', 'icon' => 'fa-droplet', 'unit' => 'm³'],
  'gas' => ['label' => 'meter_type_gas', 'icon' => 'fa-fire', 'unit' => 'm³'],
  'pv_feed_in' => ['label' => 'meter_type_pv_feed_in', 'icon' => 'fa-solar-panel', 'unit' => 'kWh'],
  'pv_generation' => ['label' => 'meter_type_pv_generation', 'icon' => 'fa-sun', 'unit' => 'kWh'],
];

$mainCurrencyId = $main_currency;
$mainCurrencyCode = $currencies[$mainCurrencyId]['code'] ?? 'EUR';
$today = date('Y-m-d');
$endOfYear = date('Y') . '-12-31';
$consumptionSettings = loadConsumptionSettings($db, $userId);
require_once 'includes/cancellation_helpers.php';
$supplyContracts = [];
$contractStmt = $db->prepare('SELECT * FROM contracts WHERE user_id = :userId AND archived = 0 ORDER BY name COLLATE NOCASE');
$contractStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$contractResult = $contractStmt->execute();
while ($contractRow = $contractResult->fetchArray(SQLITE3_ASSOC)) {
  $contractRow['monthly'] = getPricePerMonth($contractRow['cycle'], $contractRow['frequency'], (float) $contractRow['price']);
  $contractRow['deadline'] = $contractRow['cancellation_status'] ? null : (getNextCancellationDeadline($contractRow, date('Y-m-d'))['deadline'] ?? null);
  $supplyContracts[$contractRow['id']] = $contractRow;
}
$benchPersons = (int) ($consumptionSettings['persons'] ?: ($consumptionSettings['persons_default'] ?? 0));

$stmt = $db->prepare("SELECT * FROM meters WHERE user_id = :userId AND archived = :archived ORDER BY sort_order = 0, sort_order, meter_type ASC, name COLLATE NOCASE ASC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':archived', $showArchived ? 1 : 0, SQLITE3_INTEGER);
$result = $stmt->execute();

$meters = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $meters[] = $row;
}

$readingsData = [];

foreach ($meters as &$meter) {
  $stmt = $db->prepare("SELECT * FROM meter_readings WHERE meter_id = :meterId AND user_id = :userId ORDER BY reading_date ASC");
  $stmt->bindValue(':meterId', $meter['id'], SQLITE3_INTEGER);
  $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $rResult = $stmt->execute();
  $readings = [];
  while ($r = $rResult->fetchArray(SQLITE3_ASSOC)) {
    $readings[] = $r;
  }
  $meter['readings'] = $readings;

  // Build JS-accessible readings map for edit modal.
  foreach ($readings as $r) {
    $readingsData[$r['id']] = [
      'meter_id' => $meter['id'],
      'reading_date' => $r['reading_date'],
      'reading_value' => (float)$r['reading_value'],
      'is_meter_change' => (int)($r['is_meter_change'] ?? 0),
      'note' => $r['note'] ?? '',
      'unit' => ($meterTypes[$meter['meter_type']] ?? $meterTypes['electricity'])['unit'],
    ];
  }

  $computed = computeConsumptionSegments($readings);
  $meter['segments'] = $computed['segments'];
  $meter['warnings'] = $computed['warnings'];
  $meter['monthly'] = computeMonthlyBuckets($computed['segments']);
  $meter['forecast'] = meterForecast($db, (int) $userId, $meter, $computed['segments'], $endOfYear);
  $meter['ytd_units'] = computeUnitsInWindow($computed['segments'], date('Y') . '-01-01', $today);

  $meter['forecast_cost'] = null;
  $meter['ytd_cost'] = null;
  $pricePerUnit = floatval($meter['price_per_unit']);
  $conversion = ($meter['meter_type'] === 'gas' && !empty($meter['conversion_factor']))
    ? floatval($meter['conversion_factor']) : 1.0;

  $isPvType = in_array($meter['meter_type'], ['pv_feed_in', 'pv_generation'], true);
  $meter['tariff_rows'] = loadMeterRows($db, 'meter_tariffs', (int) $meter['id'], $userId, 'valid_from');
  $tariffs = meterTariffList($meter, $meter['tariff_rows']);
  $meter['tariffs'] = $tariffs;
  $meter['ytd_cost'] = $meter['meter_type'] === 'pv_generation' ? null
    : costInWindow($computed['segments'], $tariffs, date('Y') . '-01-01', $today, $conversion, !$isPvType)['total'];

  // Cost per day (last 30 measured days) and per month (last 12 months), work price and base fee apart.
  $meter['cost_day'] = null;
  $meter['cost_months'] = [];
  if (!$isPvType && count($computed['segments']) > 0) {
    $lastTo = end($computed['segments'])['to'];
    $window = costInWindow($computed['segments'], $tariffs, date('Y-m-d', strtotime($lastTo . ' -30 days')), $lastTo, $conversion);
    $meter['cost_day'] = round($window['total'] / 30, 2);
    foreach (array_slice(array_keys($meter['monthly']), -12) as $month) {
      $monthEnd = min(date('Y-m-d', strtotime($month . '-01 +1 month')), $lastTo);
      $meter['cost_months'][$month] = costInWindow($computed['segments'], $tariffs, $month . '-01', $monthEnd, $conversion);
    }
    $meter['cost_months'] = array_reverse($meter['cost_months'], true);
  }

  $meter['outlier'] = $isPvType ? null : detectOutlier($computed['segments']);
  $meter['base_load'] = $meter['meter_type'] === 'electricity' ? estimateBaseLoad($computed['segments'], $today) : null;
  $meter['switch_hints'] = $isPvType ? [] : tariffSwitchHints($meter, $today);
  $meter['contract'] = $supplyContracts[$meter['contract_id'] ?? 0] ?? null;
  $meter['bills'] = [];
  foreach (loadMeterRows($db, 'meter_bills', (int) $meter['id'], $userId, 'period_from DESC') as $bill) {
    $bill['check'] = checkBill($bill, $computed['segments'], $tariffs, $conversion, !$isPvType);
    $meter['bills'][] = $bill;
  }

  if ($meter['forecast'] !== null) {
    $projUnits = ($meter['ytd_units'] + $meter['forecast']['projected_units']) * $conversion;
    if ($meter['meter_type'] === 'pv_feed_in') {
      $meter['forecast_cost'] = round($projUnits * $pricePerUnit, 2);
    } else {
      $meter['forecast_cost'] = round($projUnits * $pricePerUnit + floatval($meter['base_fee_monthly']) * 12, 2);
    }
  }

  // Yearly consumption for the tariff comparison: the last 12 months when the
  // readings reach back that far, otherwise this year's projection.
  $meter['annual_units'] = null;
  if (count($computed['segments']) > 0) {
    $firstDate = $computed['segments'][0]['from'] ?? null;
    if ($firstDate !== null && $firstDate <= date('Y-m-d', strtotime('-365 days'))) {
      $meter['annual_units'] = computeUnitsInWindow($computed['segments'], date('Y-m-d', strtotime('-365 days')), $today) * $conversion;
    } elseif ($meter['forecast'] !== null) {
      $meter['annual_units'] = ($meter['ytd_units'] + $meter['forecast']['projected_units']) * $conversion;
    }
  }

  $meter['benchmark'] = null;
  $typical = householdBenchmark($meter, $benchPersons, (float) $consumptionSettings['living_area']);
  if ($typical !== null && !empty($meter['annual_units'])) {
    $own = $meter['annual_units'] / $conversion; // back to the unit of the meter
    $meter['benchmark'] = ['own' => round($own), 'typical' => $typical, 'percent' => round($own / $typical * 100)];
  }

  // Advance payments (Abschlag) vs. projected cost of the current billing year.
  $meter['settlement'] = null;
  $advance = floatval($meter['monthly_advance'] ?? 0);
  if ($advance <= 0 && $meter['contract'] !== null) {
    $advance = round((float) $meter['contract']['monthly'], 2); // the payment of the linked supply contract
  }
  if (!$isPvType) {
    $periodStart = !empty($meter['billing_start']) ? $meter['billing_start'] : date('Y') . '-01-01';
    // Roll the billing year forward until it contains today.
    for ($guard = 0; date('Y-m-d', strtotime($periodStart . ' +1 year')) <= $today && $guard < 100; $guard++) {
      $periodStart = date('Y-m-d', strtotime($periodStart . ' +1 year'));
    }
    $periodEnd = date('Y-m-d', strtotime($periodStart . ' +1 year'));
    $periodForecast = meterForecast($db, (int) $userId, $meter, $computed['segments'], $periodEnd);
    if ($periodForecast !== null && $periodStart <= $today) {
      // Measured units inside the period + forecast for the days not covered by readings yet.
      $forecastFrom = max($periodForecast['from'], $periodStart);
      $forecastDays = max(0, (strtotime($periodEnd) - strtotime($forecastFrom)) / 86400);
      $periodUnits = computeUnitsInWindow($computed['segments'], $periodStart, $periodEnd) + $periodForecast['per_day'] * $forecastDays;
      $periodCost = $periodUnits * $conversion * $pricePerUnit + floatval($meter['base_fee_monthly']) * 12;
      $meter['settlement'] = [
        'from' => $periodStart,
        'to' => date('Y-m-d', strtotime($periodEnd . ' -1 day')),
        'cost' => round($periodCost, 2),
        'advances' => round($advance * 12, 2),
        'difference' => round($periodCost - $advance * 12, 2), // > 0 = back payment
        'recommended' => round($periodCost / 12, 2),
      ];
    }
  }
}
unset($meter);

$pvSummary = null;
$generationMeters = array_filter($meters, fn($m) => $m['meter_type'] === 'pv_generation' && count($m['segments']) > 0);
if (!$showArchived && $generationMeters) {
  $involved = array_filter($meters, fn($m) => in_array($m['meter_type'], ['pv_generation', 'pv_feed_in', 'electricity'], true) && count($m['segments']) > 0);
  $pvTo = min(array_map(fn($m) => end($m['segments'])['to'], $involved));
  $pvFrom = date('Y-m-d', strtotime($pvTo . ' -365 days'));
  $sum = fn(string $type) => array_sum(array_map(fn($m) => computeUnitsInWindow($m['segments'], $pvFrom, $pvTo), array_filter($involved, fn($m) => $m['meter_type'] === $type)));
  $balance = pvBalance($sum('pv_generation'), $sum('pv_feed_in'), $sum('electricity'));
  if ($balance !== null) {
    $days = max(1, (strtotime($pvTo) - max(strtotime($pvFrom), strtotime(min(array_map(fn($m) => $m['segments'][0]['from'], $generationMeters))))) / 86400);
    $perYear = 365 / $days; // scale a shorter history to a year
    $gridMeter = current(array_filter($meters, fn($m) => $m['meter_type'] === 'electricity'));
    $feedMeter = current(array_filter($meters, fn($m) => $m['meter_type'] === 'pv_feed_in'));
    $genMeter = current($generationMeters);
    $pvSummary = $balance + [
      'from' => $pvFrom, 'to' => $pvTo, 'days' => (int) $days,
      'currency' => $currencies[$genMeter['currency_id']]['code'] ?? $mainCurrencyCode,
      'amortization' => pvAmortization((float) $genMeter['investment_cost'], $genMeter['commissioning_date'] ?: null,
        $balance['self'] * $perYear, $gridMeter ? tariffAt($gridMeter['tariffs'], $today)['price'] : 0.0,
        $balance['feed_in'] * $perYear, $feedMeter ? tariffAt($feedMeter['tariffs'], $today)['price'] : 0.0, $today),
    ];
  }
}

$headerClass = count($meters) > 0 ? "main-actions" : "main-actions hidden";
?>

<section class="contain">
  <header class="<?= $headerClass ?>" id="main-actions">
    <button class="button" onClick="addMeter()">
      <i class="fa-solid fa-circle-plus"></i>
      <?= translate('new_meter', $i18n) ?>
    </button>
    <div class="top-actions">
      <button class="button secondary-button" type="button" onClick="openConsumptionSettings()" title="<?= translate('household_and_location', $i18n) ?>">
        <i class="fa-solid fa-house-user"></i>
        <span class="hideOnMobile"><?= translate('household_and_location', $i18n) ?></span>
      </button>
      <a href="consumption.php<?= $showArchived ? '' : '?state=archived' ?>" class="button secondary-button"
        title="<?= translate($showArchived ? 'show_active' : 'show_archive', $i18n) ?>">
        <i class="fa-solid <?= $showArchived ? 'fa-list' : 'fa-box-archive' ?>"></i>
        <span class="hideOnMobile"><?= translate($showArchived ? 'show_active' : 'show_archive', $i18n) ?></span>
      </a>
    </div>
  </header>

  <?php if ($showArchived): ?>
    <h2 class="archive-title"><i class="fa-solid fa-box-archive"></i> <?= translate('archive', $i18n) ?></h2>
  <?php endif; ?>

  <?php if ($pvSummary !== null): $pvCur = $pvSummary['currency']; ?>
    <section class="pv-summary" aria-labelledby="pv-summary-title">
      <h3 id="pv-summary-title"><i class="fa-solid fa-sun"></i> <?= translate('pv_summary', $i18n) ?>
        <span class="forecast-label"><?= date('d.m.Y', strtotime($pvSummary['from'])) ?> – <?= date('d.m.Y', strtotime($pvSummary['to'])) ?></span></h3>
      <div class="pv-summary-grid">
        <div><strong><?= number_format($pvSummary['generation'], 0, ',', '.') ?> kWh</strong><span><?= translate('pv_generation', $i18n) ?></span></div>
        <div><strong><?= number_format($pvSummary['self'], 0, ',', '.') ?> kWh</strong><span><?= translate('pv_self_consumption', $i18n) ?></span></div>
        <div><strong><?= number_format($pvSummary['self_rate'], 1, ',', '.') ?> %</strong><span><?= translate('pv_self_rate', $i18n) ?></span></div>
        <div><strong><?= $pvSummary['autarky'] !== null ? number_format($pvSummary['autarky'], 1, ',', '.') . ' %' : '—' ?></strong><span><?= translate('pv_autarky', $i18n) ?></span></div>
      </div>
      <?php if ($pvSummary['amortization'] !== null): $am = $pvSummary['amortization']; ?>
        <div class="pv-amortization">
          <div class="pv-amortization-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $am['percent'] ?>"
            aria-label="<?= translate('pv_amortization', $i18n) ?>"><span style="width: <?= min(100, $am['percent']) ?>%"></span></div>
          <p>
            <?= translate('pv_amortization', $i18n) ?>: <strong><?= number_format($am['percent'], 0, ',', '.') ?> %</strong>
            (<?= CurrencyFormatter::format($am['recovered'], $pvCur) ?>)
            · <?= translate('pv_benefit_per_year', $i18n) ?>: <strong><?= CurrencyFormatter::format($am['benefit'], $pvCur) ?></strong>
            <?php if ($am['years'] !== null): ?>
              · <?= sprintf(translate('pv_payback_years', $i18n), number_format($am['years'], 1, ',', '.')) ?>
              <?= $am['payback_date'] ? '(' . date('m/Y', strtotime($am['payback_date'])) . ')' : '' ?>
            <?php endif; ?>
          </p>
        </div>
      <?php endif; ?>
      <?php if ($pvSummary['days'] < 300): ?>
        <p class="forecast-label"><?= translate('pv_short_history', $i18n) ?></p>
      <?php endif; ?>
    </section>
  <?php endif; ?>
  <div id="meter-list">
  <?php foreach ($meters as $meter):
    $typeInfo = $meterTypes[$meter['meter_type']] ?? $meterTypes['electricity'];
    $unitLabel = $typeInfo['unit'];
    $isPv = $meter['meter_type'] === 'pv_feed_in';
    $lastReading = count($meter['readings']) > 0 ? end($meter['readings']) : null;
    $meterCode = $currencies[$meter['currency_id']]['code'] ?? $mainCurrencyCode;
    ?>
    <div class="meter-panel <?= $meter['archived'] ? 'archived' : '' ?> <?= $isPv ? 'pv' : '' ?>"
      data-id="<?= $meter['id'] ?>">
      <header class="meter-panel-header">
        <div class="meter-panel-title">
          <?php if (!$showArchived && count($meters) > 1): ?>
            <span class="drag-icon meter-drag" title="<?= translate('meter_drag_hint', $i18n) ?>"><i class="fa-solid fa-grip-vertical"></i></span>
          <?php endif; ?>
          <i class="fa-solid <?= $typeInfo['icon'] ?>"></i>
          <h3><?= htmlspecialchars($meter['name']) ?></h3>
          <span class="meter-panel-type"><?= translate($typeInfo['label'], $i18n) ?>
            <?= $meter['meter_number'] ? '· ' . htmlspecialchars($meter['meter_number']) : '' ?></span>
          <?php if ($meter['contract'] !== null): ?>
            <a class="deadline-badge" href="contracts.php" title="<?= translate('meter_contract', $i18n) ?>">
              <i class="fa-solid fa-file-signature"></i> <?= $meter['contract']['name'] ?><?= $meter['contract']['deadline'] ? ' · ' . translate('cancellable_until', $i18n) . ' ' . date('d.m.Y', strtotime($meter['contract']['deadline'])) : '' ?>
            </a>
          <?php endif; ?>
          <?php foreach ($meter['switch_hints'] as $switchHint): ?>
            <span class="deadline-badge warning">
              <i class="fa-solid fa-right-left"></i>
              <?= $switchHint['key'] === 'guarantee'
                ? sprintf(translate('price_guarantee_ends', $i18n), date('d.m.Y', strtotime($switchHint['date'])))
                : sprintf(translate('price_above_reference', $i18n), $switchHint['percent']) ?>
            </span>
          <?php endforeach; ?>
          <?php if ($meter['outlier'] !== null): ?>
            <span class="deadline-badge warning" title="<?= sprintf(translate('outlier_detail', $i18n), number_format($meter['outlier']['per_day'], 2, ',', '.'), number_format($meter['outlier']['usual'], 2, ',', '.'), $unitLabel) ?>">
              <i class="fa-solid fa-triangle-exclamation"></i> <?= sprintf(translate('outlier_badge', $i18n), round(($meter['outlier']['ratio'] - 1) * 100)) ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="module-card-actions">
          <?php if ($haEnabled && !empty($meter['ha_entity_id']) && !$meter['archived']): ?>
            <button class="image-button" title="<?= translate('ha_fetch_reading', $i18n) ?> (<?= htmlspecialchars($meter['ha_entity_id']) ?>)"
              onClick="fetchHomeAssistantReading(event, <?= $meter['id'] ?>)">
              <i class="fa-solid fa-house-signal"></i>
            </button>
          <?php endif; ?>
          <button class="image-button" title="<?= translate('import_csv', $i18n) ?>"
            onClick="openCsvImport(<?= $meter['id'] ?>)">
            <i class="fa-solid fa-file-import"></i>
          </button>
          <button class="image-button" title="<?= translate('edit', $i18n) ?>"
            onClick="openEditMeter(event, <?= $meter['id'] ?>)">
            <i class="fa-solid fa-pen"></i>
          </button>
          <?php if ($meter['archived']): ?>
            <button class="image-button" title="<?= translate('unarchive', $i18n) ?>"
              onClick="archiveMeter(event, <?= $meter['id'] ?>, 0)">
              <i class="fa-solid fa-box-open"></i>
            </button>
          <?php else: ?>
            <button class="image-button" title="<?= translate('archive', $i18n) ?>"
              onClick="archiveMeter(event, <?= $meter['id'] ?>, 1)">
              <i class="fa-solid fa-box-archive"></i>
            </button>
          <?php endif; ?>
        </div>
      </header>

      <div class="meter-panel-body">
        <details class="meter-input-details">
          <summary>
            <i class="fa-solid fa-pen-to-square"></i>
            <span><?= translate('entry', $i18n) ?> &amp; <?= translate('forecast', $i18n) ?></span>
            <?php if ($lastReading !== null): ?>
              <span class="meter-input-summary-value">
                <?= translate('last_reading', $i18n) ?>:
                <?= number_format($lastReading['reading_value'], 2, ',', '.') ?> <?= $unitLabel ?>
              </span>
            <?php endif; ?>
          </summary>
          <div class="meter-input-content">
          <form class="reading-form inline-form" data-meter="<?= $meter['id'] ?>"
            data-last-value="<?= $lastReading !== null ? (float) $lastReading['reading_value'] : '' ?>"
            data-last-date="<?= $lastReading !== null ? htmlspecialchars($lastReading['reading_date']) : '' ?>"
            data-per-day="<?= $meter['forecast'] !== null ? (float) $meter['forecast']['per_day'] : '' ?>">
            <div class="date-wrapper">
              <input type="date" class="reading-date" value="<?= $today ?>" required>
            </div>
            <input type="text" inputmode="decimal" autocomplete="off" class="reading-value" pattern="[0-9]+([.,][0-9]+)?"
              placeholder="<?= translate('meter_reading', $i18n) ?>" data-unit="<?= $unitLabel ?>" required
              title="<?= translate('reading_forecast_hint', $i18n) ?>">
            <label class="meter-change-label" title="<?= translate('meter_change_hint', $i18n) ?>">
              <input type="checkbox" class="reading-meter-change">
              <?= translate('meter_change', $i18n) ?>
            </label>
            <button type="submit" class="button thin">
              <i class="fa-solid fa-circle-plus"></i> <?= translate('add', $i18n) ?>
            </button>
          </form>

          <?php if ($lastReading !== null): ?>
            <p class="meter-last-reading">
              <?= translate('last_reading', $i18n) ?>:
              <strong><?= number_format($lastReading['reading_value'], 2, ',', '.') ?> <?= $unitLabel ?></strong>
              (<?= date('d.m.Y', strtotime($lastReading['reading_date'])) ?>)
            </p>
          <?php endif; ?>

          <div class="forecast-box <?= $isPv ? 'pv' : '' ?>">
            <div class="forecast-box-header">
              <i class="fa-solid fa-chart-line"></i>
              <?= translate('forecast', $i18n) ?>
              <input type="date" class="forecast-date" data-meter="<?= $meter['id'] ?>" value="<?= $endOfYear ?>">
            </div>
            <div class="forecast-box-content" id="forecast-content-<?= $meter['id'] ?>">
              <?php if ($meter['forecast'] !== null): ?>
                <span class="forecast-units">
                  <?= number_format($meter['ytd_units'] + $meter['forecast']['projected_units'], 0, ',', '.') ?>
                  <?= $unitLabel ?>
                </span>
                <span class="forecast-label"><?= translate('projected_consumption_total', $i18n) ?></span>
                <span class="forecast-label forecast-method"><?= translate('forecast_basis_' . ($meter['forecast']['method'] ?? 'recent_average'), $i18n) ?></span>
                <?php if ($meter['forecast_cost'] !== null && $meter['meter_type'] !== 'pv_generation'): ?>
                  <span class="forecast-cost <?= $isPv ? 'positive' : '' ?>">
                    <?= CurrencyFormatter::format($meter['forecast_cost'], $meterCode) ?>
                  </span>
                  <span class="forecast-label"><?= translate($isPv ? 'projected_earnings' : 'projected_cost', $i18n) ?></span>
                <?php endif; ?>
              <?php else: ?>
                <span class="forecast-label"><?= translate('not_enough_data_forecast', $i18n) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($meter['settlement'] !== null && $meter['settlement']['advances'] <= 0): ?>
            <p class="meter-ytd"><i class="fa-solid fa-lightbulb"></i>
              <?= translate('recommended_advance', $i18n) ?>: <strong><?= CurrencyFormatter::format($meter['settlement']['recommended'], $meterCode) ?></strong> / <?= translate('month', $i18n) ?>
            </p>
          <?php elseif ($meter['settlement'] !== null):
            $settlement = $meter['settlement'];
            $isCredit = $settlement['difference'] < 0;
            $advanceChange = $settlement['recommended'] - $settlement['advances'] / 12; ?>
            <div class="forecast-box <?= $isCredit ? 'pv' : '' ?>">
              <div class="forecast-box-header">
                <i class="fa-solid fa-scale-balanced"></i>
                <?= translate('advance_settlement', $i18n) ?>
                <span class="forecast-label">
                  <?= date('d.m.Y', strtotime($settlement['from'])) ?> – <?= date('d.m.Y', strtotime($settlement['to'])) ?>
                </span>
              </div>
              <div class="forecast-box-content">
                <span class="forecast-cost <?= $isCredit ? 'positive' : '' ?>">
                  <?= CurrencyFormatter::format(abs($settlement['difference']), $meterCode) ?>
                </span>
                <span class="forecast-label"><?= translate($isCredit ? 'expected_credit' : 'expected_back_payment', $i18n) ?></span>
                <span class="forecast-label">
                  <?= translate('projected_cost', $i18n) ?>: <?= CurrencyFormatter::format($settlement['cost'], $meterCode) ?>
                  · <?= translate('advances_paid', $i18n) ?>: <?= CurrencyFormatter::format($settlement['advances'], $meterCode) ?>
                  · <?= translate('recommended_advance', $i18n) ?>: <?= CurrencyFormatter::format($settlement['recommended'], $meterCode) ?>
                </span>
                <?php if (abs($advanceChange) >= 1): ?>
                  <span class="forecast-label"><strong><?= sprintf(translate($advanceChange > 0 ? 'advance_raise_by' : 'advance_lower_by', $i18n), CurrencyFormatter::format(abs($advanceChange), $meterCode)) ?></strong></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($meter['ytd_cost'] !== null && count($meter['segments']) > 0): ?>
            <p class="meter-ytd">
              <?= translate('year_to_date', $i18n) ?>:
              <strong><?= number_format($meter['ytd_units'], 0, ',', '.') ?> <?= $unitLabel ?></strong>
              · <?= CurrencyFormatter::format($meter['ytd_cost'], $meterCode) ?>
              <?= translate($isPv ? 'earnings' : 'cost', $i18n) ?>
            </p>
          <?php endif; ?>

          <?php if (!$isPv && !empty($meter['annual_units'])):
            $currentYearly = $meter['annual_units'] * floatval($meter['price_per_unit']) + floatval($meter['base_fee_monthly']) * 12; ?>
            <details class="tariff-compare" data-units="<?= round($meter['annual_units'], 2) ?>"
              data-current="<?= round($currentYearly, 2) ?>" data-currency="<?= htmlspecialchars($meterCode) ?>">
              <summary><i class="fa-solid fa-scale-balanced"></i> <?= translate('tariff_compare', $i18n) ?></summary>
              <p><?= translate('tariff_compare_basis', $i18n) ?>:
                <strong><?= number_format($meter['annual_units'], 0, ',', '.') ?> <?= $meter['meter_type'] === 'gas' && !empty($meter['conversion_factor']) ? 'kWh' : $unitLabel ?></strong>
                · <?= translate('tariff_current', $i18n) ?>: <strong><?= CurrencyFormatter::format($currentYearly, $meterCode) ?></strong> / <?= translate('year', $i18n) ?></p>
              <div class="form-group-inline">
                <input type="number" step="0.0001" min="0" placeholder="<?= translate('price_per_unit', $i18n) ?>" oninput="compareTariff(this)" class="tariff-price">
                <input type="number" step="0.01" min="0" placeholder="<?= translate('base_fee_monthly', $i18n) ?>" oninput="compareTariff(this)" class="tariff-fee">
                <input type="number" step="0.01" placeholder="<?= translate('tariff_bonus', $i18n) ?>" oninput="compareTariff(this)" class="tariff-bonus">
              </div>
              <p class="tariff-result"></p>
            </details>
          <?php endif; ?>
          </div>
        </details>

        <?php if (count($meter['readings']) < 2): ?>
          <p class="meter-empty-hint"><?= translate('meter_empty_hint', $i18n) ?></p>
        <?php endif; ?>
        <div class="meter-panel-chart" <?= count($meter['readings']) < 2 ? 'hidden' : '' ?>>
          <div class="chart-range-controls">
            <span class="chart-range-label"><?= translate('chart_from', $i18n) ?></span>
            <input type="date" class="chart-from" data-meter="<?= $meter['id'] ?>">
            <span class="chart-range-label"><?= translate('chart_to', $i18n) ?></span>
            <input type="date" class="chart-to" data-meter="<?= $meter['id'] ?>">
            <div class="chart-presets">
              <button class="chart-preset-btn" data-meter="<?= $meter['id'] ?>" data-preset="1m">1M</button>
              <button class="chart-preset-btn" data-meter="<?= $meter['id'] ?>" data-preset="12m">12M</button>
              <button class="chart-preset-btn active" data-meter="<?= $meter['id'] ?>" data-preset="all">Max</button>
            </div>
          </div>
          <div class="meter-chart-container">
            <div id="meterChart-<?= $meter['id'] ?>" class="apex-chart"></div>
          </div>
        </div>
      </div>

      <?php if (count($meter['segments']) > 0): ?>
        <details class="meter-insights" data-meter="<?= $meter['id'] ?>" data-heating="<?= meterIsHeating($meter) ? 1 : 0 ?>">
          <summary><i class="fa-solid fa-magnifying-glass-chart"></i> <?= translate('consumption_insights', $i18n) ?></summary>
          <div class="meter-insights-content">

            <?php if (count($meter['monthly']) > 1): ?>
              <section class="insight-block">
                <h4><?= translate('year_comparison', $i18n) ?></h4>
                <div class="meter-chart-container"><div id="yearChart-<?= $meter['id'] ?>" class="apex-chart"></div></div>
              </section>
            <?php endif; ?>

            <?php if ($meter['cost_day'] !== null): ?>
              <section class="insight-block">
                <h4><?= translate('cost_per_day_month', $i18n) ?></h4>
                <p><?= translate('cost_per_day', $i18n) ?>: <strong><?= CurrencyFormatter::format($meter['cost_day'], $meterCode) ?></strong>
                  <span class="forecast-label">(<?= translate('last_30_days', $i18n) ?>)</span></p>
                <div class="table-scroll">
                  <table class="schedule-table">
                    <thead><tr><th><?= translate('month', $i18n) ?></th><th><?= translate('consumption', $i18n) ?></th>
                      <th><?= translate('work_price', $i18n) ?></th><th><?= translate('base_fee', $i18n) ?></th><th><?= translate('total', $i18n) ?></th></tr></thead>
                    <tbody>
                      <?php foreach ($meter['cost_months'] as $month => $c): ?>
                        <tr><td><?= date('m/Y', strtotime($month . '-01')) ?></td>
                          <td><?= number_format($c['units'], 1, ',', '.') ?> <?= $unitLabel ?></td>
                          <td><?= CurrencyFormatter::format($c['work'], $meterCode) ?></td>
                          <td><?= CurrencyFormatter::format($c['base'], $meterCode) ?></td>
                          <td><strong><?= CurrencyFormatter::format($c['total'], $meterCode) ?></strong></td></tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </section>
            <?php endif; ?>

            <?php if ($meter['meter_type'] === 'electricity'): ?>
              <section class="insight-block">
                <h4><?= translate('base_load', $i18n) ?></h4>
                <?php if ($meter['base_load'] !== null): $baseLoad = $meter['base_load'];
                  $baseCost = $baseLoad['kwh_year'] * tariffAt($meter['tariffs'], $today)['price']; ?>
                  <p><?= sprintf(translate('base_load_text', $i18n), number_format($baseLoad['watts'], 0, ',', '.'), number_format($baseLoad['kwh_year'], 0, ',', '.'), CurrencyFormatter::format($baseCost, $meterCode)) ?></p>
                  <p class="forecast-label"><?= translate('base_load_hint', $i18n) ?></p>
                <?php else: ?>
                  <p class="forecast-label"><?= translate('base_load_needs_data', $i18n) ?></p>
                <?php endif; ?>
              </section>
            <?php endif; ?>

            <?php if (!in_array($meter['meter_type'], ['pv_feed_in', 'pv_generation'], true)): ?>
              <section class="insight-block">
                <h4><?= translate('household_comparison', $i18n) ?></h4>
                <?php if ($meter['benchmark'] !== null): $bm = $meter['benchmark']; ?>
                  <p><?= sprintf(translate('benchmark_text', $i18n), number_format($bm['own'], 0, ',', '.') . ' ' . $unitLabel, number_format($bm['typical'], 0, ',', '.') . ' ' . $unitLabel) ?></p>
                  <div class="benchmark-bar <?= $bm['percent'] > 110 ? 'high' : ($bm['percent'] < 90 ? 'low' : '') ?>" role="img"
                    aria-label="<?= $bm['percent'] ?> %"><span style="width: <?= min(100, $bm['percent'] / 2) ?>%"></span><i style="left: 50%"></i></div>
                  <p class="forecast-label"><?= sprintf(translate('benchmark_percent', $i18n), $bm['percent']) ?></p>
                <?php else: ?>
                  <p class="forecast-label"><?= translate('benchmark_needs_data', $i18n) ?>
                    <button type="button" class="link-button" onClick="openConsumptionSettings()"><?= translate('household_and_location', $i18n) ?></button></p>
                <?php endif; ?>
              </section>
            <?php endif; ?>

            <?php if (meterIsHeating($meter)): ?>
              <section class="insight-block">
                <h4><?= translate('weather_adjusted', $i18n) ?></h4>
                <div class="weather-result" id="weather-<?= $meter['id'] ?>">
                  <?php if ($consumptionSettings['latitude'] === null): ?>
                    <p class="forecast-label"><?= translate('weather_needs_location', $i18n) ?>
                      <button type="button" class="link-button" onClick="openConsumptionSettings()"><?= translate('household_and_location', $i18n) ?></button></p>
                  <?php else: ?>
                    <p class="forecast-label"><?= translate('loading', $i18n) ?> …</p>
                  <?php endif; ?>
                </div>
              </section>
            <?php endif; ?>

            <?php if ($meter['meter_type'] !== 'pv_generation'): ?>
              <section class="insight-block">
                <h4><?= translate('tariff_history', $i18n) ?></h4>
                <div class="table-scroll">
                  <table class="schedule-table">
                    <thead><tr><th><?= translate('valid_from', $i18n) ?></th><th><?= translate('price_per_unit', $i18n) ?></th>
                      <?php if ($meter['meter_type'] !== 'pv_feed_in'): ?><th><?= translate('base_fee_monthly', $i18n) ?></th><?php endif; ?><th></th></tr></thead>
                    <tbody>
                      <?php foreach (array_reverse($meter['tariffs']) as $t): ?>
                        <tr><td><?= $t['valid_from'] === '0000-01-01' ? translate('from_start', $i18n) : date('d.m.Y', strtotime($t['valid_from'])) ?>
                            <?= $t['id'] === null ? '<span class="deadline-badge">' . translate('current', $i18n) . '</span>' : '' ?></td>
                          <td><?= number_format($t['price'], 4, ',', '.') ?></td>
                          <?php if ($meter['meter_type'] !== 'pv_feed_in'): ?><td><?= CurrencyFormatter::format($t['fee'], $meterCode) ?></td><?php endif; ?>
                          <td class="reading-actions"><?php if ($t['id'] !== null): ?>
                            <button class="image-button" type="button" title="<?= translate('delete', $i18n) ?>" onClick="deleteTariff(<?= $t['id'] ?>)"><i class="fa-solid fa-trash"></i></button>
                          <?php endif; ?></td></tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <form class="inline-form insight-form" onsubmit="return addTariff(event, <?= $meter['id'] ?>)">
                  <label><?= translate('valid_from', $i18n) ?><input type="date" name="valid_from" required></label>
                  <label><?= translate('price_per_unit', $i18n) ?><input type="number" name="price_per_unit" step="0.0001" min="0" required placeholder="0.30"></label>
                  <?php if ($meter['meter_type'] !== 'pv_feed_in'): ?>
                    <label><?= translate('base_fee_monthly', $i18n) ?><input type="number" name="base_fee_monthly" step="0.01" min="0" placeholder="0"></label>
                  <?php endif; ?>
                  <button type="submit" class="button thin"><i class="fa-solid fa-circle-plus"></i> <?= translate('add_earlier_price', $i18n) ?></button>
                </form>
                <p class="forecast-label"><?= translate('tariff_history_hint', $i18n) ?></p>
              </section>
            <?php endif; ?>

            <?php if (!in_array($meter['meter_type'], ['pv_feed_in', 'pv_generation'], true)): ?>
              <section class="insight-block">
                <h4><?= translate('bill_check', $i18n) ?></h4>
                <?php if ($meter['bills']): ?>
                  <div class="table-scroll">
                    <table class="schedule-table">
                      <thead><tr><th><?= translate('period', $i18n) ?></th><th><?= translate('bill', $i18n) ?></th><th><?= translate('own_readings', $i18n) ?></th><th><?= translate('status', $i18n) ?></th><th></th></tr></thead>
                      <tbody>
                        <?php foreach ($meter['bills'] as $bill): $chk = $bill['check']; ?>
                          <tr>
                            <td><?= date('d.m.Y', strtotime($bill['period_from'])) ?> – <?= date('d.m.Y', strtotime($bill['period_to'])) ?>
                              <?= $bill['note'] !== '' ? '<br><span class="forecast-label">' . $bill['note'] . '</span>' : '' ?></td>
                            <td><?= number_format($bill['units'], 0, ',', '.') ?> <?= $unitLabel ?><br><?= CurrencyFormatter::format($bill['amount'], $meterCode) ?></td>
                            <td><?php if ($chk['units'] !== null): ?>
                                <?= number_format($chk['units'], 0, ',', '.') ?> <?= $unitLabel ?> (<?= ($chk['unit_diff'] ?? 0) >= 0 ? '+' : '−' ?><?= number_format(abs($chk['unit_diff'] ?? 0), 1, ',', '.') ?> %)<br>
                                <?= CurrencyFormatter::format($chk['cost'], $meterCode) ?> (<?= ($chk['cost_diff'] ?? 0) >= 0 ? '+' : '−' ?><?= number_format(abs($chk['cost_diff'] ?? 0), 1, ',', '.') ?> %)
                              <?php else: ?>—<?php endif; ?></td>
                            <td><span class="deadline-badge <?= ['ok' => 'ok', 'check' => 'warning', 'deviation' => 'danger', 'no_data' => 'cancelled'][$chk['status']] ?>"><?= translate('bill_status_' . $chk['status'], $i18n) ?></span></td>
                            <td class="reading-actions"><button class="image-button" type="button" title="<?= translate('delete', $i18n) ?>" onClick="deleteBill(<?= $bill['id'] ?>)"><i class="fa-solid fa-trash"></i></button></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
                <form class="inline-form insight-form" onsubmit="return addBill(event, <?= $meter['id'] ?>)">
                  <label><?= translate('chart_from', $i18n) ?><input type="date" name="period_from" required></label>
                  <label><?= translate('chart_to', $i18n) ?><input type="date" name="period_to" required></label>
                  <label><?= translate('consumption', $i18n) ?> (<?= $unitLabel ?>)<input type="number" name="units" step="0.01" min="0" required></label>
                  <label><?= translate('bill_amount', $i18n) ?><input type="number" name="amount" step="0.01" required></label>
                  <label class="grow"><?= translate('note', $i18n) ?><input type="text" name="note" maxlength="200"></label>
                  <button type="submit" class="button thin"><i class="fa-solid fa-circle-plus"></i> <?= translate('add_bill', $i18n) ?></button>
                </form>
                <p class="forecast-label"><?= translate('bill_check_hint', $i18n) ?></p>
              </section>
            <?php endif; ?>

          </div>
        </details>
      <?php endif; ?>

      <?php if (count($meter['readings']) > 0): ?>
        <details class="meter-readings-details">
          <summary><?= translate('readings', $i18n) ?> (<?= count($meter['readings']) ?>)</summary>
          <table class="schedule-table readings-table">
            <thead>
              <tr>
                <th><?= translate('date', $i18n) ?></th>
                <th><?= translate('meter_reading', $i18n) ?> (<?= $unitLabel ?>)</th>
                <th><?= translate('consumption', $i18n) ?></th>
                <th><?= translate('per_day', $i18n) ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php
              $readingsDesc = array_reverse($meter['readings']);
              $segmentsByEndDate = [];
              foreach ($meter['segments'] as $segment) {
                $segmentsByEndDate[$segment['to']] = $segment;
              }
              foreach ($readingsDesc as $reading):
                $segment = $segmentsByEndDate[$reading['reading_date']] ?? null;
                ?>
                <tr>
                  <td>
                    <?= date('d.m.Y', strtotime($reading['reading_date'])) ?>
                    <?php if ($reading['is_meter_change']): ?>
                      <span class="deadline-badge warning"><?= translate('meter_change', $i18n) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($reading['note'])): ?>
                      <span class="reading-note" title="<?= htmlspecialchars($reading['note']) ?>">
                        <i class="fa-solid fa-note-sticky"></i>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td><?= number_format($reading['reading_value'], 2, ',', '.') ?></td>
                  <td><?= $segment !== null ? number_format($segment['delta'], 2, ',', '.') . ' ' . $unitLabel : '—' ?></td>
                  <td><?= $segment !== null ? number_format($segment['per_day'], 3, ',', '.') . ' ' . $unitLabel : '—' ?></td>
                  <td class="reading-actions">
                    <button class="image-button" title="<?= translate('edit', $i18n) ?>"
                      onClick="openEditReading(event, <?= $reading['id'] ?>)">
                      <i class="fa-solid fa-pen"></i>
                    </button>
                    <button class="image-button" title="<?= translate('delete', $i18n) ?>"
                      onClick="deleteReading(event, <?= $reading['id'] ?>)">
                      <i class="fa-solid fa-trash"></i>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>

  <?php if (count($meters) == 0): ?>
    <div class="empty-page">
      <img src="images/siteimages/empty.png" alt="<?= translate('empty_page', $i18n) ?>" />
      <p>
        <?= translate($showArchived ? 'no_archived_items' : 'no_meters_yet', $i18n) ?>
      </p>
      <?php if (!$showArchived): ?>
        <button class="button" onClick="addMeter()">
          <i class="fa-solid fa-circle-plus"></i>
          <?= translate('add_first_meter', $i18n) ?>
        </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<!-- Meter add/edit form -->
<section class="subscription-form" id="meter-form">
  <header>
    <h3 id="meter-form-title"><?= translate('add_meter', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeMeterForm()"></span>
  </header>
  <form action="endpoints/consumption/add_meter.php" method="post" id="meter-form-data">
    <input type="hidden" id="meter-id" name="id">

    <div class="form-group">
      <input type="text" id="meter-name" name="name" autocomplete="off"
        placeholder="<?= translate('meter_name', $i18n) ?>" required>
    </div>

    <div class="form-group">
      <label for="meter-type"><?= translate('meter_type', $i18n) ?></label>
      <select id="meter-type" name="meter_type" onchange="toggleMeterTypeFields()">
        <?php foreach ($meterTypes as $key => $info): ?>
          <option value="<?= $key ?>"><?= translate($info['label'], $i18n) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <input type="text" id="meter-number" name="meter_number" autocomplete="off"
        placeholder="<?= translate('meter_number', $i18n) ?>">
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="meter-price-per-unit" id="meter-price-label"><?= translate('price_per_unit', $i18n) ?></label>
          <input type="number" step="0.0001" min="0" id="meter-price-per-unit" name="price_per_unit"
            autocomplete="off" placeholder="0.32" aria-describedby="meter-price-hint">
          <small class="loan-anchor-hint" id="meter-price-hint"><?= translate('price_per_unit_hint', $i18n) ?></small>
        </div>
        <div class="split50" id="meter-base-fee-group">
          <label for="meter-base-fee"><?= translate('base_fee_monthly', $i18n) ?></label>
          <input type="number" step="0.01" min="0" id="meter-base-fee" name="base_fee_monthly" autocomplete="off">
        </div>
      </div>
    </div>

    <div class="form-group" id="meter-price-from-group">
      <label for="meter-price-valid-from"><?= translate('price_valid_from', $i18n) ?></label>
      <input type="date" id="meter-price-valid-from" name="price_valid_from" autocomplete="off">
      <small class="loan-anchor-hint"><?= translate('price_valid_from_hint', $i18n) ?></small>
    </div>

    <div class="form-group">
      <label for="meter-forecast-method"><?= translate('forecast_method', $i18n) ?></label>
      <select id="meter-forecast-method" name="forecast_method">
        <?php foreach (METER_FORECAST_METHODS as $forecastMethod): ?>
          <option value="<?= $forecastMethod ?>"><?= translate('forecast_method_' . $forecastMethod, $i18n) ?></option>
        <?php endforeach; ?>
      </select>
      <small class="loan-anchor-hint"><?= translate('forecast_method_hint', $i18n) ?></small>
    </div>

    <div class="form-group-inline" id="meter-heating-group">
      <input type="checkbox" id="meter-is-heating" name="is_heating" value="1">
      <label for="meter-is-heating"><?= translate('meter_is_heating', $i18n) ?></label>
    </div>

    <div class="form-group hide" id="meter-pv-group">
      <div class="inline">
        <div class="split50">
          <label for="meter-investment"><?= translate('pv_investment_cost', $i18n) ?></label>
          <input type="number" step="0.01" min="0" id="meter-investment" name="investment_cost" autocomplete="off">
        </div>
        <div class="split50">
          <label for="meter-commissioning"><?= translate('pv_commissioning_date', $i18n) ?></label>
          <input type="date" id="meter-commissioning" name="commissioning_date" autocomplete="off">
        </div>
      </div>
    </div>

    <?php if ($haEnabled): ?>
      <div class="form-group" id="meter-ha-group">
        <label for="meter-ha-entity"><?= translate('ha_entity', $i18n) ?></label>
        <input type="text" id="meter-ha-entity" name="ha_entity_id" autocomplete="off" placeholder="sensor.stromzaehler_energie">
        <small class="cancellation-hint"><?= translate('ha_entity_hint', $i18n) ?></small>
      </div>
    <?php endif; ?>

    <div class="form-group" id="meter-advance-group">
      <div class="inline">
        <div class="split50">
          <label for="meter-monthly-advance"><?= translate('monthly_advance', $i18n) ?></label>
          <input type="number" step="0.01" min="0" id="meter-monthly-advance" name="monthly_advance" autocomplete="off">
        </div>
        <div class="split50">
          <label for="meter-billing-start"><?= translate('billing_start', $i18n) ?></label>
          <input type="date" id="meter-billing-start" name="billing_start" autocomplete="off">
        </div>
      </div>
    </div>

    <div class="form-group" id="meter-contract-group">
      <label for="meter-contract"><?= translate('meter_contract', $i18n) ?></label>
      <select id="meter-contract" name="contract_id">
        <option value=""><?= translate('none', $i18n) ?></option>
        <?php foreach ($supplyContracts as $supplyContract): ?>
          <option value="<?= $supplyContract['id'] ?>"><?= $supplyContract['name'] ?><?= $supplyContract['provider'] ? ' · ' . $supplyContract['provider'] : '' ?></option>
        <?php endforeach; ?>
      </select>
      <small class="loan-anchor-hint"><?= translate('meter_contract_hint', $i18n) ?></small>
    </div>

    <div class="form-group" id="meter-switch-group">
      <div class="inline">
        <div class="split50">
          <label for="meter-guarantee"><?= translate('price_guarantee_until', $i18n) ?></label>
          <input type="date" id="meter-guarantee" name="price_guarantee_until" autocomplete="off">
        </div>
        <div class="split50">
          <label for="meter-reference-price"><?= translate('reference_price', $i18n) ?></label>
          <input type="number" step="0.0001" min="0" id="meter-reference-price" name="reference_price" autocomplete="off" placeholder="0.28">
        </div>
      </div>
      <small class="loan-anchor-hint"><?= translate('tariff_switch_hint', $i18n) ?></small>
    </div>

    <div class="form-group">
      <label for="meter-reminder-days"><?= translate('reading_reminder_days', $i18n) ?></label>
      <input type="number" step="1" min="0" id="meter-reminder-days" name="reading_reminder_days" autocomplete="off"
        placeholder="0">
    </div>

    <div class="form-group hide" id="meter-conversion-group">
      <label for="meter-conversion-factor"><?= translate('gas_conversion_factor', $i18n) ?></label>
      <input type="number" step="0.0001" min="0" id="meter-conversion-factor" name="conversion_factor"
        autocomplete="off" placeholder="<?= translate('gas_conversion_factor_hint', $i18n) ?>">
    </div>

    <div class="form-group">
      <label for="meter-currency"><?= translate('currency', $i18n) ?></label>
      <select id="meter-currency" name="currency_id">
        <?php foreach ($currencies as $currency): ?>
          <option value="<?= $currency['id'] ?>" <?= $currency['id'] == $main_currency ? 'selected' : '' ?>>
            <?= $currency['name'] ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="buttons">
      <input type="button" value="<?= translate('delete', $i18n) ?>" class="warning-button left thin" id="delete-meter"
        style="display: none">
      <input type="button" value="<?= translate('cancel', $i18n) ?>" class="secondary-button thin"
        onClick="closeMeterForm()">
      <input type="submit" value="<?= translate('save', $i18n) ?>" class="thin" id="meter-save-button">
    </div>
  </form>
</section>

<!-- CSV import form -->
<section class="subscription-form" id="csv-import-form">
  <header>
    <h3><?= translate('import_csv', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeCsvImport()"></span>
  </header>
  <div class="csv-import-content">
    <p class="csv-import-hint"><?= translate('csv_import_hint', $i18n) ?></p>
    <input type="file" id="csv-file" accept=".csv,.txt,text/csv">
    <div id="csv-mapping" class="hide">
      <div class="form-group-inline grow">
        <input type="checkbox" id="csv-has-header" checked onchange="renderCsvPreview()">
        <label for="csv-has-header" class="grow"><?= translate('csv_first_row_header', $i18n) ?></label>
      </div>
      <div class="csv-mapping-scroll">
        <table class="csv-mapping-table" id="csv-preview-table"></table>
      </div>
      <p id="csv-import-summary"></p>
      <div class="buttons">
        <input type="button" value="<?= translate('cancel', $i18n) ?>" class="secondary-button thin"
          onClick="closeCsvImport()">
        <button class="button thin" id="csv-import-button" onClick="submitCsvImport()">
          <i class="fa-solid fa-file-import"></i> <?= translate('import', $i18n) ?>
        </button>
      </div>
    </div>
  </div>
</section>

<!-- Reading edit form -->
<section class="subscription-form" id="reading-edit-form">
  <header>
    <h3><?= translate('edit_reading', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeEditReadingForm()"></span>
  </header>
  <form action="" method="post" id="reading-edit-form-data">
    <input type="hidden" id="reading-edit-id">

    <div class="form-group">
      <label for="reading-edit-date"><?= translate('date', $i18n) ?></label>
      <input type="date" id="reading-edit-date" required>
    </div>

    <div class="form-group">
      <label for="reading-edit-value"><?= translate('meter_reading', $i18n) ?></label>
      <span class="unit-input">
        <input type="number" step="0.001" min="0" id="reading-edit-value" autocomplete="off" required>
        <span class="unit-suffix" id="reading-edit-unit" aria-hidden="true"></span>
      </span>
    </div>

    <div class="form-group-inline">
      <input type="checkbox" id="reading-edit-meter-change">
      <label for="reading-edit-meter-change" title="<?= translate('meter_change_hint', $i18n) ?>">
        <?= translate('meter_change', $i18n) ?>
      </label>
    </div>

    <div class="form-group">
      <label for="reading-edit-note"><?= translate('note', $i18n) ?></label>
      <input type="text" id="reading-edit-note" autocomplete="off"
        placeholder="<?= translate('note', $i18n) ?>">
    </div>

    <div class="buttons">
      <input type="button" value="<?= translate('cancel', $i18n) ?>" class="secondary-button thin"
        onClick="closeEditReadingForm()">
      <input type="submit" value="<?= translate('save', $i18n) ?>" class="thin" id="reading-edit-save">
    </div>
  </form>
</section>

<section class="subscription-form" id="consumption-settings-form" aria-labelledby="consumption-settings-title">
  <header>
    <h3 id="consumption-settings-title"><?= translate('household_and_location', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeConsumptionSettings()"></span>
  </header>
  <form id="consumption-settings-data" onsubmit="return saveConsumptionSettings(event)">
    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="cs-persons"><?= translate('household_persons', $i18n) ?></label>
          <input type="number" id="cs-persons" name="persons" min="0" max="20" step="1"
            value="<?= (int) $consumptionSettings['persons'] ?: '' ?>" placeholder="<?= (int) ($consumptionSettings['persons_default'] ?? 0) ?: '' ?>">
        </div>
        <div class="split50">
          <label for="cs-area"><?= translate('living_area', $i18n) ?></label>
          <input type="number" id="cs-area" name="living_area" min="0" step="0.1" value="<?= (float) $consumptionSettings['living_area'] ?: '' ?>">
        </div>
      </div>
    </div>
    <div class="form-group">
      <label for="cs-location"><?= translate('location_postal', $i18n) ?></label>
      <input type="text" id="cs-location" name="location" maxlength="80" autocomplete="postal-code"
        value="<?= htmlspecialchars($consumptionSettings['location_name']) ?>" placeholder="10115">
      <small class="loan-anchor-hint"><?= translate('location_hint', $i18n) ?></small>
    </div>
    <div class="buttons">
      <input type="button" value="<?= translate('cancel', $i18n) ?>" class="secondary-button thin" onClick="closeConsumptionSettings()">
      <input type="submit" value="<?= translate('save', $i18n) ?>" class="thin">
    </div>
  </form>
</section>

<script src="scripts/libs/apexcharts.min.js"></script>
<script src="scripts/libs/sortable.min.js"></script>
<script src="scripts/apex-base.js?<?= $assetVersion ?>"></script>
<script type="text/javascript">
  window.mainCurrencyCode = "<?= $mainCurrencyCode ?>";
  window.meterChartData = <?php
  $chartData = [];
  foreach ($meters as $meter) {
    $chartData[$meter['id']] = [
      'monthly' => $meter['monthly'],
      'unit' => ($meterTypes[$meter['meter_type']] ?? $meterTypes['electricity'])['unit'],
      'is_pv' => $meter['meter_type'] === 'pv_feed_in',
      'name' => $meter['name'],
      'years' => yearComparison($meter['monthly']),
    ];
  }
  echo json_encode($chartData);
  ?>;
  window.meterReadingsData = <?= json_encode($readingsData) ?>;
</script>
<script src="scripts/homeassistant.js?<?= $assetVersion ?>"></script>
<script src="scripts/reading-forecast.js?<?= $assetVersion ?>"></script>
<script src="scripts/consumption.js?<?= $assetVersion ?>"></script>
<?php
$db->close();
require_once 'includes/footer.php';
?>
