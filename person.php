<?php
// Per household member: everything that person pays or is insured by, with monthly totals.
// Useful when a household changes (separation, a child moving out).
require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/price_helpers.php';
require_once 'includes/insurance_insights.php';

$mainCurrencyCode = $currencies[$userData['main_currency']]['code'] ?? 'EUR';
$memberId = (int) ($_GET['member'] ?? array_key_first($members));
if (!isset($members[$memberId])) {
  $memberId = (int) array_key_first($members);
}
$monthly = fn(array $row) => getPriceConverted(getPricePerMonth($row['cycle'], $row['frequency'], (float) $row['price']), $row['currency_id'], $db, $userId);
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

$sections = [
  'subscriptions' => ['fa-credit-card', 'subscriptions.php', array_filter($rows('SELECT * FROM subscriptions WHERE user_id = :userId AND inactive = 0 AND archived = 0 ORDER BY name COLLATE NOCASE'),
    fn($r) => (int) $r['payer_user_id'] === $memberId && (int) $r['cycle'] !== 5)],
  'contracts' => ['fa-file-signature', 'contracts.php', array_filter($rows('SELECT * FROM contracts WHERE user_id = :userId AND archived = 0 ORDER BY name COLLATE NOCASE'),
    fn($r) => (int) $r['payer_user_id'] === $memberId)],
  // Insurances the person pays for or is insured by.
  'insurances' => ['fa-shield-halved', 'insurance.php', array_filter($rows('SELECT * FROM insurances WHERE user_id = :userId AND archived = 0 ORDER BY name COLLATE NOCASE'),
    fn($r) => (int) $r['payer_user_id'] === $memberId || in_array($memberId, insuredMemberIds($r), true))],
];
$incomes = array_filter($rows('SELECT * FROM incomes WHERE user_id = :userId'), fn($r) => (int) ($r['payer_user_id'] ?: array_key_first($members)) === $memberId);
$incomeMonthly = array_sum(array_map(fn($r) => getPriceConverted(getPricePerMonth($r['cycle'], $r['frequency'], (float) $r['amount']), $r['currency_id'], $db, $userId), $incomes));
$costMonthly = 0.0;
foreach ($sections as [, , $list]) {
  foreach ($list as $row) {
    if ((int) $row['payer_user_id'] === $memberId) {
      $costMonthly += $monthly($row);
    }
  }
}
?>

<section class="contain">
  <h2><i class="fa-solid fa-user"></i> <?= translate('person_view', $i18n) ?></h2>
  <div class="module-filter-chips person-chips" role="tablist">
    <?php foreach ($members as $member): ?>
      <a class="module-filter-chip" href="person.php?member=<?= (int) $member['id'] ?>" aria-current="<?= (int) $member['id'] === $memberId ? 'page' : 'false' ?>"
        <?= (int) $member['id'] === $memberId ? 'aria-pressed="true"' : '' ?>><?= $member['name'] ?></a>
    <?php endforeach; ?>
  </div>

  <div class="annual-review-summary">
    <div><strong><?= CurrencyFormatter::format($costMonthly, $mainCurrencyCode) ?></strong><span><?= translate('person_pays_monthly', $i18n) ?></span></div>
    <div><strong><?= CurrencyFormatter::format($incomeMonthly, $mainCurrencyCode) ?></strong><span><?= translate('person_income_monthly', $i18n) ?></span></div>
    <div><strong><?= CurrencyFormatter::format($costMonthly * 12, $mainCurrencyCode) ?></strong><span><?= translate('person_pays_yearly', $i18n) ?></span></div>
  </div>

  <?php foreach ($sections as $key => [$icon, $link, $list]): ?>
    <h3><i class="fa-solid <?= $icon ?>"></i> <?= translate($key, $i18n) ?> (<?= count($list) ?>)</h3>
    <?php if (!$list): ?>
      <p class="dashboard-empty"><?= translate('person_nothing', $i18n) ?></p>
    <?php else: ?>
      <div class="table-scroll">
        <table class="schedule-table">
          <thead><tr><th><?= translate('name', $i18n) ?></th><th><?= translate('price', $i18n) ?> / <?= translate('month', $i18n) ?></th><th></th></tr></thead>
          <tbody>
            <?php foreach ($list as $row): $pays = (int) $row['payer_user_id'] === $memberId; ?>
              <tr>
                <td><a href="<?= $link ?>"><?= $row['name'] ?></a></td>
                <td><?= CurrencyFormatter::format($monthly($row), $mainCurrencyCode) ?></td>
                <td><?= $pays ? translate('person_pays', $i18n) : '<span class="forecast-label">' . sprintf(translate('person_insured_by', $i18n), $members[(int) $row['payer_user_id']]['name'] ?? '') . '</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
</section>

<?php require_once 'includes/footer.php'; ?>
