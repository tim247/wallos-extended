<?php
// Change log: what was added, changed or deleted (filled by SQLite triggers, migrations/000064.php).
require_once 'includes/header.php';

$icons = ['subscription' => 'fa-credit-card', 'contract' => 'fa-file-signature', 'insurance' => 'fa-shield-halved',
    'loan' => 'fa-landmark', 'meter' => 'fa-gauge'];
$stmt = $db->prepare('SELECT * FROM audit_log WHERE user_id = :userId ORDER BY id DESC LIMIT 300');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
?>

<section class="contain">
  <h2><?= translate('change_log', $i18n) ?></h2>
  <p class="settings-notes"><?= translate('change_log_info', $i18n) ?></p>
  <table class="bank-table">
    <tr><th><?= translate('date', $i18n) ?></th><th></th><th><?= translate('name', $i18n) ?></th><th><?= translate('change_log_action', $i18n) ?></th></tr>
    <?php while ($row = $result->fetchArray(SQLITE3_ASSOC)): ?>
      <tr>
        <td><?= date('d.m.Y H:i', strtotime($row['changed_at'])) ?></td>
        <td><i class="fa-solid <?= $icons[$row['item_type']] ?? 'fa-circle' ?>"></i></td>
        <td><?= htmlspecialchars((string) $row['name']) ?></td>
        <td><?= translate('change_log_' . $row['action'], $i18n) ?>
          <?php if ($row['action'] === 'edit' && $row['detail']): ?><br><small><?= htmlspecialchars(rtrim($row['detail'], '; ')) ?></small><?php endif; ?></td>
      </tr>
    <?php endwhile; ?>
  </table>
</section>

<?php require_once 'includes/footer.php'; ?>
