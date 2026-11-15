<?php
// Emergency dossier content: emergency.php (logged in) and emergency_access.php (trusted person).
// Needs $db, $userId, $i18n, $lang, $currencies and $main_currency.
require_once __DIR__ . '/cancellation_helpers.php';
require_once __DIR__ . '/financing_calculations.php';
require_once __DIR__ . '/life_admin.php';
$mainCurrencyCode = $currencies[$main_currency]['code'] ?? 'EUR';
$money = fn($amount, $currencyId) => CurrencyFormatter::format((float) $amount, $currencies[$currencyId]['code'] ?? $mainCurrencyCode);
$cycleLabel = fn($cycle, $frequency) => ((int) $frequency > 1 ? (int) $frequency . '× ' : '')
  . translate([1 => 'daily', 2 => 'weekly', 3 => 'monthly', 4 => 'yearly'][(int) $cycle] ?? 'monthly', $i18n);
$rows = function (string $sql) use ($db, $userId): array {
  $stmt = $db->prepare($sql);
  $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $result = $stmt->execute();
  $out = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $out[] = $row;
  }
  return $out;
};
$h = fn($value) => htmlspecialchars((string) $value);

$notes = $rows('SELECT notes, updated_at FROM emergency_notes WHERE user_id = :userId')[0] ?? ['notes' => '', 'updated_at' => null];
$contracts = $rows('SELECT * FROM contracts WHERE user_id = :userId AND archived = 0 ORDER BY name');
$insurances = $rows('SELECT * FROM insurances WHERE user_id = :userId AND archived = 0 ORDER BY name');
$loans = $rows('SELECT * FROM loans WHERE user_id = :userId AND archived = 0 ORDER BY name');
$subscriptions = $rows('SELECT * FROM subscriptions WHERE user_id = :userId AND archived = 0 AND inactive = 0 ORDER BY name');
$meters = $rows('SELECT * FROM meters WHERE user_id = :userId AND archived = 0 ORDER BY name');
$documents = $rows("SELECT * FROM reminders WHERE user_id = :userId AND kind = 'document' ORDER BY name");
$paymentMethods = $rows('SELECT id, name FROM payment_methods WHERE user_id = :userId AND enabled = 1 ORDER BY name');
$methodName = array_column($paymentMethods, 'name', 'id');
$deadline = function (array $row): string {
  $info = getNextCancellationDeadline($row);
  return $info ? date('d.m.Y', strtotime($info['deadline'])) : '–';
};
?>
  <div id="dossier" class="dossier">
    <h1><?= translate('emergency', $i18n) ?> · <?= date('d.m.Y') ?></h1>

    <?php if (trim($notes['notes']) !== ''): ?>
      <h2><?= translate('emergency_notes', $i18n) ?></h2>
      <div class="dossier-notes"><?= nl2br($h($notes['notes'])) ?></div>
    <?php endif; ?>

    <?php if ($insurances): ?>
      <h2><?= translate('insurances', $i18n) ?></h2>
      <table class="bank-table">
        <tr><th><?= translate('name', $i18n) ?></th><th><?= translate('insurer', $i18n) ?> / <?= translate('policy_number', $i18n) ?></th><th><?= translate('report_payment', $i18n) ?></th><th><?= translate('cancellable_until', $i18n) ?></th></tr>
        <?php foreach ($insurances as $row): ?>
          <tr><td><strong><?= $h($row['name']) ?></strong><br><small><?= translate('insurance_type_' . $row['insurance_type'], $i18n) ?></small></td>
            <td><?= $h($row['insurer']) ?><br><small><?= $h($row['policy_number']) ?></small><?= !empty($row['provider_address']) ? '<br><small>' . nl2br($h($row['provider_address'])) . '</small>' : '' ?></td>
            <td><?= $money($row['price'], $row['currency_id']) ?> <small><?= $cycleLabel($row['cycle'], $row['frequency']) ?></small><br><small><?= $h($methodName[$row['payment_method_id']] ?? '') ?></small></td>
            <td><?= $deadline($row) ?><br><small><?= (int) $row['notice_period_months'] ?> <?= translate('month', $i18n) ?> <?= translate('notice_period', $i18n) ?></small></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if ($contracts): ?>
      <h2><?= translate('contracts', $i18n) ?></h2>
      <table class="bank-table">
        <tr><th><?= translate('name', $i18n) ?></th><th><?= translate('provider', $i18n) ?> / <?= translate('contract_number', $i18n) ?></th><th><?= translate('report_payment', $i18n) ?></th><th><?= translate('cancellable_until', $i18n) ?></th></tr>
        <?php foreach ($contracts as $row): ?>
          <tr><td><strong><?= $h($row['name']) ?></strong><?= $row['url'] ? '<br><small>' . $h($row['url']) . '</small>' : '' ?></td>
            <td><?= $h($row['provider']) ?><br><small><?= $h($row['contract_number']) ?></small><?= !empty($row['provider_address']) ? '<br><small>' . nl2br($h($row['provider_address'])) . '</small>' : '' ?></td>
            <td><?= $money($row['price'], $row['currency_id']) ?> <small><?= $cycleLabel($row['cycle'], $row['frequency']) ?></small><br><small><?= $h($methodName[$row['payment_method_id']] ?? '') ?></small></td>
            <td><?= $deadline($row) ?><br><small><?= (int) $row['notice_period_months'] ?> <?= translate('month', $i18n) ?> <?= translate('notice_period', $i18n) ?></small></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if ($loans): ?>
      <h2><?= translate('financing', $i18n) ?></h2>
      <table class="bank-table">
        <tr><th><?= translate('name', $i18n) ?></th><th><?= translate('lender', $i18n) ?></th><th><?= translate('monthly_rate', $i18n) ?></th><th><?= translate('remaining_debt_today', $i18n) ?></th></tr>
        <?php foreach ($loans as $row): $schedule = loadLoanSchedule($db, $userId, $row, date('Y-m-d')); ?>
          <tr><td><strong><?= $h($row['name']) ?></strong><?= $row['notes'] ? '<br><small>' . $h($row['notes']) . '</small>' : '' ?></td><td><?= $h($row['lender']) ?></td>
            <td><?= $money($row['monthly_rate'], $row['currency_id']) ?></td><td><?= $money($schedule['balance_as_of'] ?? 0, $row['currency_id']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if ($subscriptions): ?>
      <h2><?= translate('subscriptions', $i18n) ?></h2>
      <table class="bank-table">
        <tr><th><?= translate('name', $i18n) ?></th><th><?= translate('report_payment', $i18n) ?></th><th><?= translate('payment_method', $i18n) ?></th></tr>
        <?php foreach ($subscriptions as $row): ?>
          <tr><td><strong><?= $h($row['name']) ?></strong><?= $row['url'] ? '<br><small>' . $h($row['url']) . '</small>' : '' ?></td>
            <td><?= $money($row['price'], $row['currency_id']) ?> <small><?= $cycleLabel($row['cycle'], $row['frequency']) ?></small></td>
            <td><?= $h($methodName[$row['payment_method_id']] ?? '') ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if ($paymentMethods): ?>
      <h2><?= translate('emergency_accounts', $i18n) ?></h2>
      <p><?= $h(implode(', ', array_column($paymentMethods, 'name'))) ?></p>
    <?php endif; ?>

    <?php if ($meters): ?>
      <h2><?= translate('consumption', $i18n) ?></h2>
      <table class="bank-table">
        <?php foreach ($meters as $row): ?>
          <tr><td><?= $h($row['name']) ?></td><td><?= translate('meter_type_' . $row['meter_type'], $i18n) ?></td><td><?= translate('meter_number', $i18n) ?>: <?= $h($row['meter_number']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>

    <?php if ($documents): ?>
      <h2><?= translate('reminders_document', $i18n) ?></h2>
      <table class="bank-table">
        <?php foreach ($documents as $row): ?>
          <tr><td><?= $h($row['name']) ?></td><td><?= $h($row['holder']) ?></td><td><?= $row['due_date'] ? translate('ends_on', $i18n) . ' ' . date('d.m.Y', strtotime($row['due_date'])) : '' ?></td><td><small><?= $h($row['notes']) ?></small></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
