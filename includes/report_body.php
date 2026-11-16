<?php
// Cost report content, shared by report.php and the read-only share link (share.php).
// Needs $db, $userId, $i18n and $mainCurrencyCode (or $currencies + $main_currency).
require_once __DIR__ . '/household_costs.php';
$mainCurrencyCode ??= $currencies[$main_currency]['code'] ?? 'EUR';
$fmt = fn($amount) => CurrencyFormatter::format($amount, $mainCurrencyCode);
$areas = [
  'subscription' => 'subscriptions',
  'contract' => 'contracts',
  'insurance' => 'insurances',
  'loan' => 'financing',
  'meter' => 'consumption',
];

$items = householdCostItems($db, $userId);
usort($items, fn($a, $b) => $b['monthly'] <=> $a['monthly']);
$byArea = array_fill_keys(array_keys($areas), []);
foreach ($items as $item) {
  $byArea[$item['type']][] = $item;
}
$total = array_sum(array_column($items, 'monthly'));

$members = [];
$stmt = $db->prepare('SELECT id, name FROM household WHERE user_id = :userId');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $members[(int) $row['id']] = $row['name'];
}
$byPayer = [];
foreach ($items as $item) {
  $name = $members[$item['payer_id']] ?? translate('report_shared', $i18n);
  $byPayer[$name] = ($byPayer[$name] ?? 0) + $item['monthly'];
}
arsort($byPayer);

$changes = [];
$stmt = $db->prepare("SELECT ph.*, COALESCE(s.name, c.name, i.name) AS name FROM price_history ph
    LEFT JOIN subscriptions s ON ph.item_type = 'subscription' AND s.id = ph.item_id
    LEFT JOIN contracts c ON ph.item_type = 'contract' AND c.id = ph.item_id
    LEFT JOIN insurances i ON ph.item_type = 'insurance' AND i.id = ph.item_id
    WHERE ph.user_id = :userId AND ph.changed_at >= date('now', '-12 months') ORDER BY ph.changed_at DESC");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  if ($row['name'] !== null) {
    $changes[] = $row;
  }
}

// Year comparison: every item at the price it had 12 months ago (first old price
// vs. latest new price in the window). Items added during the year count as unchanged.
$priceFactor = [];
foreach (array_reverse($changes) as $c) {
  $key = $c['item_type'] . ':' . $c['item_id'];
  $priceFactor[$key]['old'] ??= (float) $c['old_price'];
  $priceFactor[$key]['new'] = (float) $c['new_price'];
}
$previous = fn($item) => isset($priceFactor[$item['type'] . ':' . $item['id']]) && $priceFactor[$item['type'] . ':' . $item['id']]['new'] > 0
  ? $item['monthly'] * $priceFactor[$item['type'] . ':' . $item['id']]['old'] / $priceFactor[$item['type'] . ':' . $item['id']]['new']
  : $item['monthly'];
$previousTotal = array_sum(array_map($previous, $items));
$taxItems = array_filter($items, fn($item) => $item['tax']);

// Savings potential: simple rules, each pointing at something worth a look.
require_once __DIR__ . '/cancellation_helpers.php';
$savings = [];
$byCategory = [];
$stmt = $db->prepare('SELECT s.name, c.name AS category FROM subscriptions s JOIN categories c ON c.id = s.category_id
    WHERE s.user_id = :userId AND s.archived = 0 AND s.inactive = 0 AND s.category_id > 1');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $byCategory[$row['category']][] = $row['name'];
}
foreach ($byCategory as $category => $names) {
  if (count($names) > 1) {
    $savings[] = ['fa-clone', sprintf(translate('savings_duplicate_category', $i18n), $category, implode(', ', $names))];
  }
}
$byInsuranceType = [];
$stmt = $db->prepare("SELECT name, insurance_type FROM insurances WHERE user_id = :userId AND archived = 0 AND insurance_type != 'other'");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $byInsuranceType[$row['insurance_type']][] = $row['name'];
}
foreach ($byInsuranceType as $type => $names) {
  if (count($names) > 1) {
    $savings[] = ['fa-shield-halved', sprintf(translate('savings_duplicate_insurance', $i18n), (isset($i18n['insurance_type_' . $type]) ? translate('insurance_type_' . $type, $i18n) : $type), implode(', ', $names))];
  }
}
foreach (['contracts', 'insurances'] as $table) {
  $stmt = $db->prepare("SELECT * FROM $table WHERE user_id = :userId AND archived = 0 AND cancellation_status = 0");
  $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $result = $stmt->execute();
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $deadline = getNextCancellationDeadline($row)['deadline'] ?? null;
    if ($deadline && $deadline <= date('Y-m-d', strtotime('+90 days'))) {
      $savings[] = ['fa-scissors', sprintf(translate('savings_cancellable', $i18n), $row['name'], date('d.m.Y', strtotime($deadline)))];
    }
  }
}
foreach ($changes as $c) {
  if ($c['item_type'] !== 'subscription' && $c['new_price'] > $c['old_price'] && $c['changed_at'] >= date('Y-m-d', strtotime('-3 months'))) {
    $savings[] = ['fa-arrow-trend-up', sprintf(translate('savings_price_increase', $i18n), $c['name'])];
  }
}
$stmt = $db->prepare("SELECT name, fixed_interest_end FROM loans WHERE user_id = :userId AND archived = 0
    AND fixed_interest_end BETWEEN date('now') AND date('now', '+24 months')");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $savings[] = ['fa-percent', sprintf(translate('savings_fixed_interest', $i18n), $row['name'], date('d.m.Y', strtotime($row['fixed_interest_end'])))];
}
?>

<section class="contain report">
  <div class="split-header">
    <h2><?= translate('cost_report', $i18n) ?> · <?= date('d.m.Y') ?></h2>
    <?php if (empty($readOnly)): ?>
    <div class="no-print">
      <a class="button thin" href="endpoints/report/export_all.php"><i class="fa-solid fa-file-zipper"></i> ZIP</a>
      <a class="button thin" href="endpoints/report/export.php"><i class="fa-solid fa-file-csv"></i> CSV</a>
      <button class="button thin" onclick="window.print()"><i class="fa-solid fa-print"></i> <?= translate('report_print', $i18n) ?></button>
    </div>
    <?php endif; ?>
  </div>
  <?php if (empty($readOnly)): ?><p class="no-print settings-notes"><?= translate('report_intro', $i18n) ?></p><?php endif; ?>

  <div class="statistics dashboard-tiles">
    <div class="statistic highlight"><span><?= $fmt($total) ?></span><div class="title"><?= translate('total_monthly_burden', $i18n) ?></div></div>
    <?php
    require_once __DIR__ . '/finances.php';
    $reportIncome = array_sum(array_column(financeIncomes($db, $userId), 'monthly'));
    if ($reportIncome > 0): ?>
      <div class="statistic"><span><?= $fmt($reportIncome) ?></span><div class="title"><?= translate('income_monthly', $i18n) ?></div></div>
      <div class="statistic"><span><?= $fmt($reportIncome - $total) ?></span><div class="title"><?= translate('left_before_savings', $i18n) ?></div></div>
    <?php endif; ?>
    <div class="statistic"><span><?= $fmt($total * 12) ?></span><div class="title"><?= translate('report_yearly', $i18n) ?></div></div>
    <div class="statistic"><span><?= count($items) ?></span><div class="title"><?= translate('report_items', $i18n) ?></div></div>
    <?php if (abs($total - $previousTotal) >= 0.01): ?>
      <div class="statistic"><span><?= sprintf('%+.2f', ($total - $previousTotal) * 12) ?></span><div class="title"><?= translate('report_vs_last_year', $i18n) ?></div></div>
    <?php endif; ?>
    <?php if ($taxItems): ?>
      <div class="statistic"><span><?= $fmt(array_sum(array_column($taxItems, 'monthly')) * 12) ?></span><div class="title"><?= translate('report_tax_yearly', $i18n) ?></div></div>
    <?php endif; ?>
  </div>

  <?php if ($savings): ?>
    <h3><i class="fa-solid fa-piggy-bank"></i> <?= translate('savings_potential', $i18n) ?></h3>
    <ul class="savings-list">
      <?php foreach ($savings as [$icon, $text]): ?>
        <li><i class="fa-solid <?= $icon ?>"></i> <?= htmlspecialchars($text) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (count($byPayer) > 1): ?>
    <h3><?= translate('report_per_person', $i18n) ?></h3>
    <table class="bank-table">
      <tr><th><?= translate('name', $i18n) ?></th><th><?= translate('report_monthly', $i18n) ?></th><th><?= translate('report_yearly', $i18n) ?></th><th>%</th></tr>
      <?php foreach ($byPayer as $name => $monthly): ?>
        <tr><td><?= htmlspecialchars($name) ?></td><td><?= $fmt($monthly) ?></td><td><?= $fmt($monthly * 12) ?></td>
          <td><?= $total > 0 ? number_format($monthly / $total * 100, 1, ',', '.') : 0 ?> %</td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php $budgets = categoryBudgets($db, $userId, $items); if ($budgets): ?>
    <h3><?= translate('category_budgets', $i18n) ?></h3>
    <table class="bank-table">
      <tr><th><?= translate('category', $i18n) ?></th><th><?= translate('budget', $i18n) ?></th><th><?= translate('report_monthly', $i18n) ?></th><th>%</th></tr>
      <?php foreach ($budgets as $cb): ?>
        <tr class="<?= $cb['spent'] > $cb['budget'] ? 'warning' : '' ?>"><td><?= htmlspecialchars($cb['name']) ?></td><td><?= $fmt($cb['budget']) ?></td>
          <td><?= $fmt($cb['spent']) ?></td><td><?= round($cb['spent'] / $cb['budget'] * 100) ?> %</td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php foreach ($areas as $type => $label): if (!$byArea[$type]) continue;
    $sum = array_sum(array_column($byArea[$type], 'monthly')); ?>
    <h3><?= translate($label, $i18n) ?> · <?= $fmt($sum) ?> / <?= translate('month', $i18n) ?></h3>
    <table class="bank-table">
      <tr><th><?= translate('name', $i18n) ?></th><th><?= translate('report_payment', $i18n) ?></th><th><?= translate('report_monthly', $i18n) ?></th><th><?= translate('report_yearly', $i18n) ?></th><th><?= translate('report_last_year', $i18n) ?></th></tr>
      <?php foreach ($byArea[$type] as $item): $prev = $previous($item); ?>
        <tr><td><?= htmlspecialchars($item['name']) ?><?= $item['tax'] ? ' <i class="fa-solid fa-receipt" title="' . translate('tax_deductible', $i18n) . '"></i>' : '' ?></td><td><?= $fmt($item['amount']) ?></td>
          <td><?= $fmt($item['monthly']) ?></td><td><?= $fmt($item['monthly'] * 12) ?></td>
          <td><?= abs($prev - $item['monthly']) >= 0.01 ? $fmt($prev * 12) : '' ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>

  <?php if ($changes): ?>
    <h3><?= translate('report_price_changes', $i18n) ?></h3>
    <table class="bank-table">
      <?php foreach ($changes as $c): ?>
        <tr><td><?= htmlspecialchars($c['changed_at']) ?></td><td><?= htmlspecialchars($c['name']) ?></td>
          <td><?= number_format($c['old_price'], 2, ',', '.') ?> → <?= number_format($c['new_price'], 2, ',', '.') ?></td>
          <td><?= $c['old_price'] > 0 ? sprintf('%+.1f %%', ($c['new_price'] / $c['old_price'] - 1) * 100) : '' ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</section>

