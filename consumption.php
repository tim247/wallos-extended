<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/consumption_calculations.php';
require_once 'includes/homeassistant.php';
$haEnabled = homeAssistantConfig($db, $userId) !== null;

$showArchived = isset($_GET['state']) && $_GET['state'] === 'archived';

$meterTypes = [
  'electricity' => ['label' => 'meter_type_electricity', 'icon' => 'fa-bolt', 'unit' => 'kWh'],
  'water' => ['label' => 'meter_type_water', 'icon' => 'fa-droplet', 'unit' => 'm³'],
  'gas' => ['label' => 'meter_type_gas', 'icon' => 'fa-fire', 'unit' => 'm³'],
  'pv_feed_in' => ['label' => 'meter_type_pv_feed_in', 'icon' => 'fa-solar-panel', 'unit' => 'kWh'],
];

$mainCurrencyId = $main_currency;
$mainCurrencyCode = $currencies[$mainCurrencyId]['code'] ?? 'EUR';
$today = date('Y-m-d');
$endOfYear = date('Y') . '-12-31';

$stmt = $db->prepare("SELECT * FROM meters WHERE user_id = :userId AND archived = :archived ORDER BY meter_type ASC, name COLLATE NOCASE ASC");
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
    ];
  }

  $computed = computeConsumptionSegments($readings);
  $meter['segments'] = $computed['segments'];
  $meter['warnings'] = $computed['warnings'];
  $meter['monthly'] = computeMonthlyBuckets($computed['segments']);
  $meter['forecast'] = computeForecast($computed['segments'], $endOfYear);
  $meter['ytd_units'] = computeUnitsInWindow($computed['segments'], date('Y') . '-01-01', $today);

  $meter['forecast_cost'] = null;
  $meter['ytd_cost'] = null;
  $pricePerUnit = floatval($meter['price_per_unit']);
  $conversion = ($meter['meter_type'] === 'gas' && !empty($meter['conversion_factor']))
    ? floatval($meter['conversion_factor']) : 1.0;

  $monthsYtd = max(0, (strtotime($today) - strtotime(date('Y') . '-01-01')) / 86400 / 30.44);
  if ($meter['meter_type'] === 'pv_feed_in') {
    $meter['ytd_cost'] = round($meter['ytd_units'] * $conversion * $pricePerUnit, 2);
  } else {
    $meter['ytd_cost'] = round($meter['ytd_units'] * $conversion * $pricePerUnit + floatval($meter['base_fee_monthly']) * $monthsYtd, 2);
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

  // Advance payments (Abschlag) vs. projected cost of the current billing year.
  $meter['settlement'] = null;
  $advance = floatval($meter['monthly_advance'] ?? 0);
  if ($advance > 0 && $meter['meter_type'] !== 'pv_feed_in') {
    $periodStart = !empty($meter['billing_start']) ? $meter['billing_start'] : date('Y') . '-01-01';
    // Roll the billing year forward until it contains today.
    for ($guard = 0; date('Y-m-d', strtotime($periodStart . ' +1 year')) <= $today && $guard < 100; $guard++) {
      $periodStart = date('Y-m-d', strtotime($periodStart . ' +1 year'));
    }
    $periodEnd = date('Y-m-d', strtotime($periodStart . ' +1 year'));
    $periodForecast = computeForecast($computed['segments'], $periodEnd);
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

$headerClass = count($meters) > 0 ? "main-actions" : "main-actions hidden";
?>

<section class="contain">
  <header class="<?= $headerClass ?>" id="main-actions">
    <button class="button" onClick="addMeter()">
      <i class="fa-solid fa-circle-plus"></i>
      <?= translate('new_meter', $i18n) ?>
    </button>
    <div class="top-actions">
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
          <i class="fa-solid <?= $typeInfo['icon'] ?>"></i>
          <h3><?= htmlspecialchars($meter['name']) ?></h3>
          <span class="meter-panel-type"><?= translate($typeInfo['label'], $i18n) ?>
            <?= $meter['meter_number'] ? '· ' . htmlspecialchars($meter['meter_number']) : '' ?></span>
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
          <form class="reading-form inline-form" data-meter="<?= $meter['id'] ?>">
            <div class="date-wrapper">
              <input type="date" class="reading-date" value="<?= $today ?>" required>
            </div>
            <input type="number" step="0.001" min="0" class="reading-value"
              placeholder="<?= translate('meter_reading', $i18n) ?> (<?= $unitLabel ?>)" required>
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
                <?php if ($meter['forecast_cost'] !== null): ?>
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

          <?php if ($meter['settlement'] !== null):
            $settlement = $meter['settlement'];
            $isCredit = $settlement['difference'] < 0; ?>
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
            autocomplete="off">
        </div>
        <div class="split50" id="meter-base-fee-group">
          <label for="meter-base-fee"><?= translate('base_fee_monthly', $i18n) ?></label>
          <input type="number" step="0.01" min="0" id="meter-base-fee" name="base_fee_monthly" autocomplete="off">
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
      <input type="number" step="0.001" min="0" id="reading-edit-value" autocomplete="off" required>
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

<script src="scripts/libs/apexcharts.min.js"></script>
<script src="scripts/apex-base.js?<?= $version ?>"></script>
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
    ];
  }
  echo json_encode($chartData);
  ?>;
  window.meterReadingsData = <?= json_encode($readingsData) ?>;
</script>
<script src="scripts/homeassistant.js?<?= $version ?>"></script>
<script src="scripts/consumption.js?<?= $version ?>"></script>
<?php
$db->close();
require_once 'includes/footer.php';
?>
