<?php
// Deadlines & maintenance: ID card, passport, driving licence, vaccinations, TÜV, services,
// heating, filters ... Everything that recurs without a monthly debit.
require_once 'includes/header.php';
require_once 'includes/life_admin.php';

$readOnlyView = !empty($isReadOnlyAccount);
$today = date('Y-m-d');
$stmt = $db->prepare('SELECT * FROM reminders WHERE user_id = :userId ORDER BY due_date IS NULL, due_date');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$groups = ['document' => [], 'maintenance' => []];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $groups[$row['kind'] === 'document' ? 'document' : 'maintenance'][] = $row;
}
$status = function (array $row) use ($today): array {
  if (empty($row['due_date'])) {
    return ['', ''];
  }
  $days = (int) floor((strtotime($row['due_date']) - strtotime($today)) / 86400);
  if ($days < 0) {
    return ['overdue', sprintf(translate('reminder_overdue', $GLOBALS['i18n']), -$days)];
  }
  return [$days <= (int) $row['notify_days_before'] ? 'soon' : 'ok', sprintf(translate('reminder_in_days', $GLOBALS['i18n']), $days)];
};
?>

<section class="contain life">
  <h2><?= translate('reminders', $i18n) ?></h2>
  <p class="settings-notes"><?= translate('reminders_intro', $i18n) ?></p>

  <?php foreach ($groups as $kind => $rows): ?>
    <h3><i class="fa-solid <?= $kind === 'document' ? 'fa-id-card' : 'fa-screwdriver-wrench' ?>"></i> <?= translate('reminders_' . $kind, $i18n) ?></h3>
    <?php if (!$rows): ?><p class="settings-notes"><?= translate('reminders_empty_' . $kind, $i18n) ?></p><?php endif; ?>
    <table class="bank-table">
      <?php foreach ($rows as $row): [$class, $label] = $status($row); ?>
        <tr class="<?= $class === 'overdue' ? 'warning' : '' ?>">
          <td><i class="fa-solid <?= REMINDER_CATEGORIES[$row['category']][1] ?? 'fa-calendar-check' ?>"></i></td>
          <td><strong><?= htmlspecialchars($row['name']) ?></strong><?= $row['holder'] !== '' ? ' <small>· ' . htmlspecialchars($row['holder']) . '</small>' : '' ?>
            <?= $row['notes'] !== '' ? '<br><small>' . htmlspecialchars($row['notes']) . '</small>' : '' ?></td>
          <td><?= $row['due_date'] ? date('d.m.Y', strtotime($row['due_date'])) : '–' ?>
            <?= $label ? '<br><small class="reminder-' . $class . '">' . $label . '</small>' : '' ?></td>
          <td><small><?= (int) $row['interval_months'] > 0 ? sprintf(translate('reminder_every_months', $i18n), (int) $row['interval_months']) : translate('reminder_one_off', $i18n) ?>
            <?= $row['last_done'] ? '<br>' . translate('reminder_last_done', $i18n) . ' ' . date('d.m.Y', strtotime($row['last_done'])) : '' ?></small></td>
          <?php if (!$readOnlyView): ?>
            <td class="no-print">
              <button class="button tiny" onclick="lifeDone(<?= (int) $row['id'] ?>)" title="<?= translate('reminder_done_hint', $i18n) ?>"><i class="fa-solid fa-check"></i> <?= translate('reminder_done_button', $i18n) ?></button>
              <button class="image-button" onclick="lifeEdit(JSON.parse(this.dataset.row))" data-row="<?= htmlspecialchars(json_encode($row, JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) ?>"><i class="fa-solid fa-pen"></i></button>
              <button class="image-button" onclick="lifeDelete('reminder', <?= (int) $row['id'] ?>, <?= htmlspecialchars(json_encode(translate('finance_delete_confirm', $i18n)), ENT_QUOTES) ?>)"><i class="fa-solid fa-trash-can"></i></button>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>

  <?php if (!$readOnlyView): ?>
    <div class="buttons no-print">
      <button class="button thin" onclick="lifeEdit({category: 'id_card', interval_months: 120, notify_days_before: 90})"><i class="fa-solid fa-plus"></i> <?= translate('reminder_add_document', $i18n) ?></button>
      <button class="button thin secondary-button" onclick="lifeEdit({category: 'tuev', interval_months: 24, notify_days_before: 30})"><i class="fa-solid fa-plus"></i> <?= translate('reminder_add_maintenance', $i18n) ?></button>
    </div>

    <dialog id="life-dialog" class="finance-dialog">
      <form method="dialog" onsubmit="return lifeSave(this)">
        <input type="hidden" name="kind" value="reminder"><input type="hidden" name="id">
        <label><?= translate('category', $i18n) ?>
          <select name="category" onchange="this.form.interval_months.value = this.selectedOptions[0].dataset.interval">
            <?php foreach (REMINDER_CATEGORIES as $key => [$kind, $icon, $interval]): ?>
              <option value="<?= $key ?>" data-interval="<?= $interval ?>"><?= translate('reminder_cat_' . $key, $i18n) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label><?= translate('name', $i18n) ?><input type="text" name="name" required maxlength="200" placeholder="<?= translate('reminder_name_placeholder', $i18n) ?>"></label>
        <label><?= translate('reminder_holder', $i18n) ?><input type="text" name="holder" maxlength="200"></label>
        <label><?= translate('reminder_due', $i18n) ?><input type="date" name="due_date"></label>
        <label><?= translate('reminder_interval', $i18n) ?><input type="number" name="interval_months" min="0" max="600"></label>
        <label><?= translate('notify_me', $i18n) ?> (<?= translate('reminder_days_before', $i18n) ?>)<input type="number" name="notify_days_before" min="0" max="730"></label>
        <label><?= translate('notes', $i18n) ?><input type="text" name="notes" maxlength="1000"></label>
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
