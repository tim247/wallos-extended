<?php

require_once 'includes/header.php';
require_once 'includes/household_costs.php';
require_once 'includes/bank_import.php';

$mainCurrencyCode = $currencies[$main_currency]['code'] ?? 'EUR';
$fmt = fn($amount) => CurrencyFormatter::format($amount, $mainCurrencyCode);
$typeInfo = [
  'subscription' => ['fa-credit-card', 'subscriptions.php', 'subscriptions'],
  'contract' => ['fa-file-signature', 'contracts.php', 'contracts'],
  'insurance' => ['fa-shield-halved', 'insurance.php', 'insurances'],
  'loan' => ['fa-landmark', 'financing.php', 'financing'],
  'meter' => ['fa-gauge', 'consumption.php', 'consumption'],
];

$result = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $file = $_FILES['statement'] ?? null;
  if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $error = translate('error', $i18n);
  } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024) {
    $error = translate('file_upload_failed', $i18n);
  } else {
    $bookings = bankParseCsv((string) file_get_contents($file['tmp_name']));
    if ($bookings === []) {
      $error = translate('bank_import_unreadable', $i18n);
    } else {
      $result = bankCompare($bookings, householdCostItems($db, $userId));
      $result['count'] = count($bookings);
    }
  }
}
?>

<section class="contain bank-import">
  <div class="split-header">
    <h2><?= translate('bank_import', $i18n) ?></h2>
  </div>
  <p class="yearly-intro"><?= translate('bank_import_intro', $i18n) ?></p>

  <form method="post" enctype="multipart/form-data" class="form-group-inline">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
    <input type="file" name="statement" accept=".csv,.txt" required>
    <input type="submit" class="button thin" value="<?= translate('bank_import_compare', $i18n) ?>">
  </form>
  <p class="settings-notes"><i class="fa-solid fa-lock"></i> <?= translate('bank_import_privacy', $i18n) ?></p>

  <?php if ($error): ?>
    <p class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></p>
  <?php endif; ?>

  <?php if ($result): ?>
    <p><?= sprintf(translate('bank_import_summary', $i18n), $result['count'], htmlspecialchars($result['from']), htmlspecialchars($result['to'])) ?></p>

    <h3><i class="fa-solid fa-circle-check"></i> <?= translate('bank_import_matched', $i18n) ?> (<?= count($result['matched']) ?>)</h3>
    <table class="bank-table">
      <tr><th></th><th><?= translate('name', $i18n) ?></th><th><?= translate('bank_import_expected', $i18n) ?></th><th><?= translate('bank_import_booked', $i18n) ?></th><th><?= translate('date', $i18n) ?></th></tr>
      <?php foreach ($result['matched'] as $m): [$icon, $link] = $typeInfo[$m['item']['type']]; ?>
        <tr class="<?= $m['changed'] ? 'warning' : '' ?>">
          <td><i class="fa-solid <?= $icon ?>"></i></td>
          <td><a href="<?= $link ?>"><?= htmlspecialchars($m['item']['name']) ?></a> <small>(<?= $m['count'] ?>×)</small></td>
          <td><?= $fmt($m['item']['amount']) ?></td>
          <td><?= $fmt($m['booked']) ?>
            <?php if ($m['changed']): ?><br><small><i class="fa-solid fa-triangle-exclamation"></i> <?= translate('bank_import_changed', $i18n) ?></small>
              <?php if (in_array($m['item']['type'], ['subscription', 'contract', 'insurance'], true)): ?>
                <br><button type="button" class="button tiny" onclick="adoptPrice(this, '<?= $m['item']['type'] ?>', <?= $m['item']['id'] ?>, <?= $m['booked'] ?>)"><?= translate('bank_import_adopt', $i18n) ?></button>
              <?php endif; ?>
            <?php endif; ?></td>
          <td><?= htmlspecialchars($m['date']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <h3><i class="fa-solid fa-magnifying-glass"></i> <?= translate('bank_import_untracked', $i18n) ?> (<?= count($result['untracked']) ?>)</h3>
    <p class="settings-notes"><?= translate('bank_import_untracked_info', $i18n) ?></p>
    <table class="bank-table">
      <tr><th><?= translate('bank_import_payee', $i18n) ?></th><th><?= translate('price', $i18n) ?></th><th><?= translate('bank_import_times', $i18n) ?></th><th><?= translate('date', $i18n) ?></th></tr>
      <?php foreach ($result['untracked'] as $u): ?>
        <tr>
          <td><?= htmlspecialchars($u['payee']) ?><br><small><?= htmlspecialchars(mb_substr($u['text'], 0, 80)) ?></small></td>
          <td><?= $fmt($u['amount']) ?></td>
          <td><?= $u['count'] ?>×</td>
          <td><?= htmlspecialchars($u['last']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <h3><i class="fa-solid fa-circle-question"></i> <?= translate('bank_import_missing', $i18n) ?> (<?= count($result['missing']) ?>)</h3>
    <p class="settings-notes"><?= translate('bank_import_missing_info', $i18n) ?></p>
    <table class="bank-table">
      <?php foreach ($result['missing'] as $item): [$icon, $link] = $typeInfo[$item['type']]; ?>
        <tr>
          <td><i class="fa-solid <?= $icon ?>"></i></td>
          <td><a href="<?= $link ?>"><?= htmlspecialchars($item['name']) ?></a></td>
          <td><?= $fmt($item['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</section>

<script>
  function adoptPrice(button, type, id, booked) {
    button.disabled = true;
    const body = new FormData();
    body.append('type', type);
    body.append('id', id);
    body.append('booked', booked);
    fetch('endpoints/bank/setprice.php', { method: 'POST', headers: { 'X-CSRF-Token': window.csrfToken }, body })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showSuccessMessage(data.message);
          button.closest('tr').classList.remove('warning');
          button.remove();
        } else {
          showErrorMessage(data.message);
          button.disabled = false;
        }
      })
      .catch(() => { showErrorMessage(translate('unknown_error')); button.disabled = false; });
  }
</script>

<?php require_once 'includes/footer.php'; ?>
