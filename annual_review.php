<?php
// Annual review: once a year go through every contract and insurance and decide
// "keep", "cancel" or "compare". At the end: what the decisions save per year.
require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/price_helpers.php';
require_once 'includes/cancellation_helpers.php';

$year = (int) date('Y');
$mainCurrencyCode = $currencies[$userData['main_currency']]['code'] ?? 'EUR';
$items = [];
foreach (['contract' => ['contracts', 'provider', 'contracts.php', 'fa-file-signature'], 'insurance' => ['insurances', 'insurer', 'insurance.php', 'fa-shield-halved']] as $type => [$table, $providerColumn, $link, $icon]) {
  $stmt = $db->prepare("SELECT * FROM $table WHERE user_id = :userId AND archived = 0 AND cancellation_status = 0 ORDER BY name COLLATE NOCASE");
  $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $result = $stmt->execute();
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $monthly = getPriceConverted(getPricePerMonth($row['cycle'], $row['frequency'], (float) $row['price']), $row['currency_id'], $db, $userId);
    $items[] = ['type' => $type, 'id' => (int) $row['id'], 'name' => $row['name'], 'provider' => $row[$providerColumn] ?? '',
      'monthly' => round($monthly, 2), 'deadline' => getNextCancellationDeadline($row)['deadline'] ?? null, 'link' => $link, 'icon' => $icon];
  }
}
$decisions = [];
$stmt = $db->prepare('SELECT item_type, item_id, decision FROM annual_review WHERE user_id = :userId AND year = :year');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':year', $year, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $decisions[$row['item_type'] . ':' . $row['item_id']] = $row['decision'];
}
$done = count(array_filter($items, fn($i) => isset($decisions[$i['type'] . ':' . $i['id']])));
$cancelYearly = array_sum(array_map(fn($i) => ($decisions[$i['type'] . ':' . $i['id']] ?? '') === 'cancel' ? $i['monthly'] * 12 : 0, $items));
$compareCount = count(array_filter($items, fn($i) => ($decisions[$i['type'] . ':' . $i['id']] ?? '') === 'compare'));
?>

<section class="contain">
  <h2><i class="fa-solid fa-clipboard-check"></i> <?= sprintf(translate('annual_review_title', $i18n), $year) ?></h2>
  <p class="settings-notes"><?= translate('annual_review_info', $i18n) ?></p>

  <div class="annual-review-summary" id="annual-review-summary" data-total="<?= count($items) ?>">
    <div><strong id="review-done"><?= $done ?></strong> / <?= count($items) ?><span><?= translate('annual_review_done', $i18n) ?></span></div>
    <div><strong id="review-saving"><?= CurrencyFormatter::format($cancelYearly, $mainCurrencyCode) ?></strong><span><?= translate('annual_review_saving', $i18n) ?></span></div>
    <div><strong id="review-compare"><?= $compareCount ?></strong><span><?= translate('annual_review_compare_count', $i18n) ?></span></div>
  </div>
  <div class="annual-review-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= count($items) ?>" aria-valuenow="<?= $done ?>">
    <span id="review-bar" style="width: <?= count($items) ? round($done / count($items) * 100) : 0 ?>%"></span>
  </div>

  <?php if (!$items): ?>
    <p class="dashboard-empty"><?= translate('annual_review_empty', $i18n) ?></p>
  <?php endif; ?>
  <ul class="annual-review-list">
    <?php foreach ($items as $item): $key = $item['type'] . ':' . $item['id']; $decision = $decisions[$key] ?? ''; ?>
      <li class="annual-review-item" data-type="<?= $item['type'] ?>" data-id="<?= $item['id'] ?>" data-monthly="<?= $item['monthly'] ?>" data-decision="<?= $decision ?>">
        <div class="annual-review-info">
          <a href="<?= $item['link'] ?>"><i class="fa-solid <?= $item['icon'] ?>" aria-hidden="true"></i> <strong><?= $item['name'] ?></strong></a>
          <span><?= $item['provider'] ?> · <?= CurrencyFormatter::format($item['monthly'], $mainCurrencyCode) ?> / <?= translate('month', $i18n) ?>
            <?= $item['deadline'] ? ' · ' . translate('cancellable_until', $i18n) . ' ' . date('d.m.Y', strtotime($item['deadline'])) : '' ?></span>
        </div>
        <div class="annual-review-choices" role="group" aria-label="<?= htmlspecialchars($item['name']) ?>">
          <?php foreach (['keep' => 'fa-check', 'compare' => 'fa-scale-balanced', 'cancel' => 'fa-ban'] as $choice => $choiceIcon): ?>
            <button type="button" class="annual-review-choice <?= $choice ?>" data-choice="<?= $choice ?>" aria-pressed="<?= $decision === $choice ? 'true' : 'false' ?>">
              <i class="fa-solid <?= $choiceIcon ?>" aria-hidden="true"></i> <?= translate('annual_review_' . $choice, $i18n) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>

<script>
  document.querySelectorAll('.annual-review-item').forEach((item) => {
    item.addEventListener('click', (event) => {
      const button = event.target.closest('.annual-review-choice');
      if (!button) return;
      fetch('endpoints/review/save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
        body: JSON.stringify({ type: item.dataset.type, id: Number(item.dataset.id), decision: button.dataset.choice }),
      })
        .then((r) => r.json())
        .then((data) => {
          if (!data.success) { showErrorMessage(data.message || translate('unknown_error')); return; }
          item.dataset.decision = button.dataset.choice;
          item.querySelectorAll('.annual-review-choice').forEach((b) => b.setAttribute('aria-pressed', b === button ? 'true' : 'false'));
          const all = [...document.querySelectorAll('.annual-review-item')];
          const done = all.filter((i) => i.dataset.decision).length;
          const saving = all.filter((i) => i.dataset.decision === 'cancel').reduce((sum, i) => sum + Number(i.dataset.monthly) * 12, 0);
          document.getElementById('review-done').textContent = done;
          document.getElementById('review-compare').textContent = all.filter((i) => i.dataset.decision === 'compare').length;
          document.getElementById('review-saving').textContent = new Intl.NumberFormat(navigator.language, { style: 'currency', currency: '<?= $mainCurrencyCode ?>' }).format(saving);
          document.getElementById('review-bar').style.width = Math.round(done / all.length * 100) + '%';
        })
        .catch(() => showErrorMessage(translate('unknown_error')));
    });
  });
</script>

<?php require_once 'includes/footer.php'; ?>
