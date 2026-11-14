<?php
// Cost report for printing or "Save as PDF" in the browser: every recurring cost,
// per area and per household member, plus the price changes of the last 12 months.

require_once 'includes/header.php';
require_once 'includes/household_costs.php';

$mainCurrencyCode = $currencies[$main_currency]['code'] ?? 'EUR';
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
?>

<section class="contain report">
  <div class="split-header">
    <h2><?= translate('cost_report', $i18n) ?> · <?= date('d.m.Y') ?></h2>
    <button class="button thin no-print" onclick="window.print()"><i class="fa-solid fa-print"></i> <?= translate('report_print', $i18n) ?></button>
  </div>
  <p class="no-print settings-notes"><?= translate('report_intro', $i18n) ?></p>

  <div class="statistics dashboard-tiles">
    <div class="statistic highlight"><span><?= $fmt($total) ?></span><div class="title"><?= translate('total_monthly_burden', $i18n) ?></div></div>
    <div class="statistic"><span><?= $fmt($total * 12) ?></span><div class="title"><?= translate('report_yearly', $i18n) ?></div></div>
    <div class="statistic"><span><?= count($items) ?></span><div class="title"><?= translate('report_items', $i18n) ?></div></div>
  </div>

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

  <?php foreach ($areas as $type => $label): if (!$byArea[$type]) continue;
    $sum = array_sum(array_column($byArea[$type], 'monthly')); ?>
    <h3><?= translate($label, $i18n) ?> · <?= $fmt($sum) ?> / <?= translate('month', $i18n) ?></h3>
    <table class="bank-table">
      <tr><th><?= translate('name', $i18n) ?></th><th><?= translate('report_payment', $i18n) ?></th><th><?= translate('report_monthly', $i18n) ?></th><th><?= translate('report_yearly', $i18n) ?></th></tr>
      <?php foreach ($byArea[$type] as $item): ?>
        <tr><td><?= htmlspecialchars($item['name']) ?></td><td><?= $fmt($item['amount']) ?></td>
          <td><?= $fmt($item['monthly']) ?></td><td><?= $fmt($item['monthly'] * 12) ?></td></tr>
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

<?php require_once 'includes/footer.php'; ?>
