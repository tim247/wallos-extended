<?php
// Children: one card per household member marked as a child - Kindergeld, maintenance and
// childcare per month, the U check-ups and the ID documents from Deadlines & maintenance.
// What the child is insured by or what is paid for it shows in the person view.
require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/life_admin.php';
require_once 'includes/household_extras.php';

$readOnlyView = !empty($isReadOnlyAccount);
$mainCurrencyCode = $currencies[$main_currency]['code'] ?? 'EUR';
$fmt = fn($amount) => CurrencyFormatter::format($amount, $mainCurrencyCode);
$today = date('Y-m-d');
$stmt = $db->prepare('SELECT * FROM children WHERE user_id = :userId');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$children = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  if (isset($members[$row['household_id']])) {
    $children[] = $row + ['name' => $members[$row['household_id']]['name']];
  }
}
usort($children, fn($a, $b) => strcmp((string) $b['birthdate'], (string) $a['birthdate']));
$stmt = $db->prepare("SELECT * FROM reminders WHERE user_id = :userId AND kind = 'document' ORDER BY due_date");
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$documents = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $documents[mb_strtolower(trim(html_entity_decode($row['holder'])))][] = $row;
}
$free = array_diff_key($members, array_flip(array_column($children, 'household_id')));
$stateClass = ['done' => 'ok', 'due' => 'warning', 'overdue' => 'danger', 'upcoming' => '', 'past' => ''];
$deleteQuestion = htmlspecialchars(json_encode(translate('finance_delete_confirm', $i18n)), ENT_QUOTES);
?>

<section class="contain life">
  <h2><?= translate('children', $i18n) ?></h2>
  <p class="settings-notes"><?= translate('children_intro', $i18n) ?></p>
  <?php if (!$children): ?><p class="settings-notes"><?= translate('children_empty', $i18n) ?></p><?php endif; ?>

  <?php foreach ($children as $child):
    $exams = childExams($child['birthdate'], (string) $child['exams_done'], $today);
    $docs = $documents[mb_strtolower(trim(html_entity_decode($child['name'])))] ?? []; ?>
    <h3><i class="fa-solid fa-child"></i> <?= $child['name'] ?>
      <?= $child['birthdate'] ? '<small>· ' . sprintf(translate('child_age', $i18n), childAge($child['birthdate'], $today)) . '</small>' : '' ?>
      <?php if (!$readOnlyView): ?>
        <span class="no-print">
          <button class="image-button" onclick="lifeEdit(JSON.parse(this.dataset.row))" data-row="<?= htmlspecialchars(json_encode($child, JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES) ?>"><i class="fa-solid fa-pen"></i></button>
          <button class="image-button" onclick="lifeDelete('child', <?= (int) $child['id'] ?>, <?= $deleteQuestion ?>)"><i class="fa-solid fa-trash-can"></i></button>
        </span>
      <?php endif; ?>
    </h3>
    <div class="statistics dashboard-tiles">
      <div class="statistic"><span><?= $fmt((float) $child['child_benefit']) ?></span><div class="title"><?= translate('child_benefit', $i18n) ?></div></div>
      <div class="statistic"><span><?= $fmt((float) $child['maintenance']) ?></span><div class="title"><?= translate('child_maintenance', $i18n) ?></div></div>
      <div class="statistic"><span><?= $fmt((float) $child['care_costs']) ?></span><div class="title"><?= translate('child_care_costs', $i18n) ?></div></div>
      <div class="statistic"><span><?= $fmt((float) $child['savings_rate']) ?></span><div class="title"><?= translate('child_savings_rate', $i18n) ?></div></div>
      <div class="statistic highlight"><span><?= $fmt($child['child_benefit'] + $child['maintenance'] - $child['care_costs'] - $child['savings_rate']) ?></span><div class="title"><?= translate('child_balance', $i18n) ?></div></div>
    </div>
    <p><a href="person.php?member=<?= (int) $child['household_id'] ?>"><i class="fa-solid fa-users"></i> <?= translate('child_person_view', $i18n) ?></a></p>

    <?php if ($exams): ?>
      <details <?= array_intersect(['due', 'overdue'], array_column($exams, 'state')) ? 'open' : '' ?>>
        <summary><i class="fa-solid fa-stethoscope"></i> <?= translate('child_exams', $i18n) ?></summary>
        <table class="bank-table">
          <?php foreach ($exams as $code => $exam): ?>
            <tr>
              <td><strong><?= $code ?></strong></td>
              <td><?= date('d.m.Y', strtotime($exam['from'])) ?> – <?= date('d.m.Y', strtotime($exam['to'])) ?></td>
              <td><span class="deadline-badge <?= $stateClass[$exam['state']] ?>"><?= translate('child_exam_' . $exam['state'], $i18n) ?></span></td>
              <?php if (!$readOnlyView): ?>
                <td class="no-print"><button class="image-button" title="<?= translate('child_exam_toggle', $i18n) ?>" onclick="lifeRequest({kind: 'child', action: 'exam', id: <?= (int) $child['id'] ?>, exam: '<?= $code ?>'})">
                  <i class="fa-<?= $exam['state'] === 'done' ? 'solid fa-square-check' : 'regular fa-square' ?>"></i></button></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </table>
      </details>
    <?php endif; ?>

    <table class="bank-table">
      <?php foreach ($docs as $doc): $days = $doc['due_date'] ? (int) floor((strtotime($doc['due_date']) - strtotime($today)) / 86400) : null; ?>
        <tr class="<?= $days !== null && $days < 0 ? 'warning' : '' ?>"><td><i class="fa-solid <?= REMINDER_CATEGORIES[$doc['category']][1] ?? 'fa-id-card' ?>"></i> <?= translate('reminder_cat_' . $doc['category'], $i18n) ?></td>
          <td><a href="reminders.php"><?= $doc['due_date'] ? date('d.m.Y', strtotime($doc['due_date'])) : '–' ?></a></td></tr>
      <?php endforeach; ?>
    </table>
    <?php if (!$docs): ?><p class="settings-notes"><?= sprintf(translate('child_no_documents', $i18n), $child['name']) ?></p><?php endif; ?>
    <?php if ($child['notes'] !== ''): ?><p class="settings-notes"><?= htmlspecialchars($child['notes']) ?></p><?php endif; ?>
  <?php endforeach; ?>

  <?php if (!$readOnlyView): ?>
    <div class="buttons no-print">
      <?php if ($free): ?><button class="button thin" onclick="lifeEdit({})"><i class="fa-solid fa-plus"></i> <?= translate('child_add', $i18n) ?></button><?php endif; ?>
      <a class="button secondary-button thin" href="settings.php"><i class="fa-solid fa-user-plus"></i> <?= translate('child_add_member', $i18n) ?></a>
    </div>

    <dialog id="life-dialog" class="finance-dialog">
      <form method="dialog" onsubmit="return lifeSave(this)">
        <input type="hidden" name="kind" value="child"><input type="hidden" name="id">
        <label><?= translate('child_member', $i18n) ?>
          <select name="household_id" required>
            <?php foreach ($members as $memberId => $member): ?><option value="<?= (int) $memberId ?>"><?= $member['name'] ?></option><?php endforeach; ?>
          </select></label>
        <label><?= translate('child_birthdate', $i18n) ?><input type="date" name="birthdate"></label>
        <label><?= translate('child_benefit', $i18n) ?><span class="input-unit" data-unit="<?= htmlspecialchars($mainCurrencyCode) ?> / <?= translate('month', $i18n) ?>"><input type="number" name="child_benefit" step="0.01" min="0"></span></label>
        <label><?= translate('child_maintenance', $i18n) ?><span class="input-unit" data-unit="<?= htmlspecialchars($mainCurrencyCode) ?> / <?= translate('month', $i18n) ?>"><input type="number" name="maintenance" step="0.01" min="0"></span></label>
        <label><?= translate('child_care_costs', $i18n) ?><span class="input-unit" data-unit="<?= htmlspecialchars($mainCurrencyCode) ?> / <?= translate('month', $i18n) ?>"><input type="number" name="care_costs" step="0.01" min="0"></span></label>
        <label><?= translate('child_savings_rate', $i18n) ?><span class="input-unit" data-unit="<?= htmlspecialchars($mainCurrencyCode) ?> / <?= translate('month', $i18n) ?>"><input type="number" name="savings_rate" step="0.01" min="0"></span></label>
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
