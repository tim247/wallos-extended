<?php
// Household inventory: what we own, where it is, what it cost and what it is worth now.
// The replacement value (Neuwert) is what the contents insurance (Hausrat) has to cover.
require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/life_admin.php';

$readOnlyView = !empty($isReadOnlyAccount);
$mainCurrencyCode = $currencies[$main_currency]['code'] ?? 'EUR';
$fmt = fn($amount) => CurrencyFormatter::format($amount, $mainCurrencyCode);
$stmt = $db->prepare('SELECT i.*, (SELECT COUNT(*) FROM item_files f WHERE f.user_id = i.user_id AND f.item_type = \'inventory\' AND f.item_id = i.id) AS files
    FROM inventory i WHERE i.user_id = :userId ORDER BY i.location COLLATE NOCASE, i.name COLLATE NOCASE');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$items = [];
$byLocation = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $row['value'] = inventoryCurrentValue((float) $row['price'], $row['purchase_date'], (int) $row['lifetime_years']);
  $items[] = $row;
  $location = $row['location'] !== '' ? $row['location'] : translate('inventory_no_location', $i18n);
  $byLocation[$location]['price'] = ($byLocation[$location]['price'] ?? 0) + $row['price'];
  $byLocation[$location]['value'] = ($byLocation[$location]['value'] ?? 0) + $row['value'];
  $byLocation[$location]['count'] = ($byLocation[$location]['count'] ?? 0) + 1;
}
$totalPrice = array_sum(array_column($items, 'price'));
$totalValue = array_sum(array_column($items, 'value'));

// Sum insured of the contents insurance, to compare with the replacement value.
$stmt = $db->prepare("SELECT SUM(coverage_amount) FROM insurances WHERE user_id = :userId AND archived = 0 AND insurance_type = 'household'");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$coverage = (float) ($stmt->execute()->fetchArray()[0] ?? 0);
$locations = array_values(array_unique(array_filter(array_column($items, 'location'))));
$categories = array_values(array_unique(array_filter(array_column($items, 'category'))));
?>

<section class="contain life">
  <h2><?= translate('inventory', $i18n) ?></h2>
  <p class="settings-notes"><?= translate('inventory_intro', $i18n) ?></p>

  <div class="statistics dashboard-tiles">
    <div class="statistic highlight"><span><?= $fmt($totalPrice) ?></span><div class="title"><?= translate('inventory_replacement_value', $i18n) ?></div></div>
    <div class="statistic"><span><?= $fmt($totalValue) ?></span><div class="title"><?= translate('inventory_current_value', $i18n) ?></div></div>
    <div class="statistic"><span><?= count($items) ?></span><div class="title"><?= translate('inventory_items', $i18n) ?></div></div>
    <?php if ($coverage > 0): ?>
      <div class="statistic <?= $totalPrice > $coverage ? 'negative' : '' ?>"><span><?= $fmt($coverage) ?></span><div class="title"><?= translate('inventory_coverage', $i18n) ?></div></div>
    <?php endif; ?>
  </div>
  <?php if ($coverage > 0 && $totalPrice > $coverage): ?>
    <p class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?= sprintf(translate('inventory_underinsured', $i18n), $fmt($totalPrice - $coverage)) ?></p>
  <?php endif; ?>

  <?php if (count($byLocation) > 1): ?>
    <h3><i class="fa-solid fa-location-dot"></i> <?= translate('inventory_by_location', $i18n) ?></h3>
    <table class="bank-table">
      <tr><th><?= translate('inventory_location', $i18n) ?></th><th><?= translate('inventory_items', $i18n) ?></th><th><?= translate('inventory_replacement_value', $i18n) ?></th><th><?= translate('inventory_current_value', $i18n) ?></th></tr>
      <?php foreach ($byLocation as $location => $sum): ?>
        <tr><td><?= htmlspecialchars($location) ?></td><td><?= $sum['count'] ?></td><td><?= $fmt($sum['price']) ?></td><td><?= $fmt($sum['value']) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <h3><i class="fa-solid fa-boxes-stacked"></i> <?= translate('inventory_items', $i18n) ?></h3>
  <?php if ($locations): ?>
    <div class="form-group-inline no-print">
      <select id="inventory-filter" onchange="document.querySelectorAll('[data-location]').forEach(r => r.hidden = this.value !== '' && r.dataset.location !== this.value)">
        <option value=""><?= translate('inventory_all_locations', $i18n) ?></option>
        <?php foreach ($locations as $location): ?><option><?= htmlspecialchars($location) ?></option><?php endforeach; ?>
      </select>
    </div>
  <?php endif; ?>
  <table class="bank-table">
    <tr><th><?= translate('name', $i18n) ?></th><th><?= translate('inventory_location', $i18n) ?></th><th><?= translate('inventory_purchased', $i18n) ?></th><th><?= translate('inventory_replacement_value', $i18n) ?></th><th><?= translate('inventory_current_value', $i18n) ?></th><?= $readOnlyView ? '' : '<th></th>' ?></tr>
    <?php foreach ($items as $item): ?>
      <tr data-location="<?= htmlspecialchars($item['location']) ?>">
        <td><strong><?= htmlspecialchars($item['name']) ?></strong>
          <?= $item['category'] !== '' ? '<br><small>' . htmlspecialchars($item['category']) . '</small>' : '' ?>
          <?= $item['serial_number'] !== '' ? '<br><small>' . translate('inventory_serial', $i18n) . ': ' . htmlspecialchars($item['serial_number']) . '</small>' : '' ?>
          <?php if (!empty($item['warranty_until'])): $warrantyLeft = (strtotime($item['warranty_until']) - strtotime(date('Y-m-d'))) / 86400; ?>
            <br><span class="deadline-badge <?= $warrantyLeft < 0 ? 'cancelled' : ($warrantyLeft <= 60 ? 'warning' : '') ?>"><i class="fa-solid fa-shield"></i> <?= sprintf(translate($warrantyLeft < 0 ? 'warranty_expired' : 'warranty_badge', $i18n), date('d.m.Y', strtotime($item['warranty_until']))) ?></span>
          <?php endif; ?>
          <?= $item['files'] ? ' <i class="fa-solid fa-paperclip" title="' . translate('item_files', $i18n) . '"></i>' : '' ?></td>
        <td><?= htmlspecialchars($item['location']) ?></td>
        <td><?= $item['purchase_date'] ? date('d.m.Y', strtotime($item['purchase_date'])) : '–' ?></td>
        <td><?= $fmt((float) $item['price']) ?></td>
        <td><?= $fmt($item['value']) ?><?= (int) $item['lifetime_years'] > 0 ? '<br><small>' . sprintf(translate('inventory_lifetime_short', $i18n), (int) $item['lifetime_years']) . '</small>' : '' ?></td>
        <?php if (!$readOnlyView): ?>
          <td class="no-print">
            <button class="image-button" onclick="lifeEdit(JSON.parse(this.dataset.row))" data-row="<?= htmlspecialchars(json_encode($item, JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) ?>"><i class="fa-solid fa-pen"></i></button>
            <button class="image-button" onclick="lifeDelete('inventory', <?= (int) $item['id'] ?>, <?= htmlspecialchars(json_encode(translate('finance_delete_confirm', $i18n)), ENT_QUOTES) ?>)"><i class="fa-solid fa-trash-can"></i></button>
          </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>

  <?php if (!$readOnlyView): ?>
    <div class="buttons no-print">
      <button class="button thin" onclick="lifeEdit({})"><i class="fa-solid fa-plus"></i> <?= translate('inventory_add', $i18n) ?></button>
    </div>

    <dialog id="life-dialog" class="finance-dialog">
      <form method="dialog" onsubmit="return lifeSave(this)">
        <input type="hidden" name="kind" value="inventory"><input type="hidden" name="id">
        <label><?= translate('name', $i18n) ?><input type="text" name="name" required maxlength="200"></label>
        <label><?= translate('category', $i18n) ?><input type="text" name="category" maxlength="100" list="inventory-categories" placeholder="<?= translate('inventory_category_placeholder', $i18n) ?>"></label>
        <label><?= translate('inventory_location', $i18n) ?><input type="text" name="location" maxlength="100" list="inventory-locations" placeholder="<?= translate('inventory_location_placeholder', $i18n) ?>"></label>
        <label><?= translate('inventory_purchase_date', $i18n) ?><input type="date" name="purchase_date"></label>
        <label><?= translate('inventory_price', $i18n) ?><input type="number" name="price" step="0.01" min="0"></label>
        <label><?= translate('inventory_lifetime', $i18n) ?><input type="number" name="lifetime_years" min="0" max="100"></label>
        <label><?= translate('inventory_serial', $i18n) ?><input type="text" name="serial_number" maxlength="100"></label>
        <label><?= translate('warranty_until', $i18n) ?><input type="date" name="warranty_until"></label>
        <label><?= translate('notes', $i18n) ?><input type="text" name="notes" maxlength="1000"></label>
        <?php $filesType = 'inventory'; include 'includes/files_panel.php'; ?>
        <div class="buttons">
          <button type="button" class="secondary-button thin" onclick="this.closest('dialog').close()"><?= translate('cancel', $i18n) ?></button>
          <input type="submit" class="thin" value="<?= translate('save', $i18n) ?>">
        </div>
      </form>
      <datalist id="inventory-locations"><?php foreach ($locations as $location): ?><option value="<?= htmlspecialchars($location) ?>"><?php endforeach; ?></datalist>
      <datalist id="inventory-categories"><?php foreach ($categories as $category): ?><option value="<?= htmlspecialchars($category) ?>"><?php endforeach; ?></datalist>
    </dialog>
  <?php endif; ?>
</section>

<script src="scripts/files.js?<?= $assetVersion ?>"></script>
<script src="scripts/life.js?<?= $assetVersion ?>"></script>
<?php require_once 'includes/footer.php'; ?>
