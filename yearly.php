<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/yearly_dues.php';

$mainCurrencyCode = $currencies[$main_currency]['code'] ?? 'EUR';
$from = date('Y-m-01');
$dues = collectYearlyDues($db, $userId, $from, $i18n);
$months = $dues['months'];
$items = $dues['items'];

$totals = array_map(fn($m) => round($m['total'], 2), $months);
$yearTotal = array_sum($totals);
$average = $yearTotal / 12;
$plan = yearlyReservePlan($totals);
$maxIndex = $totals ? array_search(max($totals), $totals) : 0;

$typeIcons = ['subscription' => 'fa-credit-card', 'contract' => 'fa-file-signature', 'insurance' => 'fa-shield-halved'];
$fmt = fn($amount) => CurrencyFormatter::format($amount, $mainCurrencyCode);

$chartData = [
    'labels' => array_map(fn($m) => translate('month-' . sprintf('%02d', $m['month']), $i18n) . ' ' . $m['year'], $months),
    'totals' => $totals,
    'currency' => $mainCurrencyCode,
];
?>

<section class="contain">
  <div class="split-header">
    <h2><?= translate('yearly_overview', $i18n) ?></h2>
  </div>
  <p class="yearly-intro"><?= translate('yearly_intro', $i18n) ?></p>

  <?php if (count($items) === 0): ?>
    <div class="empty-page">
      <img src="images/siteimages/empty.png" alt="<?= translate('empty_page', $i18n) ?>" />
      <p><?= translate('yearly_empty', $i18n) ?></p>
    </div>
  <?php else: ?>

    <div class="statistics">
      <div class="statistic">
        <span><?= $fmt($yearTotal) ?></span>
        <div class="title"><?= translate('yearly_total_due', $i18n) ?></div>
      </div>
      <div class="statistic">
        <span><?= $fmt($average) ?></span>
        <div class="title"><?= translate('yearly_average_reserve', $i18n) ?></div>
      </div>
      <div class="statistic highlight">
        <span id="yearly-reserve-monthly"><?= $fmt($plan['monthly']) ?></span>
        <div class="title"><?= translate('yearly_recommended_reserve', $i18n) ?></div>
      </div>
      <div class="statistic">
        <span><?= $fmt($totals[$maxIndex]) ?></span>
        <div class="title">
          <?= translate('yearly_peak_month', $i18n) ?>:
          <?= translate('month-' . sprintf('%02d', $months[$maxIndex]['month']), $i18n) ?>
        </div>
      </div>
    </div>

    <div class="yearly-reserve-start">
      <label for="yearly-start"><?= translate('yearly_already_saved', $i18n) ?></label>
      <input type="number" id="yearly-start" min="0" step="10" value="0" autocomplete="off">
      <span><?= $mainCurrencyCode ?></span>
    </div>
    <p class="yearly-hint"><?= translate('yearly_reserve_hint', $i18n) ?></p>

    <div class="graphs">
      <div class="graph x2">
        <h3><?= translate('yearly_chart_title', $i18n) ?></h3>
        <div style="position:relative; height:300px; width:100%;">
          <canvas id="yearlyChart"></canvas>
        </div>
      </div>
    </div>

    <div class="dues-grid">
      <?php foreach ($months as $month): ?>
        <div class="dues-month <?= $month['total'] > $average * 1.5 ? 'heavy' : '' ?> <?= $month['entries'] ? '' : 'empty' ?>">
          <header>
            <strong><?= translate('month-' . sprintf('%02d', $month['month']), $i18n) ?> <?= $month['year'] ?></strong>
            <span><?= $month['total'] > 0 ? $fmt($month['total']) : '–' ?></span>
          </header>
          <?php foreach ($month['entries'] as $entry): ?>
            <a class="dues-entry" href="<?= $entry['link'] ?>">
              <i class="fa-solid <?= $typeIcons[$entry['type']] ?>"></i>
              <span class="dues-entry-name"><?= htmlspecialchars($entry['name']) ?></span>
              <small><?= date('d.m.', strtotime($entry['date'])) ?></small>
              <span><?= $fmt($entry['amount']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <h3 class="yearly-table-title"><?= translate('yearly_all_payments', $i18n) ?></h3>
    <div class="yearly-table-wrap">
      <table class="schedule-table">
        <thead>
          <tr>
            <th><?= translate('name', $i18n) ?></th>
            <th><?= translate('yearly_rhythm', $i18n) ?></th>
            <th><?= translate('price', $i18n) ?></th>
            <th><?= translate('yearly_next_due', $i18n) ?></th>
            <th><?= translate('yearly_share_per_month', $i18n) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td>
                <a href="<?= $item['link'] ?>"><i class="fa-solid <?= $typeIcons[$item['type']] ?>"></i>
                  <?= htmlspecialchars($item['name']) ?></a>
              </td>
              <td><?= $item['cycle_label'] ?></td>
              <td><?= $fmt($item['price']) ?></td>
              <td><?= date('d.m.Y', strtotime($item['dates'][0])) ?></td>
              <td><?= $fmt($item['share']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="settings-notes">
      <p><i class="fa-solid fa-circle-info"></i> <?= translate('yearly_note', $i18n) ?></p>
    </div>
  <?php endif; ?>
</section>

<?php if (count($items) > 0): ?>
  <script src="scripts/libs/chart.js"></script>
  <script src="scripts/yearly.js?<?= $version ?>"></script>
  <script>
    initYearly(<?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>);
  </script>
<?php endif; ?>

<?php
require_once 'includes/footer.php';
