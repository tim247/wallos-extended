<?php
// Vehicles: fuel log with consumption, linked motor insurance and leasing/finance contract,
// monthly costs and the deadlines (TÜV, service, oil, tyres) from Deadlines & maintenance.
require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/price_helpers.php';
require_once 'includes/life_admin.php';
require_once 'includes/household_extras.php';

$readOnlyView = !empty($isReadOnlyAccount);
$mainCurrencyCode = $currencies[$main_currency]['code'] ?? 'EUR';
$fmt = fn($amount) => CurrencyFormatter::format($amount, $mainCurrencyCode);
$today = date('Y-m-d');
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
$monthly = fn(array $row) => getPricePerMonth($row['cycle'], $row['frequency'], (float) $row['price']);
$insurances = array_column($rows("SELECT id, name, price, cycle, frequency, insurance_type FROM insurances WHERE user_id = :userId AND archived = 0 ORDER BY insurance_type != 'car', name"), null, 'id');
$contracts = array_column($rows("SELECT id, name, price, cycle, frequency, contract_type FROM contracts WHERE user_id = :userId AND archived = 0 ORDER BY contract_type != 'leasing', name"), null, 'id');
if (!$readOnlyView) {
  vehicleTaxApplyPending($db, (int) $userId, $today);
}
$vehicles = $rows('SELECT * FROM vehicles WHERE user_id = :userId ORDER BY name COLLATE NOCASE');
$fuelByVehicle = [];
foreach ($rows('SELECT * FROM vehicle_fuel WHERE user_id = :userId ORDER BY fuel_date DESC, id DESC') as $row) {
  $fuelByVehicle[(int) $row['vehicle_id']][] = $row;
}
$reminders = $rows("SELECT * FROM reminders WHERE user_id = :userId AND category IN ('" . implode("','", VEHICLE_REMINDER_CATEGORIES) . "') ORDER BY due_date");
$deleteQuestion = htmlspecialchars(json_encode(translate('finance_delete_confirm', $i18n)), ENT_QUOTES);
?>

<section class="contain life">
  <h2><?= translate('vehicles', $i18n) ?></h2>
  <p class="settings-notes"><?= translate('vehicles_intro', $i18n) ?></p>

  <?php if (!$vehicles): ?><p class="settings-notes"><?= translate('vehicles_empty', $i18n) ?></p><?php endif; ?>

  <?php foreach ($vehicles as $vehicle):
    $fuel = $fuelByVehicle[(int) $vehicle['id']] ?? [];
    $stats = vehicleFuelStats($fuel, $today);
    $insurance = $insurances[$vehicle['insurance_id']] ?? null;
    $contract = $contracts[$vehicle['contract_id']] ?? null;
    $tax = (float) $vehicle['tax_amount'];
    $taxDue = vehicleTaxNextDue($vehicle['tax_due'], $today);
    $total = $stats['monthly'] + ($insurance ? $monthly($insurance) : 0) + ($contract ? $monthly($contract) : 0) + $tax / 12;
    $keys = array_filter([mb_strtolower(trim($vehicle['name'])), mb_strtolower(trim($vehicle['plate']))]);
    $own = array_filter($reminders, fn($r) => in_array(mb_strtolower(trim(html_entity_decode($r['holder']))), $keys, true)); ?>
    <h3><i class="fa-solid fa-car"></i> <?= htmlspecialchars($vehicle['name']) ?>
      <?= $vehicle['plate'] !== '' ? '<small>· ' . htmlspecialchars($vehicle['plate']) . '</small>' : '' ?>
      <?php if (!$readOnlyView): ?>
        <span class="no-print">
          <button class="image-button" onclick="lifeEdit(JSON.parse(this.dataset.row)); priceHistoryLoad('vehicle_tax', <?= (int) $vehicle['id'] ?>)" data-row="<?= htmlspecialchars(json_encode($vehicle, JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) ?>"><i class="fa-solid fa-pen"></i></button>
          <button class="image-button" onclick="lifeDelete('vehicle', <?= (int) $vehicle['id'] ?>, <?= $deleteQuestion ?>)"><i class="fa-solid fa-trash-can"></i></button>
        </span>
      <?php endif; ?>
    </h3>
    <div class="statistics dashboard-tiles">
      <div class="statistic highlight"><span><?= $fmt($total) ?></span><div class="title"><?= translate('vehicle_cost_month', $i18n) ?></div></div>
      <div class="statistic"><span><?= $stats['mileage'] ? number_format($stats['mileage'], 0, ',', '.') . ' km' : '–' ?></span><div class="title"><?= translate('vehicle_mileage', $i18n) ?></div></div>
      <div class="statistic"><span><?= $stats['per100'] !== null ? number_format($stats['per100'], 1, ',', '.') : '–' ?></span><div class="title"><?= translate('vehicle_per100', $i18n) ?></div></div>
      <div class="statistic"><span><?= $stats['cost_per_km'] !== null ? $fmt($stats['cost_per_km']) : '–' ?></span><div class="title"><?= translate('vehicle_cost_per_km', $i18n) ?></div></div>
    </div>
    <table class="bank-table">
      <tr><td><i class="fa-solid fa-gas-pump"></i> <?= translate('vehicle_fuel_month', $i18n) ?></td><td><?= $fmt($stats['monthly']) ?></td></tr>
      <tr><td><i class="fa-solid fa-shield-halved"></i> <?= translate('vehicle_insurance', $i18n) ?></td>
        <td><?= $insurance ? '<a href="insurance.php">' . $insurance['name'] . '</a> · ' . $fmt($monthly($insurance)) : '–' ?></td></tr>
      <tr><td><i class="fa-solid fa-file-signature"></i> <?= translate('vehicle_contract', $i18n) ?></td>
        <td><?= $contract ? '<a href="contracts.php">' . $contract['name'] . '</a> · ' . $fmt($monthly($contract)) : '–' ?></td></tr>
      <tr class="<?= $taxDue && $taxDue <= date('Y-m-d', strtotime('+30 days')) ? 'warning' : '' ?>"><td><i class="fa-solid fa-landmark"></i> <?= translate('vehicle_tax', $i18n) ?></td>
        <td><?php if ($tax > 0): ?><?= $fmt($tax) ?> / <?= translate('year', $i18n) ?><?= $taxDue ? ' · ' . translate('vehicle_tax_due', $i18n) . ' ' . date('d.m.Y', strtotime($taxDue)) : '' ?>
          <?php if (!empty($vehicle['tax_pending_date'])): ?><br><small><?= sprintf(translate('price_from_date', $i18n), date('d.m.Y', strtotime($vehicle['tax_pending_date'])), $fmt((float) $vehicle['tax_pending_amount'])) ?></small><?php endif; ?>
        <?php else: ?>–<?php endif; ?></td></tr>
      <?php foreach ($own as $reminder):
        $days = $reminder['due_date'] ? (int) floor((strtotime($reminder['due_date']) - strtotime($today)) / 86400) : null; ?>
        <tr class="<?= $days !== null && $days < 0 ? 'warning' : '' ?>"><td><i class="fa-solid <?= REMINDER_CATEGORIES[$reminder['category']][1] ?? 'fa-calendar-check' ?>"></i> <?= translate('reminder_cat_' . $reminder['category'], $i18n) ?></td>
          <td><a href="reminders.php"><?= $reminder['due_date'] ? date('d.m.Y', strtotime($reminder['due_date'])) : '–' ?></a></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if (!$own): ?><p class="settings-notes"><?= sprintf(translate('vehicle_no_deadlines', $i18n), htmlspecialchars($vehicle['plate'] ?: $vehicle['name'])) ?></p><?php endif; ?>

    <details>
      <summary><?= translate('vehicle_fuel_log', $i18n) ?> (<?= count($fuel) ?>)</summary>
      <table class="bank-table">
        <tr><th><?= translate('date', $i18n) ?></th><th><?= translate('vehicle_liters', $i18n) ?></th><th><?= translate('vehicle_fuel_cost', $i18n) ?></th><th><?= translate('vehicle_mileage', $i18n) ?></th><?= $readOnlyView ? '' : '<th></th>' ?></tr>
        <?php foreach (array_slice($fuel, 0, 50) as $entry): ?>
          <tr><td><?= date('d.m.Y', strtotime($entry['fuel_date'])) ?></td><td><?= number_format((float) $entry['liters'], 2, ',', '.') ?></td>
            <td><?= $fmt((float) $entry['cost']) ?></td><td><?= $entry['mileage'] ? number_format((int) $entry['mileage'], 0, ',', '.') . ' km' : '–' ?></td>
            <?php if (!$readOnlyView): ?>
              <td class="no-print">
                <button class="image-button" onclick="lifeEdit(JSON.parse(this.dataset.row), 'fuel-dialog')" data-row="<?= htmlspecialchars(json_encode($entry), ENT_QUOTES) ?>"><i class="fa-solid fa-pen"></i></button>
                <button class="image-button" onclick="lifeDelete('fuel', <?= (int) $entry['id'] ?>, <?= $deleteQuestion ?>)"><i class="fa-solid fa-trash-can"></i></button>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </table>
    </details>
    <?php if (!$readOnlyView): ?>
      <div class="buttons no-print">
        <button class="button thin" onclick="lifeEdit({vehicle_id: <?= (int) $vehicle['id'] ?>, fuel_date: '<?= $today ?>'}, 'fuel-dialog')"><i class="fa-solid fa-gas-pump"></i> <?= translate('vehicle_add_fuel', $i18n) ?></button>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php if (!$readOnlyView): ?>
    <div class="buttons no-print">
      <button class="button thin" onclick="lifeEdit({}); priceHistoryLoad('vehicle_tax', 0)"><i class="fa-solid fa-plus"></i> <?= translate('vehicle_add', $i18n) ?></button>
      <a class="button secondary-button thin" href="reminders.php"><i class="fa-solid fa-calendar-check"></i> <?= translate('reminders', $i18n) ?></a>
    </div>

    <dialog id="life-dialog" class="finance-dialog">
      <form method="dialog" onsubmit="return lifeSave(this)">
        <input type="hidden" name="kind" value="vehicle"><input type="hidden" name="id">
        <label><?= translate('name', $i18n) ?><input type="text" name="name" required maxlength="200" placeholder="<?= translate('vehicle_name_placeholder', $i18n) ?>"></label>
        <label><?= translate('vehicle_plate', $i18n) ?><input type="text" name="plate" maxlength="20"></label>
        <label><?= translate('vehicle_first_registration', $i18n) ?><input type="date" name="first_registration"></label>
        <label><?= translate('vehicle_insurance', $i18n) ?>
          <select name="insurance_id"><option value="">–</option>
            <?php foreach ($insurances as $insurance): ?><option value="<?= (int) $insurance['id'] ?>"><?= $insurance['name'] ?></option><?php endforeach; ?>
          </select></label>
        <label><?= translate('vehicle_contract', $i18n) ?>
          <select name="contract_id"><option value="">–</option>
            <?php foreach ($contracts as $contract): ?><option value="<?= (int) $contract['id'] ?>"><?= $contract['name'] ?></option><?php endforeach; ?>
          </select></label>
        <label><?= translate('notes', $i18n) ?><input type="text" name="notes" maxlength="1000"></label>
        <h4><i class="fa-solid fa-landmark"></i> <?= translate('vehicle_tax', $i18n) ?></h4>
        <label><?= translate('vehicle_tax_amount', $i18n) ?><span class="input-unit" data-unit="<?= htmlspecialchars($mainCurrencyCode) ?> / <?= translate('year', $i18n) ?>"><input type="number" name="tax_amount" step="0.01" min="0"></span></label>
        <label><?= translate('vehicle_tax_next_debit', $i18n) ?><input type="date" name="tax_due"></label>
        <?php $priceHistoryType = 'vehicle_tax'; $priceHistoryField = '#life-dialog [name=tax_amount]'; require 'includes/price_history_panel.php'; ?>
        <div class="buttons">
          <button type="button" class="secondary-button thin" onclick="this.closest('dialog').close()"><?= translate('cancel', $i18n) ?></button>
          <input type="submit" class="thin" value="<?= translate('save', $i18n) ?>">
        </div>
      </form>
    </dialog>

    <dialog id="fuel-dialog" class="finance-dialog">
      <form method="dialog" onsubmit="return lifeSave(this)">
        <input type="hidden" name="kind" value="fuel"><input type="hidden" name="id"><input type="hidden" name="vehicle_id">
        <label><?= translate('date', $i18n) ?><input type="date" name="fuel_date" required></label>
        <label><?= translate('vehicle_liters', $i18n) ?><span class="input-unit" data-unit="l / kWh"><input type="number" name="liters" step="0.01" min="0"></span></label>
        <label><?= translate('vehicle_fuel_cost', $i18n) ?><span class="input-unit" data-unit="<?= htmlspecialchars($mainCurrencyCode) ?>"><input type="number" name="cost" step="0.01" min="0"></span></label>
        <label><?= translate('vehicle_mileage', $i18n) ?><span class="input-unit" data-unit="km"><input type="number" name="mileage" step="1" min="0"></span></label>
        <div class="buttons">
          <button type="button" class="secondary-button thin" onclick="this.closest('dialog').close()"><?= translate('cancel', $i18n) ?></button>
          <input type="submit" class="thin" value="<?= translate('save', $i18n) ?>">
        </div>
      </form>
    </dialog>
  <?php endif; ?>
</section>

<script src="scripts/life.js?<?= $assetVersion ?>"></script>
<?php require_once 'includes/footer.php'; ?>
