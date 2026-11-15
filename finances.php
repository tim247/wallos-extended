<?php
// Budget planning: income against every cost, variable expenses, savings goals, the balance
// over the month, the changes ahead, the split per person and a what-if calculator.
require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/household_costs.php';
require_once 'includes/finances.php';

$mainCurrencyCode = $currencies[$main_currency]['code'] ?? 'EUR';
$fmt = fn($amount) => CurrencyFormatter::format($amount, $mainCurrencyCode);
$readOnlyView = !empty($isReadOnlyAccount);

$incomes = financeIncomes($db, $userId);
$goals = financeGoals($db, $userId);
$expenses = financeExpenses($db, $userId);
$costItems = householdCostItems($db, $userId);
$incomeMonthly = array_sum(array_column($incomes, 'monthly'));
$fixedMonthly = array_sum(array_column($costItems, 'monthly'));
$variableMonthly = array_sum(array_column($expenses, 'monthly'));
$savingsMonthly = array_sum(array_column($goals, 'monthly_needed'));
$left = $incomeMonthly - $fixedMonthly - $variableMonthly - $savingsMonthly;

$breakdown = financeBreakdown($incomes, $costItems, $expenses, $goals);
$lineLabels = ['insurance' => 'insurances', 'loan' => 'financing', 'contract' => 'contracts', 'subscription' => 'subscriptions',
    'meter' => 'consumption', 'variable' => 'variable_expenses', 'savings' => 'savings_goals'];
$cashflow = financeCashflow($db, $userId, $incomes, $costItems, $expenses, (int) date('Y'), (int) date('n'));
$outlook = financeOutlook($db, $userId, $incomes);
$people = financePerPerson($db, $userId, $incomes, $costItems, $expenses);
$loanMonthly = array_sum(array_column(array_filter($costItems, fn($item) => $item['type'] === 'loan'), 'monthly'));

$members = [];
$memberStmt = $db->prepare('SELECT id, name FROM household WHERE user_id = :userId ORDER BY id');
$memberStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$memberResult = $memberStmt->execute();
while ($memberRow = $memberResult->fetchArray(SQLITE3_ASSOC)) {
  $members[(int) $memberRow['id']] = $memberRow['name'];
}
$cycles = [3 => translate('monthly', $i18n), 4 => translate('yearly', $i18n), 2 => translate('weekly', $i18n), 1 => translate('daily', $i18n)];
$actions = function (string $kind, array $row) use ($readOnlyView): string {
  if ($readOnlyView) {
    return '';
  }
  $json = htmlspecialchars(json_encode($row, JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES);
  return '<td class="no-print"><button class="image-button" onclick="editFinance(\'' . $kind . '\', JSON.parse(this.dataset.row))" data-row="' . $json . '"><i class="fa-solid fa-pen"></i></button>'
    . '<button class="image-button" onclick="deleteFinance(\'' . $kind . '\', ' . (int) $row['id'] . ')"><i class="fa-solid fa-trash-can"></i></button></td>';
};
?>

<section class="contain finances">
  <h2><?= translate('finances', $i18n) ?></h2>
  <p class="settings-notes"><?= translate('finances_intro', $i18n) ?></p>

  <div class="statistics dashboard-tiles">
    <div class="statistic"><span><?= $fmt($incomeMonthly) ?></span><div class="title"><?= translate('income_monthly', $i18n) ?></div></div>
    <div class="statistic"><span><?= $fmt($fixedMonthly) ?></span><div class="title"><?= translate('fixed_costs_monthly', $i18n) ?></div></div>
    <div class="statistic"><span><?= $fmt($variableMonthly) ?></span><div class="title"><?= translate('variable_expenses', $i18n) ?></div></div>
    <div class="statistic"><span><?= $fmt($savingsMonthly) ?></span><div class="title"><?= translate('savings_monthly', $i18n) ?></div></div>
    <div class="statistic highlight <?= $left < 0 ? 'negative' : '' ?>"><span><?= $fmt($left) ?></span><div class="title"><?= translate('left_monthly', $i18n) ?></div></div>
  </div>

  <?php if ($breakdown): ?>
    <h3><i class="fa-solid fa-scale-balanced"></i> <?= translate('budget_breakdown', $i18n) ?></h3>
    <table class="bank-table budget-breakdown">
      <tr><td><strong><?= translate('incomes', $i18n) ?></strong></td><td><strong><?= $fmt($incomeMonthly) ?></strong></td><td></td></tr>
      <?php foreach ($breakdown as $line): ?>
        <tr>
          <td><?= translate($lineLabels[$line['key']], $i18n) ?></td>
          <td>− <?= $fmt($line['monthly']) ?></td>
          <td><?php if ($line['share'] !== null): ?>
            <div class="share-bar"><div style="width: <?= min(100, round($line['share'] * 100)) ?>%"></div></div>
            <small><?= number_format($line['share'] * 100, 1, ',', '.') ?> %</small><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <tr class="<?= $left < 0 ? 'warning' : '' ?>"><td><strong><?= translate('left_monthly', $i18n) ?></strong></td><td><strong><?= $fmt($left) ?></strong></td>
        <td><?= $incomeMonthly > 0 ? '<small>' . number_format($left / $incomeMonthly * 100, 1, ',', '.') . ' %</small>' : '' ?></td></tr>
    </table>
  <?php endif; ?>

  <h3><i class="fa-solid fa-sack-dollar"></i> <?= translate('incomes', $i18n) ?></h3>
  <table class="bank-table">
    <?php foreach ($incomes as $income): ?>
      <tr>
        <td><?= htmlspecialchars($income['name']) ?><?= isset($members[(int) $income['payer_user_id']]) ? ' <small>· ' . htmlspecialchars($members[(int) $income['payer_user_id']]) . '</small>' : '' ?></td>
        <td><?= CurrencyFormatter::format((float) $income['amount'], $currencies[$income['currency_id']]['code'] ?? $mainCurrencyCode) ?>
          <small><?= ((int) $income['frequency'] > 1 ? (int) $income['frequency'] . '× ' : '') . ($cycles[(int) $income['cycle']] ?? '') ?></small></td>
        <td><small><?= sprintf(translate('pay_day_short', $i18n), (int) $income['pay_day']) ?><?= $income['end_date'] ? ' · ' . translate('until', $i18n) . ' ' . date('m/Y', strtotime($income['end_date'])) : '' ?></small></td>
        <?= $actions('income', $income) ?>
      </tr>
    <?php endforeach; ?>
  </table>

  <h3><i class="fa-solid fa-cart-shopping"></i> <?= translate('variable_expenses', $i18n) ?></h3>
  <p class="settings-notes"><?= translate('variable_expenses_info', $i18n) ?></p>
  <table class="bank-table">
    <?php foreach ($expenses as $expense): ?>
      <tr>
        <td><?= htmlspecialchars($expense['name']) ?><?= isset($members[(int) $expense['payer_user_id']]) ? ' <small>· ' . htmlspecialchars($members[(int) $expense['payer_user_id']]) . '</small>' : '' ?></td>
        <td><?= $fmt($expense['monthly']) ?> / <?= translate('month', $i18n) ?></td>
        <?= $actions('expense', $expense) ?>
      </tr>
    <?php endforeach; ?>
  </table>

  <h3><i class="fa-solid fa-piggy-bank"></i> <?= translate('savings_goals', $i18n) ?></h3>
  <?php foreach ($goals as $goal): ?>
    <div class="goal">
      <div class="goal-head">
        <strong><?= htmlspecialchars($goal['name']) ?></strong>
        <span><?= $fmt((float) $goal['saved_amount']) ?> / <?= $fmt((float) $goal['target_amount']) ?></span>
      </div>
      <div class="progress-bar"><div style="width: <?= round($goal['progress'] * 100) ?>%"></div></div>
      <small>
        <?php if ($goal['target_date']): ?><?= translate('target_date', $i18n) ?> <?= date('m/Y', strtotime($goal['target_date'])) ?> · <?php endif; ?>
        <?= $goal['monthly_needed'] > 0 ? sprintf(translate('goal_monthly_needed', $i18n), $fmt($goal['monthly_needed'])) : ($goal['progress'] >= 1 ? translate('goal_reached', $i18n) : '') ?>
      </small>
      <?php if (!$readOnlyView): ?><table class="inline-actions"><tr><?= $actions('goal', $goal) ?></tr></table><?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if (!$readOnlyView): ?>
    <div class="buttons no-print">
      <button class="button thin" onclick="editFinance('income', {})"><i class="fa-solid fa-plus"></i> <?= translate('add_income', $i18n) ?></button>
      <button class="button thin secondary-button" onclick="editFinance('expense', {})"><i class="fa-solid fa-plus"></i> <?= translate('add_expense', $i18n) ?></button>
      <button class="button thin secondary-button" onclick="editFinance('goal', {})"><i class="fa-solid fa-plus"></i> <?= translate('add_goal', $i18n) ?></button>
    </div>
  <?php endif; ?>

  <?php if ($incomes): ?>
    <h3><i class="fa-solid fa-chart-line"></i> <?= translate('cashflow_month', $i18n) ?></h3>
    <p class="settings-notes"><?= translate('cashflow_info', $i18n) ?></p>
    <div class="form-group-inline no-print">
      <label for="cashflow-start"><?= translate('cashflow_start_balance', $i18n) ?></label>
      <input type="number" id="cashflow-start" step="10" value="0" style="max-width: 140px">
    </div>
    <div id="cashflow-chart"></div>
    <p id="cashflow-lowest"></p>
  <?php endif; ?>

  <?php if ($outlook): ?>
    <h3><i class="fa-solid fa-binoculars"></i> <?= translate('outlook', $i18n) ?></h3>
    <table class="bank-table">
      <tr><th><?= translate('date', $i18n) ?></th><th><?= translate('name', $i18n) ?></th><th><?= translate('outlook_change', $i18n) ?></th><th><?= translate('left_monthly', $i18n) ?></th></tr>
      <?php $running = $left; foreach ($outlook as $event): $running += $event['delta']; ?>
        <tr>
          <td><?= date('m/Y', strtotime($event['date'])) ?></td>
          <td><?= htmlspecialchars($event['name']) ?> <small><?= translate($event['delta'] < 0 ? 'outlook_ends_income' : 'outlook_ends_cost', $i18n) ?></small></td>
          <td class="<?= $event['delta'] < 0 ? 'negative' : 'positive' ?>"><?= ($event['delta'] >= 0 ? '+' : '') . $fmt($event['delta']) ?></td>
          <td><?= $fmt($running) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if (count($people) > 1): ?>
    <h3><i class="fa-solid fa-people-arrows"></i> <?= translate('per_person', $i18n) ?></h3>
    <p class="settings-notes"><?= translate('per_person_info', $i18n) ?></p>
    <table class="bank-table">
      <tr><th><?= translate('name', $i18n) ?></th><th><?= translate('incomes', $i18n) ?></th><th><?= translate('per_person_pays', $i18n) ?></th><th><?= translate('per_person_fair', $i18n) ?></th><th><?= translate('per_person_balance', $i18n) ?></th></tr>
      <?php foreach ($people as $person): $diff = $person['fair'] - $person['pays']; ?>
        <tr>
          <td><?= htmlspecialchars($person['name']) ?></td>
          <td><?= $fmt($person['income']) ?></td>
          <td><?= $fmt($person['pays']) ?></td>
          <td><?= $fmt($person['fair']) ?></td>
          <td><?= abs($diff) < 1 ? '–' : sprintf(translate($diff > 0 ? 'per_person_should_add' : 'per_person_pays_more', $i18n), $fmt(abs($diff))) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <h3><i class="fa-solid fa-flask"></i> <?= translate('what_if', $i18n) ?></h3>
  <p class="settings-notes"><?= translate('what_if_info', $i18n) ?></p>
  <div class="what-if no-print">
    <label><?= translate('what_if_income', $i18n) ?><input type="number" step="10" data-what="income" value="0"></label>
    <label><?= translate('what_if_loan', $i18n) ?><input type="number" step="10" data-what="loan" value="0"></label>
    <label><?= translate('what_if_costs', $i18n) ?><input type="number" step="10" data-what="costs" value="0"></label>
    <label><?= translate('what_if_savings', $i18n) ?><input type="number" step="10" data-what="savings" value="0"></label>
  </div>
  <p class="what-if-result"><?= translate('left_monthly', $i18n) ?>: <strong id="what-if-left"><?= $fmt($left) ?></strong> <small id="what-if-delta"></small></p>

  <?php if (!$readOnlyView): ?>
    <dialog id="finance-dialog" class="finance-dialog">
      <form method="dialog" onsubmit="return saveFinance(this)">
        <input type="hidden" name="kind"><input type="hidden" name="id">
        <label><?= translate('name', $i18n) ?><input type="text" name="name" required maxlength="200"></label>
        <div class="income-only expense-only">
          <label><?= translate('price', $i18n) ?><input type="number" name="amount" step="0.01" min="0"></label>
        </div>
        <div class="income-only">
          <label><?= translate('every', $i18n) ?>
            <span class="form-group-inline"><input type="number" name="frequency" min="1" value="1" style="max-width: 70px">
              <select name="cycle"><?php foreach ($cycles as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select></span></label>
          <label><?= translate('currency', $i18n) ?><select name="currency_id">
            <?php foreach ($currencies as $currency): ?><option value="<?= $currency['id'] ?>" <?= $currency['id'] == $main_currency ? 'selected' : '' ?>><?= htmlspecialchars($currency['code']) ?></option><?php endforeach; ?>
          </select></label>
          <label><?= translate('pay_day', $i18n) ?><input type="number" name="pay_day" min="1" max="31" value="1"></label>
          <label><?= translate('income_end_date', $i18n) ?><input type="date" name="end_date"></label>
        </div>
        <div class="income-only expense-only">
          <label><?= translate('report_payer', $i18n) ?><select name="payer_user_id">
            <option value="0"><?= translate('report_shared', $i18n) ?></option>
            <?php foreach ($members as $memberId => $memberName): ?><option value="<?= $memberId ?>"><?= htmlspecialchars($memberName) ?></option><?php endforeach; ?>
          </select></label>
        </div>
        <div class="goal-only">
          <label><?= translate('goal_target', $i18n) ?><input type="number" name="target_amount" step="0.01" min="0"></label>
          <label><?= translate('goal_saved', $i18n) ?><input type="number" name="saved_amount" step="0.01" min="0"></label>
          <label><?= translate('target_date', $i18n) ?><input type="date" name="target_date"></label>
        </div>
        <label><?= translate('notes', $i18n) ?><input type="text" name="notes" maxlength="1000"></label>
        <div class="buttons">
          <button type="button" class="secondary-button thin" onclick="this.closest('dialog').close()"><?= translate('cancel', $i18n) ?></button>
          <input type="submit" class="thin" value="<?= translate('save', $i18n) ?>">
        </div>
      </form>
    </dialog>
  <?php endif; ?>
</section>

<script src="scripts/libs/apexcharts.min.js"></script>
<script>
  const financeData = <?= json_encode([
    'left' => round($left, 2),
    'cashflow' => array_values($cashflow),
    'currency' => $mainCurrencyCode,
    'locale' => str_replace('_', '-', $lang),
    'lowestText' => translate('cashflow_lowest', $i18n),
    'balanceLabel' => translate('cashflow_balance', $i18n),
    'deleteConfirm' => translate('finance_delete_confirm', $i18n),
  ], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const money = value => new Intl.NumberFormat(financeData.locale, { style: 'currency', currency: financeData.currency }).format(value);

  function editFinance(kind, item) {
    const dialog = document.getElementById('finance-dialog');
    const form = dialog.querySelector('form');
    form.reset();
    form.kind.value = kind;
    form.id.value = item.id || '';
    for (const key of ['name', 'notes', 'amount', 'frequency', 'cycle', 'currency_id', 'pay_day', 'end_date', 'payer_user_id', 'target_amount', 'saved_amount', 'target_date']) {
      if (item[key] !== undefined && item[key] !== null && form[key]) form[key].value = item[key];
    }
    dialog.querySelectorAll('.income-only, .expense-only, .goal-only').forEach(el => {
      el.hidden = !el.classList.contains(kind + '-only');
    });
    dialog.showModal();
  }
  function financeRequest(body) {
    return fetch('endpoints/finances/save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
      body: JSON.stringify(body),
    }).then(r => r.json()).then(data => data.success ? location.reload() : showErrorMessage(data.message))
      .catch(() => showErrorMessage(translate('unknown_error')));
  }
  function saveFinance(form) {
    financeRequest(Object.fromEntries(new FormData(form)));
    return false;
  }
  function deleteFinance(kind, id) {
    if (confirm(financeData.deleteConfirm)) financeRequest({ kind, id, action: 'delete' });
  }

  // Balance over the current month: start balance + money in and out per day.
  const chartElement = document.getElementById('cashflow-chart');
  if (chartElement && window.ApexCharts) {
    const series = start => {
      let balance = start;
      return financeData.cashflow.map((amount, index) => ({ x: index + 1, y: Math.round((balance += amount) * 100) / 100 }));
    };
    const chart = new ApexCharts(chartElement, {
      chart: { type: 'area', height: 260, toolbar: { show: false }, animations: { enabled: false } },
      series: [{ name: financeData.balanceLabel, data: series(0) }],
      dataLabels: { enabled: false },
      stroke: { curve: 'stepline', width: 2 },
      xaxis: { type: 'numeric', tickAmount: 10, labels: { formatter: value => Math.round(value) + '.' } },
      yaxis: { labels: { formatter: money } },
      annotations: { yaxis: [{ y: 0, borderColor: '#d9534f' }] },
      tooltip: { y: { formatter: money } },
    });
    chart.render();
    const startInput = document.getElementById('cashflow-start');
    const update = () => {
      const data = series(parseFloat(startInput.value) || 0);
      chart.updateSeries([{ data }]);
      const lowest = data.reduce((min, point) => point.y < min.y ? point : min, data[0]);
      const lowestElement = document.getElementById('cashflow-lowest');
      lowestElement.textContent = financeData.lowestText.replace('%s', money(lowest.y)).replace('%d', lowest.x);
      lowestElement.className = lowest.y < 0 ? 'negative' : '';
    };
    startInput.addEventListener('input', update);
    update();
  }

  // What-if: changes per month, nothing is saved.
  document.querySelectorAll('[data-what]').forEach(input => input.addEventListener('input', () => {
    const value = key => parseFloat(document.querySelector(`[data-what="${key}"]`).value) || 0;
    const delta = value('income') - value('loan') - value('costs') - value('savings');
    const left = financeData.left + delta;
    const leftElement = document.getElementById('what-if-left');
    leftElement.textContent = money(left);
    leftElement.className = left < 0 ? 'negative' : '';
    document.getElementById('what-if-delta').textContent = delta ? `(${delta > 0 ? '+' : ''}${money(delta)})` : '';
  }));
</script>

<?php require_once 'includes/footer.php'; ?>
