<?php
// Task inbox: all open items in one list ("done" / "later"), and which of them also come as a message.
require_once 'includes/header.php';
require_once 'includes/task_inbox.php';

$today = date('Y-m-d');
$tasks = openTasks($db, (int) $userId, $i18n, $today);
$readOnlyView = !empty($isReadOnlyAccount);
?>

<section class="contain">
  <h2><i class="fa-solid fa-inbox"></i> <?= translate('task_inbox', $i18n) ?> (<?= count($tasks) ?>)</h2>
  <p class="settings-notes"><?= translate('task_inbox_info', $i18n) ?></p>

  <?php if (!$tasks): ?>
    <p class="dashboard-empty"><i class="fa-solid fa-circle-check"></i> <?= translate('task_inbox_empty', $i18n) ?></p>
  <?php else: ?>
    <ul class="annual-review-list task-list">
      <?php foreach ($tasks as $task): ?>
        <li class="annual-review-item <?= $task['date'] < $today ? 'overdue' : '' ?>" data-key="<?= htmlspecialchars($task['key']) ?>">
          <div class="annual-review-info">
            <a href="<?= htmlspecialchars($task['link']) ?>"><i class="fa-solid <?= $task['icon'] ?>" aria-hidden="true"></i> <strong><?= htmlspecialchars($task['text']) ?></strong></a>
            <span><?= date('d.m.Y', strtotime($task['date'])) ?></span>
          </div>
          <?php if (!$readOnlyView): ?>
            <div class="annual-review-choices">
              <button type="button" class="annual-review-choice keep" data-status="done"><i class="fa-solid fa-check" aria-hidden="true"></i> <?= translate('task_done', $i18n) ?></button>
              <button type="button" class="annual-review-choice compare" data-status="later"><i class="fa-solid fa-clock" aria-hidden="true"></i> <?= translate('task_later', $i18n) ?></button>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if (!$readOnlyView): ?>
    <h3 class="task-prefs-title"><i class="fa-solid fa-bell"></i> <?= translate('notification_centre', $i18n) ?></h3>
    <p class="settings-notes"><?= translate('notification_centre_info', $i18n) ?></p>
    <div class="task-prefs">
      <?php foreach (NOTIFICATION_KINDS as $kind => $icon): ?>
        <label class="task-pref">
          <input type="checkbox" data-kind="<?= $kind ?>" <?= notificationEnabled($db, (int) $userId, $kind) ? 'checked' : '' ?>>
          <i class="fa-solid <?= $icon ?>" aria-hidden="true"></i> <?= translate('notify_kind_' . $kind, $i18n) ?>
        </label>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<script>
  function taskRequest(body) {
    return fetch('endpoints/tasks/state.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
      body: JSON.stringify(body),
    }).then((r) => r.json());
  }
  document.querySelectorAll('.task-list .annual-review-item').forEach((item) => {
    item.addEventListener('click', (event) => {
      const button = event.target.closest('[data-status]');
      if (!button) return;
      taskRequest({ action: 'task', key: item.dataset.key, status: button.dataset.status }).then((data) => {
        if (!data.success) { showErrorMessage(data.message || translate('unknown_error')); return; }
        item.remove(); // gone until it is due again ("later": in 7 days)
      }).catch(() => showErrorMessage(translate('unknown_error')));
    });
  });
  document.querySelectorAll('.task-prefs input').forEach((box) => {
    box.addEventListener('change', () => {
      taskRequest({ action: 'pref', kind: box.dataset.kind, enabled: box.checked ? 1 : 0 }).then((data) => {
        data.success ? showSuccessMessage(data.message) : showErrorMessage(data.message || translate('unknown_error'));
      }).catch(() => showErrorMessage(translate('unknown_error')));
    });
  });
</script>

<?php require_once 'includes/footer.php'; ?>
