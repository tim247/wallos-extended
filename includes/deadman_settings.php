<?php
// Inactivity guard settings on emergency.php. Hidden for read-only accounts.
if (!empty($isReadOnlyAccount)) {
    return;
}
require_once __DIR__ . '/deadman.php';
require_once __DIR__ . '/instance_config.php';
$deadman = deadmanSettings($db, (int) $userId);
$deadmanLastSeen = $db->querySingle('SELECT last_seen FROM user WHERE id = ' . (int) $userId);
$deadmanLeft = deadmanDaysLeft($deadman, $deadmanLastSeen, date('Y-m-d'));
$deadmanAdmin = wallos_get_admin_settings($db);
$deadmanMailReady = !empty($deadmanAdmin['smtp_address']) || (bool) $db->querySingle("SELECT COUNT(*) FROM email_notifications WHERE user_id = " . (int) $userId . " AND smtp_address != ''");
?>
<details class="no-print deadman" <?= !empty($deadman['enabled']) ? '' : '' ?>>
  <summary><i class="fa-solid fa-heart-pulse"></i> <?= translate('deadman', $i18n) ?>
    <?php if (!empty($deadman['enabled'])): ?>
      <small class="deadman-state">· <?= sprintf(translate('deadman_armed', $i18n), $deadmanLeft ?? (int) $deadman['days']) ?></small>
    <?php endif; ?>
  </summary>
  <p class="settings-notes"><?= translate('deadman_info', $i18n) ?></p>
  <?php if (empty($deadmanAdmin['server_url'])): ?>
    <p class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?= translate('deadman_no_server_url', $i18n) ?></p>
  <?php endif; ?>
  <?php if (!$deadmanMailReady): ?>
    <p class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?= translate('deadman_no_mail', $i18n) ?></p>
  <?php endif; ?>
  <form class="finance-dialog deadman-form" onsubmit="return saveDeadman(this)">
    <label class="inline"><input type="checkbox" name="enabled" <?= !empty($deadman['enabled']) ? 'checked' : '' ?>> <?= translate('deadman_enable', $i18n) ?></label>
    <label><?= translate('deadman_days', $i18n) ?>
      <select name="days">
        <?php foreach ([30, 60, 90, 180, 365] as $option): ?>
          <option value="<?= $option ?>" <?= (int) $deadman['days'] === $option ? 'selected' : '' ?>><?= $option ?></option>
        <?php endforeach; ?>
      </select></label>
    <label><?= translate('deadman_trusted_name', $i18n) ?><input type="text" name="trusted_name" maxlength="100" value="<?= htmlspecialchars($deadman['trusted_name']) ?>"></label>
    <label><?= translate('deadman_trusted_email', $i18n) ?><input type="email" name="trusted_email" maxlength="200" value="<?= htmlspecialchars($deadman['trusted_email']) ?>"></label>
    <label><?= translate('deadman_message', $i18n) ?><textarea name="message" rows="4" maxlength="3000"><?= htmlspecialchars($deadman['message']) ?></textarea></label>
    <label><?= translate('deadman_code', $i18n) ?>
      <input type="password" name="access_code" minlength="8" maxlength="100" autocomplete="new-password"
        placeholder="<?= !empty($deadman['access_code_hash']) ? '••••••••  (' . translate('deadman_code_keep', $i18n) . ')' : '' ?>"></label>
    <p class="settings-notes"><?= translate('deadman_code_info', $i18n) ?></p>
    <fieldset class="deadman-second">
      <legend><?= translate('deadman_second_person', $i18n) ?></legend>
      <label><?= translate('deadman_trusted_name', $i18n) ?><input type="text" name="trusted2_name" maxlength="100" value="<?= htmlspecialchars($deadman['trusted2_name'] ?? '') ?>"></label>
      <label><?= translate('deadman_trusted_email', $i18n) ?><input type="email" name="trusted2_email" maxlength="200" value="<?= htmlspecialchars($deadman['trusted2_email'] ?? '') ?>"></label>
      <label><?= translate('deadman_scope', $i18n) ?>
        <select name="trusted2_scope">
          <option value="limited" <?= ($deadman['trusted2_scope'] ?? 'limited') !== 'all' ? 'selected' : '' ?>><?= translate('deadman_scope_limited', $i18n) ?></option>
          <option value="all" <?= ($deadman['trusted2_scope'] ?? '') === 'all' ? 'selected' : '' ?>><?= translate('deadman_scope_all', $i18n) ?></option>
        </select></label>
      <label><?= translate('deadman_code', $i18n) ?>
        <input type="password" name="access_code2" minlength="8" maxlength="100" autocomplete="new-password"
          placeholder="<?= !empty($deadman['access_code2_hash']) ? '••••••••  (' . translate('deadman_code_keep', $i18n) . ')' : '' ?>"></label>
      <p class="settings-notes"><?= translate('deadman_second_info', $i18n) ?></p>
    </fieldset>
    <div class="buttons"><input type="submit" class="thin" value="<?= translate('save', $i18n) ?>"></div>
  </form>
</details>
<script>
  function saveDeadman(form) {
    const body = Object.fromEntries(new FormData(form));
    body.enabled = form.enabled.checked ? 1 : 0;
    fetch('endpoints/life/deadman.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
      body: JSON.stringify(body),
    }).then(r => r.json()).then(data => data.success ? location.reload() : showErrorMessage(data.message))
      .catch(() => showErrorMessage(translate('unknown_error')));
    return false;
  }
</script>
