<?php
// Year in review ("Life Wrapped"): one card per highlight, like a music year in review.
require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/wrapped.php';

$mainCurrencyCode = $currencies[$main_currency]['code'] ?? 'EUR';
$fmt = fn($amount) => CurrencyFormatter::format($amount, $mainCurrencyCode);
// January looks back at the past year, later in the year the running one is shown.
$defaultYear = (int) date('n') === 1 ? (int) date('Y') - 1 : (int) date('Y');
$year = max(2000, min((int) date('Y'), (int) ($_GET['year'] ?? $defaultYear)));
$w = lifeWrapped($db, (int) $userId, $year);
$saved = $w['cancelled_yearly'] + $w['price_down_yearly'];
$h = fn($value) => htmlspecialchars((string) $value);
$names = fn(array $list) => $h(implode(', ', array_slice(array_column($list, 'name'), 0, 3)));
?>

<section class="contain wrapped">
  <div class="split-header">
    <h2><?= sprintf(translate('wrapped_title', $i18n), $year) ?></h2>
    <form method="get" class="no-print">
      <select name="year" onchange="this.form.submit()">
        <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </form>
  </div>
  <?php if ($year === (int) date('Y')): ?><p class="settings-notes"><?= translate('wrapped_running_year', $i18n) ?></p><?php endif; ?>

  <div class="wrapped-cards">
    <div class="wrapped-card c1">
      <div class="wrapped-kicker"><?= translate('wrapped_saved_kicker', $i18n) ?></div>
      <div class="wrapped-big"><?= $fmt($saved) ?></div>
      <div class="wrapped-text"><?= translate('wrapped_saved_text', $i18n) ?></div>
    </div>

    <div class="wrapped-card c2">
      <div class="wrapped-kicker"><?= translate('wrapped_cancelled_kicker', $i18n) ?></div>
      <div class="wrapped-big"><?= count($w['cancelled']) ?></div>
      <div class="wrapped-text">
        <?= $w['cancelled'] ? sprintf(translate('wrapped_cancelled_text', $i18n), $fmt($w['cancelled_yearly'])) . '<br><small>' . $names($w['cancelled']) . '</small>'
          : translate('wrapped_cancelled_none', $i18n) ?>
      </div>
    </div>

    <div class="wrapped-card c3">
      <div class="wrapped-kicker"><?= translate('wrapped_cheaper_kicker', $i18n) ?></div>
      <div class="wrapped-big"><?= count($w['price_down']) ?></div>
      <div class="wrapped-text">
        <?= $w['price_down'] ? sprintf(translate('wrapped_cheaper_text', $i18n), $fmt($w['price_down_yearly'])) . '<br><small>' . $names($w['price_down']) . '</small>'
          : translate('wrapped_cheaper_none', $i18n) ?>
        <?php if ($w['price_up_count']): ?><br><small><?= sprintf(translate('wrapped_dearer', $i18n), $w['price_up_count'], $fmt($w['price_up_yearly'])) ?></small><?php endif; ?>
      </div>
    </div>

    <?php if ($w['top_category']): ?>
      <div class="wrapped-card c4">
        <div class="wrapped-kicker"><?= translate('wrapped_hobby_kicker', $i18n) ?></div>
        <div class="wrapped-big wrapped-word"><?= $h($w['top_category']['name']) ?></div>
        <div class="wrapped-text"><?= sprintf(translate('wrapped_hobby_text', $i18n), $fmt($w['top_category']['yearly']), $fmt($w['top_category']['yearly'] / 365)) ?></div>
      </div>
    <?php endif; ?>

    <div class="wrapped-card c5">
      <div class="wrapped-kicker"><?= translate('wrapped_time_kicker', $i18n) ?></div>
      <div class="wrapped-big"><?= number_format($w['hours_saved'], 1, ',', '.') ?> h</div>
      <div class="wrapped-text"><?= translate('wrapped_time_text', $i18n) ?>
        <br><small><?= sprintf(translate('wrapped_time_basis', $i18n), count($w['cancelled']), $w['auto_readings'], $w['reminders_done']) ?></small></div>
    </div>

    <div class="wrapped-card c6">
      <div class="wrapped-kicker"><?= translate('wrapped_more_kicker', $i18n) ?></div>
      <ul class="wrapped-list">
        <li><strong><?= $w['reminders_done'] ?></strong> <?= translate('wrapped_more_reminders', $i18n) ?></li>
        <li><strong><?= $w['auto_readings'] ?></strong> <?= translate('wrapped_more_readings', $i18n) ?></li>
        <li><strong><?= $w['inventory_added'] ?></strong> <?= translate('wrapped_more_inventory', $i18n) ?></li>
        <li><strong><?= $w['goals_reached'] ?></strong> <?= translate('wrapped_more_goals', $i18n) ?></li>
      </ul>
    </div>
  </div>
  <p class="settings-notes"><?= translate('wrapped_note', $i18n) ?></p>
</section>

<?php require_once 'includes/footer.php'; ?>
