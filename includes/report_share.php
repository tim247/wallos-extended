<?php
// Read-only link to the cost report (partner, tax advisor). Shown below the report.
$stmt = $db->prepare('SELECT share_token FROM user WHERE id = :userId');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$shareToken = (string) ($stmt->execute()->fetchArray(SQLITE3_ASSOC)['share_token'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$shareUrl = $shareToken !== '' ? $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/share.php?token=' . $shareToken : '';
?>
<section class="contain no-print">
  <h3><i class="fa-solid fa-share-nodes"></i> <?= translate('share_link', $i18n) ?></h3>
  <p class="settings-notes"><?= translate('share_link_info', $i18n) ?></p>
  <?php if ($shareUrl): ?>
    <div class="form-group-inline">
      <input type="text" readonly value="<?= htmlspecialchars($shareUrl) ?>" onclick="this.select()">
      <button class="button thin secondary-button" onclick="setShareLink('revoke')"><?= translate('share_link_revoke', $i18n) ?></button>
    </div>
  <?php else: ?>
    <button class="button thin" onclick="setShareLink('create')"><?= translate('share_link_create', $i18n) ?></button>
  <?php endif; ?>
</section>
<script>
  function setShareLink(action) {
    fetch('endpoints/report/share.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.csrfToken },
      body: new URLSearchParams({ action }),
    })
      .then(response => response.json())
      .then(data => data.success ? location.reload() : showErrorMessage(data.message))
      .catch(() => showErrorMessage(translate('unknown_error')));
  }
</script>
