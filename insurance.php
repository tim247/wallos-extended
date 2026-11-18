<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/pending_prices.php';
applyPendingPrices($db, $userId);
require_once 'includes/price_helpers.php';
require_once 'includes/cancellation_helpers.php';

function getInsuranceBillingCycle($cycle, $frequency, $i18n)
{
  switch ($cycle) {
    case 1:
      return $frequency == 1 ? translate('Daily', $i18n) : $frequency . " " . translate('days', $i18n);
    case 2:
      return $frequency == 1 ? translate('Weekly', $i18n) : $frequency . " " . translate('weeks', $i18n);
    case 3:
      return $frequency == 1 ? translate('Monthly', $i18n) : $frequency . " " . translate('months', $i18n);
    case 4:
      return $frequency == 1 ? translate('Yearly', $i18n) : $frequency . " " . translate('years', $i18n);
    case 5:
      return translate('One-time', $i18n);
  }
  return "";
}

$insuranceTypes = [
  'liability' => 'insurance_type_liability',
  'household' => 'insurance_type_household',
  'car' => 'insurance_type_car',
  'life' => 'insurance_type_life',
  'pension' => 'insurance_type_pension',
  'disability' => 'insurance_type_disability',
  'legal' => 'insurance_type_legal',
  'health' => 'insurance_type_health',
  'building' => 'insurance_type_building',
  'pet' => 'insurance_type_pet',
  'travel' => 'insurance_type_travel',
  'accident' => 'insurance_type_accident',
  'other' => 'insurance_type_other',
];

// Own types typed in while adding an insurance are stored as plain text.
$customTypeStmt = $db->prepare('SELECT DISTINCT insurance_type FROM insurances WHERE user_id = :userId ORDER BY insurance_type');
$customTypeStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$customTypeResult = $customTypeStmt->execute();
$customInsuranceTypes = [];
while ($customTypeRow = $customTypeResult->fetchArray(SQLITE3_ASSOC)) {
  if (!isset($insuranceTypes[$customTypeRow['insurance_type']])) {
    $customInsuranceTypes[] = $customTypeRow['insurance_type'];
  }
}

$insuranceTypeIcons = [
  'liability' => 'fa-shield-halved',
  'household' => 'fa-couch',
  'car' => 'fa-car',
  'life' => 'fa-heart-pulse',
  'pension' => 'fa-piggy-bank',
  'disability' => 'fa-user-shield',
  'legal' => 'fa-scale-balanced',
  'health' => 'fa-staff-snake',
  'building' => 'fa-house-chimney',
  'pet' => 'fa-paw',
  'travel' => 'fa-plane',
  'accident' => 'fa-kit-medical',
  'other' => 'fa-file-shield',
];

require_once 'includes/paperless.php';
$paperlessEnabled = paperlessConfig($db, $userId) !== null;
$paperlessDocCounts = $paperlessEnabled ? paperlessDocCounts($db, $userId, 'insurance') : [];
$showArchived = isset($_GET['state']) && $_GET['state'] === 'archived';

$sql = "SELECT * FROM insurances WHERE user_id = :userId AND archived = :archived ORDER BY name COLLATE NOCASE ASC";
$stmt = $db->prepare($sql);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':archived', $showArchived ? 1 : 0, SQLITE3_INTEGER);
$result = $stmt->execute();

$insurances = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $insurances[] = $row;
}

$today = date('Y-m-d');
$totalMonthly = 0.0;
$mainCurrencyId = $main_currency;
$mainCurrencyCode = $currencies[$mainCurrencyId]['code'] ?? 'EUR';

foreach ($insurances as &$insurance) {
  $price = floatval($insurance['price']);
  if (isset($settings['convertCurrency']) && $settings['convertCurrency'] === 'true' && $insurance['currency_id'] != $mainCurrencyId) {
    $price = getPriceConverted($price, $insurance['currency_id'], $db, $userId);
  }
  $insurance['monthly_price'] = getPricePerMonth($insurance['cycle'], $insurance['frequency'], $price);
  if (!$insurance['archived']) {
    $totalMonthly += $insurance['monthly_price'];
  }
  $insurance['deadline_info'] = getNextCancellationDeadline($insurance, $today);
}
unset($insurance);

// Overview: basic cover per person, reviews due, household contents vs. living area, motor switching time.
require_once 'includes/insurance_insights.php';
require_once 'includes/consumption_insights.php';
$overview = [];
// Combined policies: names and monthly totals (policy + its building blocks).
$policyNames = [];
$policyParts = [];
foreach ($insurances as $policyRow) {
  $policyNames[$policyRow['id']] = $policyRow['name'];
}
foreach ($insurances as $policyRow) {
  if (!empty($policyRow['parent_id']) && isset($policyNames[$policyRow['parent_id']])) {
    $policyParts[$policyRow['parent_id']][] = $policyRow['id'];
  }
}
foreach ($policyParts as $parentId => $partIds) {
  $total = 0.0;
  foreach ($insurances as $policyRow) {
    if ($policyRow['id'] == $parentId || in_array($policyRow['id'], $partIds)) {
      $total += $policyRow['monthly_price'];
    }
  }
  $policyParts[$parentId]['_total'] = $total;
}
if (!$showArchived) {
  // Provision: BU per earner, risk life insurance for the main earner.
  $incomeByMember = [];
  $incomeStmt = $db->prepare('SELECT * FROM incomes WHERE user_id = :userId');
  $incomeStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $incomeResult = $incomeStmt->execute();
  while ($incomeRow = $incomeResult->fetchArray(SQLITE3_ASSOC)) {
    $earner = (int) ($incomeRow['payer_user_id'] ?: array_key_first($members));
    $incomeByMember[$earner] = ($incomeByMember[$earner] ?? 0) + getPricePerMonth($incomeRow['cycle'], $incomeRow['frequency'], (float) $incomeRow['amount']);
  }
  foreach (provisionGaps($incomeByMember, $insurances, count($members)) as $gap) {
    $overview[] = ['fa-briefcase-medical', sprintf(translate($gap['key'], $i18n), $members[$gap['member']]['name'] ?? '', isset($gap['value']) ? CurrencyFormatter::format($gap['value'], $mainCurrencyCode) : '')];
  }
  // Insurances without any document (no upload, no Paperless link).
  $withDocs = [];
  foreach (['item_files', 'item_documents'] as $docTable) {
    $docStmt = @$db->prepare("SELECT DISTINCT item_id FROM $docTable WHERE user_id = :userId AND item_type = 'insurance'");
    if ($docStmt) {
      $docStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
      $docResult = $docStmt->execute();
      while ($docRow = $docResult->fetchArray(SQLITE3_NUM)) {
        $withDocs[(int) $docRow[0]] = true;
      }
    }
  }
  $withoutDocs = array_filter($insurances, fn($i) => !$i['cancellation_status'] && empty($i['parent_id']) && !isset($withDocs[(int) $i['id']]));
  if ($withoutDocs) {
    $overview[] = ['fa-file-circle-exclamation', sprintf(translate('insurance_no_documents', $i18n), implode(', ', array_column($withoutDocs, 'name')))];
  }
  // Health insurance with a premium increase: the moment a switch is possible.
  require_once 'includes/pending_prices.php';
  $increased = [];
  foreach (announcedPriceIncreases($db, $userId) as $increase) {
    $increased[$increase['name']] = true;
  }
  $recentStmt = $db->prepare("SELECT item_id FROM price_history WHERE user_id = :userId AND item_type = 'insurance' AND new_price > old_price AND changed_at >= date('now', '-60 days')");
  $recentStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $recentResult = $recentStmt->execute();
  $recentIds = [];
  while ($recentRow = $recentResult->fetchArray(SQLITE3_NUM)) {
    $recentIds[(int) $recentRow[0]] = true;
  }
  foreach ($insurances as $switchRow) {
    if (($switchRow['cancel_mode'] ?? '') === 'switch_only' && (isset($increased[$switchRow['name']]) || isset($recentIds[(int) $switchRow['id']]))) {
      $overview[] = ['fa-right-left', sprintf(translate('switch_possible', $i18n), $switchRow['name'])];
    }
  }
  $active = array_filter($insurances, fn($i) => !$i['cancellation_status']);
  foreach (coverageGaps(array_keys($members), $active) as $memberId => $missing) {
    foreach ($missing as $type) {
      $overview[] = ['fa-user-shield', sprintf(translate('coverage_gap', $i18n), $members[$memberId]['name'], translate('coverage_gap_' . $type, $i18n))];
    }
  }
  $livingArea = (float) (loadConsumptionSettings($db, $userId)['living_area'] ?? 0);
  foreach ($active as $insurance) {
    if (($missingAmount = householdUnderinsurance($insurance, $livingArea)) !== null) {
      $overview[] = ['fa-couch', sprintf(translate('household_underinsured', $i18n), $insurance['name'], CurrencyFormatter::format($missingAmount, $currencies[$insurance['currency_id']]['code'] ?? $mainCurrencyCode))];
    }
    if (insuranceReviewDue($insurance, $today)) {
      $overview[] = ['fa-magnifying-glass', sprintf(translate('review_due', $i18n), $insurance['name'])];
    }
    if ($insurance['insurance_type'] === 'car' && substr($today, 5) >= '10-01' && substr($today, 5) <= '11-30' && ($insurance['cancel_mode'] ?? 'cancellable') === 'cancellable') {
      $overview[] = ['fa-car', sprintf(translate('kfz_switch_time', $i18n), $insurance['name'])];
    }
  }
}

$headerClass = count($insurances) > 0 ? "main-actions" : "main-actions hidden";
?>

<?php
$filterGroups = [];
foreach ($insurances as $filterInsurance) {
  $filterType = $filterInsurance['insurance_type'];
  $filterGroups[$filterType] = isset($insuranceTypes[$filterType]) ? translate($insuranceTypes[$filterType], $i18n) : $filterType;
}
asort($filterGroups);
$filterGroupTitle = translate('insurance_type', $i18n);
$archiveUrl = 'insurance.php' . ($showArchived ? '' : '?state=archived');
?>
<section class="contain">
  <header class="<?= $headerClass ?>" id="main-actions">
    <button class="button" onClick="addInsurance()">
      <i class="fa-solid fa-circle-plus"></i>
      <?= translate('new_insurance', $i18n) ?>
    </button>
    <div class="top-actions">
      <div class="module-total">
        <?php if (!$showArchived): ?>
          <span class="module-total-label"><?= translate('monthly_cost', $i18n) ?>:</span>
          <span class="module-total-value"><?= CurrencyFormatter::format($totalMonthly, $mainCurrencyCode) ?></span>
        <?php endif; ?>
      </div>
      <?php include 'includes/module_filter_bar.php'; ?>
    </div>
  </header>

  <?php if ($showArchived): ?>
    <h2 class="archive-title"><i class="fa-solid fa-box-archive"></i> <?= translate('archive', $i18n) ?></h2>
  <?php endif; ?>

  <?php
  // Tips the user hid: keyed by a hash of the text, so a tip comes back when its content changes.
  $hiddenHints = array_filter(explode(',', (string) ($settings['insurance_hidden_hints'] ?? '')));
  $hiddenHintCount = count($overview);
  $overview = array_filter($overview, fn($hint) => !in_array(substr(md5($hint[1]), 0, 8), $hiddenHints, true));
  $hiddenHintCount -= count($overview);
  $canHideHints = empty($isReadOnlyAccount);
  ?>
  <?php if ($overview || $hiddenHintCount): ?>
    <section class="insurance-overview" aria-labelledby="insurance-overview-title">
      <h3 id="insurance-overview-title"><i class="fa-solid fa-lightbulb"></i> <?= translate('insurance_overview', $i18n) ?></h3>
      <ul>
        <?php foreach ($overview as [$icon, $text]): ?>
          <li><i class="fa-solid <?= $icon ?>" aria-hidden="true"></i> <span><?= $text ?></span>
            <?php if ($canHideHints): ?><button type="button" class="hint-hide" title="<?= translate('hint_hide', $i18n) ?>" aria-label="<?= translate('hint_hide', $i18n) ?>" onclick="hideInsuranceHint('<?= substr(md5($text), 0, 8) ?>')"><i class="fa-solid fa-xmark"></i></button><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($hiddenHintCount && $canHideHints): ?>
        <button type="button" class="hint-show-all" onclick="hideInsuranceHint('')"><?= sprintf(translate('hints_show_hidden', $i18n), $hiddenHintCount) ?></button>
      <?php endif; ?>
    </section>
    <script>
      function hideInsuranceHint(key) {
        fetch('endpoints/insurance/hide_hint.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken }, body: JSON.stringify({ key }) })
          .then((r) => r.json()).then((data) => data.success ? location.reload() : showErrorMessage(data.message));
      }
    </script>
  <?php endif; ?>
  <div class="module-cards" id="insurances">
    <?php foreach ($insurances as $insurance):
      $deadline = $insurance['deadline_info'];
      $deadlineBadgeClass = '';
      if ($insurance['cancellation_status']) {
        $deadlineBadgeClass = 'cancelled';
      } elseif ($deadline !== null) {
        $daysToDeadline = (strtotime($deadline['deadline']) - strtotime($today)) / 86400;
        if ($daysToDeadline <= ($insurance['notify_days_before'] ?: 30)) {
          $deadlineBadgeClass = 'warning';
        }
      }
      $typeIcon = $insuranceTypeIcons[$insurance['insurance_type']] ?? 'fa-file-shield';
      ?>
      <div class="module-card <?= $insurance['archived'] ? 'archived' : '' ?>" data-id="<?= $insurance['id'] ?>"
        data-name="<?= $insurance['name'] ?>" data-price="<?= round((float) $insurance['monthly_price'], 2) ?>"
        data-deadline="<?= $insurance['deadline_info']['deadline'] ?? '' ?>" data-member-name="<?= $members[$insurance['payer_user_id']]['name'] ?? '' ?>"
        data-member="<?= (int) $insurance['payer_user_id'] ?>" data-group="<?= htmlspecialchars($insurance['insurance_type']) ?>">
        <div class="module-card-main" onClick="openEditInsurance(event, <?= $insurance['id'] ?>)">
          <div class="module-card-icon"><i class="fa-solid <?= $typeIcon ?>"></i></div>
          <div class="module-card-info">
            <span class="module-card-title"><?= $insurance['name'] ?></span>
            <span class="module-card-subtitle">
              <?= $insurance['insurer'] ?>
              <?= wallos_member_tag($members, $insurance['payer_user_id']) ?>
              <span class="module-card-category"><?= isset($insuranceTypes[$insurance['insurance_type']]) ? translate($insuranceTypes[$insurance['insurance_type']], $i18n) : $insurance['insurance_type'] ?></span>
            </span>
            <?php if ($insurance['coverage_amount']): ?>
              <span class="module-card-detail">
                <?= translate('coverage_amount', $i18n) ?>:
                <?= CurrencyFormatter::format($insurance['coverage_amount'], $currencies[$insurance['currency_id']]['code'] ?? $mainCurrencyCode) ?>
              </span>
            <?php endif; ?>
          </div>
          <div class="module-card-price">
            <span><?= CurrencyFormatter::format($insurance['price'], $currencies[$insurance['currency_id']]['code'] ?? $mainCurrencyCode) ?></span>
            <span class="module-card-cycle"><?= getInsuranceBillingCycle($insurance['cycle'], $insurance['frequency'], $i18n) ?></span>
          </div>
        </div>
        <div class="module-card-footer">
          <?php if (!empty($paperlessDocCounts[$insurance['id']])): ?>
            <span class="deadline-badge doc-badge" title="<?= translate('paperless_documents', $i18n) ?>"><i class="fa-solid fa-paperclip"></i> <?= $paperlessDocCounts[$insurance['id']] ?></span>
          <?php endif; ?>
          <?php if (!empty($insurance['parent_id']) && isset($policyNames[$insurance['parent_id']])): ?>
            <span class="deadline-badge"><i class="fa-solid fa-layer-group"></i> <?= sprintf(translate('policy_part_badge', $i18n), $policyNames[$insurance['parent_id']]) ?></span>
          <?php elseif (!empty($policyParts[$insurance['id']])): ?>
            <span class="deadline-badge"><i class="fa-solid fa-layer-group"></i> <?= sprintf(translate('policy_parts_badge', $i18n), count($policyParts[$insurance['id']]) - 1, CurrencyFormatter::format($policyParts[$insurance['id']]['_total'] ?? 0, $mainCurrencyCode)) ?></span>
          <?php endif; ?>
          <?php if (($insurance['cancel_mode'] ?? 'cancellable') === 'switch_only' && !$insurance['cancellation_status']): ?>
            <span class="deadline-badge cancelled" title="<?= translate('cancel_mode_switch_only_hint', $i18n) ?>"><i class="fa-solid fa-right-left"></i> <?= translate('cancel_mode_switch_only', $i18n) ?></span>
          <?php endif; ?>
          <?php if (!empty($insurance['end_date']) && empty($insurance['auto_renews'])): ?>
            <span class="deadline-badge"><i class="fa-solid fa-hourglass-end"></i> <?= translate('insurance_term_until', $i18n) ?> <?= date('d.m.Y', strtotime($insurance['end_date'])) ?></span>
          <?php endif; ?>
          <?php if (!empty($insurance['pending_price_date'])): ?>
            <span class="deadline-badge warning"><i class="fa-solid fa-arrow-trend-up"></i> <?= sprintf(translate('price_from_date', $i18n), date('d.m.Y', strtotime($insurance['pending_price_date'])), CurrencyFormatter::format((float) $insurance['pending_price'], $currencies[$insurance['currency_id']]['code'] ?? $mainCurrencyCode)) ?></span>
          <?php endif; ?>
          <?php if ($insurance['cancellation_status']): ?>
            <span class="deadline-badge cancelled"><i class="fa-solid fa-ban"></i> <?= translate('cancelled', $i18n) ?>
              <?php if (!empty($insurance['cancellation_confirmed_date'])): ?>
                · <?= translate('cancellation_badge_confirmed', $i18n) ?> <?= date('d.m.Y', strtotime($insurance['cancellation_confirmed_date'])) ?>
              <?php elseif (!empty($insurance['cancellation_sent_date'])): ?>
                · <?= translate('cancellation_badge_sent', $i18n) ?> <?= date('d.m.Y', strtotime($insurance['cancellation_sent_date'])) ?>
              <?php endif; ?>
              <?php if ($deadline !== null): ?>
                — <?= translate('ends_on', $i18n) ?> <?= date('d.m.Y', strtotime($deadline['term_end'])) ?>
              <?php endif; ?>
            </span>
          <?php elseif ($deadline !== null): ?>
            <span class="deadline-badge <?= $deadlineBadgeClass ?>">
              <i class="fa-solid fa-bell"></i>
              <?= translate('cancellable_until', $i18n) ?> <?= date('d.m.Y', strtotime($deadline['deadline'])) ?>
            </span>
          <?php endif; ?>
          <span class="module-card-actions">
            <?php if (!$insurance['archived'] && ($insurance['cancel_mode'] ?? 'cancellable') !== 'not_cancellable' && empty($insurance['parent_id'])): ?>
              <button class="image-button" title="<?= translate('cancellation_letter', $i18n) ?>"
                onClick="openCancellation(event, 'insurance', <?= $insurance['id'] ?>)">
                <i class="fa-solid fa-envelope-open-text"></i>
              </button>
            <?php endif; ?>
            <?php if ($insurance['archived']): ?>
              <button class="image-button" title="<?= translate('unarchive', $i18n) ?>"
                onClick="archiveInsurance(event, <?= $insurance['id'] ?>, 0)">
                <i class="fa-solid fa-box-open"></i>
              </button>
            <?php else: ?>
              <button class="image-button" title="<?= translate('archive', $i18n) ?>"
                onClick="archiveInsurance(event, <?= $insurance['id'] ?>, 1)">
                <i class="fa-solid fa-box-archive"></i>
              </button>
            <?php endif; ?>
          </span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (count($insurances) == 0): ?>
    <div class="empty-page">
      <img src="images/siteimages/empty.png" alt="<?= translate('empty_page', $i18n) ?>" />
      <p>
        <?= translate($showArchived ? 'no_archived_items' : 'no_insurances_yet', $i18n) ?>
      </p>
      <?php if (!$showArchived): ?>
        <button class="button" onClick="addInsurance()">
          <i class="fa-solid fa-circle-plus"></i>
          <?= translate('add_first_insurance', $i18n) ?>
        </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<?php if (count($insurances) > 0 && !$showArchived): ?>
  <section class="contain">
    <form class="tax-export-form" action="endpoints/insurance/tax_export.php" method="get">
      <i class="fa-solid fa-file-csv" aria-hidden="true"></i>
      <label for="tax-export-year"><?= translate('tax_export', $i18n) ?></label>
      <select id="tax-export-year" name="year">
        <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 5; $y--): ?>
          <option value="<?= $y ?>" <?= $y === (int) date('Y') - 1 ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
      <button type="submit" class="button secondary-button thin"><?= translate('download', $i18n) ?></button>
      <small class="loan-anchor-hint"><?= translate('tax_export_hint', $i18n) ?></small>
    </form>
  </section>
<?php endif; ?>

<section class="subscription-form" id="insurance-form">
  <header>
    <h3 id="insurance-form-title"><?= translate('add_insurance', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeInsuranceForm()"></span>
  </header>
  <form action="endpoints/insurance/add.php" method="post" id="insurance-form-data">
    <input type="hidden" id="insurance-id" name="id">

    <div class="form-group">
      <input type="text" id="insurance-name" name="name" autocomplete="off"
        placeholder="<?= translate('insurance_name', $i18n) ?>" required>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <input type="text" id="insurance-insurer" name="insurer" autocomplete="off" list="insurance-insurer-list"
            placeholder="<?= translate('insurer', $i18n) ?>">
          <datalist id="insurance-insurer-list">
            <?php
            // Names already used (archived entries too), suggested while typing.
            $known = $db->prepare("SELECT DISTINCT insurer FROM insurances WHERE user_id = :userId AND insurer != '' ORDER BY insurer COLLATE NOCASE");
            $known->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $knownResult = $known->execute();
            while ($knownRow = $knownResult->fetchArray(SQLITE3_NUM)): ?>
              <option value="<?= $knownRow[0] ?>"></option>
            <?php endwhile; ?>
          </datalist>
        </div>
        <div class="split50">
          <input type="text" id="insurance-policy-number" name="policy_number" autocomplete="off"
            placeholder="<?= translate('policy_number', $i18n) ?>">
        </div>
      </div>
    </div>

    <div class="form-group">
      <label for="insurance-type"><?= translate('insurance_type', $i18n) ?></label>
      <select id="insurance-type" name="insurance_type">
        <?php foreach ($insuranceTypes as $key => $labelKey): ?>
          <option value="<?= $key ?>"><?= translate($labelKey, $i18n) ?></option>
        <?php endforeach; ?>
        <?php foreach ($customInsuranceTypes as $customType): ?>
          <option value="<?= $customType ?>"><?= $customType ?></option>
        <?php endforeach; ?>
        <option value="__new" data-prompt="<?= htmlspecialchars(translate('insurance_type_new_prompt', $i18n)) ?>">+ <?= translate('insurance_type_new', $i18n) ?></option>
      </select>
    </div>

    <div class="form-group-inline">
      <input type="number" step="0.01" id="insurance-price" name="price" autocomplete="off"
        placeholder="<?= translate('premium', $i18n) ?>" required>
      <select id="insurance-currency" name="currency_id">
        <?php foreach ($currencies as $currency): ?>
          <option value="<?= $currency['id'] ?>" <?= $currency['id'] == $main_currency ? 'selected' : '' ?>>
            <?= $currency['name'] ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <details class="pending-price-details" id="insurance-pending-details">
      <summary><i class="fa-solid fa-arrow-trend-up"></i> <?= translate('announce_price_change', $i18n) ?></summary>
      <div class="form-group-inline">
        <div class="grow">
          <label for="insurance-pending-price"><?= translate('new_price', $i18n) ?></label>
          <input type="number" step="0.01" min="0" id="insurance-pending-price" name="pending_price" autocomplete="off">
        </div>
        <div class="grow">
          <label for="insurance-pending-date"><?= translate('valid_from', $i18n) ?></label>
          <input type="date" id="insurance-pending-date" name="pending_price_date" autocomplete="off">
        </div>
      </div>
      <small class="loan-anchor-hint"><?= translate('announce_price_change_hint', $i18n) ?></small>
    </details>

    <div class="form-group">
      <label for="insurance-cycle"><?= translate('payment_every', $i18n) ?></label>
      <div class="inline">
        <select id="insurance-frequency" name="frequency">
          <?php for ($i = 1; $i <= 366; $i++): ?>
            <option value="<?= $i ?>"><?= $i ?></option>
          <?php endfor; ?>
        </select>
        <select id="insurance-cycle" name="cycle">
          <?php foreach ($cycles as $cycle): ?>
            <?php if ($cycle['id'] == 5) continue; ?>
            <option value="<?= $cycle['id'] ?>" <?= $cycle['id'] == 4 ? "selected" : "" ?>>
              <?= translate(strtolower($cycle['name']), $i18n) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="insurance-coverage"><?= translate('coverage_amount', $i18n) ?></label>
          <input type="number" step="0.01" id="insurance-coverage" name="coverage_amount" autocomplete="off">
        </div>
        <div class="split50">
          <label for="insurance-deductible"><?= translate('deductible', $i18n) ?></label>
          <input type="number" step="0.01" id="insurance-deductible" name="deductible" autocomplete="off">
        </div>
      </div>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="insurance-payment-method"><?= translate('payment_method', $i18n) ?></label>
          <select id="insurance-payment-method" name="payment_method_id">
            <?php foreach ($payment_methods as $payment): ?>
              <option value="<?= $payment['id'] ?>"><?= $payment['name'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="split50">
          <label for="insurance-payer"><?= translate('paid_by', $i18n) ?></label>
          <select id="insurance-payer" name="payer_user_id">
            <?php foreach ($members as $member): ?>
              <option value="<?= $member['id'] ?>"><?= $member['name'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="insurance-parent"><?= translate('policy_part_of', $i18n) ?></label>
          <select id="insurance-parent" name="parent_id" onchange="toggleInsuranceCancelArea()">
            <option value=""><?= translate('policy_standalone', $i18n) ?></option>
            <?php foreach ($insurances as $policyOption): if ($policyOption['archived'] || !empty($policyOption['parent_id'])) continue; ?>
              <option value="<?= (int) $policyOption['id'] ?>"><?= $policyOption['name'] ?><?= $policyOption['insurer'] ? ' · ' . $policyOption['insurer'] : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="split50">
          <label for="insurance-refund"><?= translate('refund_amount', $i18n) ?></label>
          <input type="number" step="0.01" min="0" id="insurance-refund" name="refund_amount" autocomplete="off">
        </div>
      </div>
      <small class="loan-anchor-hint"><?= translate('policy_part_hint', $i18n) ?></small>
    </div>

    <div class="form-group">
      <label for="insurance-cancel-mode"><?= translate('cancel_mode', $i18n) ?></label>
      <select id="insurance-cancel-mode" name="cancel_mode" onchange="this.dataset.touched = '1'; toggleInsuranceCancelArea()">
        <option value="cancellable"><?= translate('cancel_mode_cancellable', $i18n) ?></option>
        <option value="switch_only"><?= translate('cancel_mode_switch_only', $i18n) ?></option>
        <option value="not_cancellable"><?= translate('cancel_mode_not_cancellable', $i18n) ?></option>
      </select>
      <small class="loan-anchor-hint" id="insurance-cancel-mode-hint"></small>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="insurance-start-date"><?= translate('start_date', $i18n) ?></label>
          <div class="date-wrapper">
            <input type="date" id="insurance-start-date" name="start_date" autocomplete="off" required>
          </div>
        </div>
        <div class="split50">
          <label for="insurance-initial-term"><?= translate('initial_term_months', $i18n) ?></label>
          <input type="number" min="1" id="insurance-initial-term" name="initial_term_months" value="12">
        </div>
      </div>
    </div>

    <div class="form-group">
      <label for="insurance-renewal-term"><?= translate('renewal_term_months', $i18n) ?></label>
      <input type="number" min="1" id="insurance-renewal-term" name="renewal_term_months" value="12">
      <small class="loan-anchor-hint"><?= translate('term_hint', $i18n) ?></small>
    </div>

    <div class="form-group">
      <div class="inline grow">
        <input type="checkbox" id="insurance-tax-deductible" name="tax_deductible">
        <label for="insurance-tax-deductible" class="grow"><?= translate('tax_deductible', $i18n) ?></label>
      </div>
    </div>

    <div class="form-group">
      <label for="insurance-end-date"><?= translate('insurance_term_end', $i18n) ?></label>
      <div class="date-wrapper">
        <input type="date" id="insurance-end-date" name="end_date" autocomplete="off"
          onchange="if (this.value) document.querySelector('#insurance-auto-renews').checked = false">
      </div>
      <small class="loan-anchor-hint"><?= translate('insurance_term_end_hint', $i18n) ?></small>
    </div>

    <div id="insurance-cancel-area">
    <div class="form-group">
      <div class="inline grow">
        <input type="checkbox" id="insurance-auto-renews" name="auto_renews" checked
          onchange="if (this.checked) document.querySelector('#insurance-end-date').value = ''">
        <label for="insurance-auto-renews" class="grow"><?= translate('automatically_renews', $i18n) ?></label>
      </div>
    </div>

    <div class="form-group">
      <label><?= translate('notice_period', $i18n) ?></label>
      <div class="inline">
        <div class="split50">
          <select id="insurance-notice-months" name="notice_period_months">
            <?php for ($i = 0; $i <= 12; $i++): ?>
              <option value="<?= $i ?>" <?= $i == 3 ? 'selected' : '' ?>><?= $i ?> <?= translate('months', $i18n) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="split50">
          <select id="insurance-notice-days" name="notice_period_days">
            <?php for ($i = 0; $i <= 30; $i++): ?>
              <option value="<?= $i ?>"><?= $i ?> <?= translate('days', $i18n) ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="form-group-inline grow">
      <input type="checkbox" id="insurance-notifications" name="notifications" onchange="toggleInsuranceNotifyDays()">
      <label for="insurance-notifications" class="grow"><?= translate('cancellation_reminder', $i18n) ?></label>
    </div>

    <div class="form-group" id="insurance-notify-days-group">
      <label for="insurance-notify-days"><?= translate('notify_me', $i18n) ?></label>
      <select id="insurance-notify-days" name="notify_days_before" disabled>
        <?php for ($i = 1; $i <= 180; $i++): ?>
          <option value="<?= $i ?>" <?= $i == 30 ? 'selected' : '' ?>><?= $i ?> <?= translate('days_before', $i18n) ?></option>
        <?php endfor; ?>
      </select>
    </div>

    <div class="form-group">
      <div class="inline grow">
        <input type="checkbox" id="insurance-cancellation-status" name="cancellation_status">
        <label for="insurance-cancellation-status" class="grow"><?= translate('already_cancelled', $i18n) ?></label>
      </div>
    </div>
    </div>

    <div class="form-group hide" id="insurance-kfz-group">
      <label><?= translate('kfz_data', $i18n) ?></label>
      <div class="form-group-inline insurance-kfz">
        <label><?= translate('kfz_sf_class', $i18n) ?><input type="text" id="insurance-kfz-sf" name="kfz_sf_class" maxlength="10" placeholder="SF 12"></label>
        <label><?= translate('kfz_km', $i18n) ?><input type="number" id="insurance-kfz-km" name="kfz_km" min="0" step="1000" placeholder="12000"></label>
        <label><?= translate('kfz_type_class', $i18n) ?><input type="text" id="insurance-kfz-type" name="kfz_type_class" maxlength="10"></label>
        <label><?= translate('kfz_region_class', $i18n) ?><input type="text" id="insurance-kfz-region" name="kfz_region_class" maxlength="10"></label>
      </div>
      <small class="loan-anchor-hint"><?= translate('kfz_hint', $i18n) ?></small>
    </div>

    <?php if (count($members) > 1): ?>
      <div class="form-group">
        <label><?= translate('insured_persons', $i18n) ?></label>
        <div class="insured-persons">
          <?php foreach ($members as $member): ?>
            <label><input type="checkbox" name="insured_persons[]" value="<?= (int) $member['id'] ?>"> <?= $member['name'] ?></label>
          <?php endforeach; ?>
        </div>
        <small class="loan-anchor-hint"><?= translate('insured_persons_hint', $i18n) ?></small>
      </div>
    <?php endif; ?>

    <details class="pending-price-details" id="insurance-more-details">
      <summary><i class="fa-solid fa-list-check"></i> <?= translate('coverage_and_contact', $i18n) ?></summary>
      <div class="form-group">
        <label for="insurance-coverage-notes"><?= translate('coverage_notes', $i18n) ?></label>
        <textarea id="insurance-coverage-notes" name="coverage_notes" rows="4" maxlength="2000" placeholder="<?= translate('coverage_notes_placeholder', $i18n) ?>"></textarea>
      </div>
      <div class="form-group-inline insurance-kfz">
        <label><?= translate('contact_name', $i18n) ?><input type="text" id="insurance-contact-name" name="contact_name" maxlength="100"></label>
        <label><?= translate('contact_phone', $i18n) ?><input type="tel" id="insurance-contact-phone" name="contact_phone" maxlength="40"></label>
        <label><?= translate('contact_email', $i18n) ?><input type="email" id="insurance-contact-email" name="contact_email" maxlength="100"></label>
      </div>
      <div class="form-group">
        <label for="insurance-last-review"><?= translate('last_review', $i18n) ?></label>
        <input type="date" id="insurance-last-review" name="last_review">
        <small class="loan-anchor-hint"><?= translate('last_review_hint', $i18n) ?></small>
      </div>
    </details>

    <div class="form-group">
      <input type="text" id="insurance-url" name="url" autocomplete="off" placeholder="<?= translate('url', $i18n) ?>">
    </div>

    <div class="form-group">
      <input type="text" id="insurance-notes" name="notes" autocomplete="off"
        placeholder="<?= translate('notes', $i18n) ?>">
    </div>

    <section class="insurance-panel" id="insurance-claims-panel" hidden>
      <h4><i class="fa-solid fa-file-medical"></i> <?= translate('claims_and_balance', $i18n) ?></h4>
      <div id="insurance-balance"></div>
      <div id="insurance-claims-list"></div>
      <p class="loan-anchor-hint" id="insurance-claim-hint"></p>
      <div class="form-group-inline insurance-kfz" id="insurance-claim-form">
        <label><?= translate('date', $i18n) ?><input type="date" id="claim-date"></label>
        <label class="grow"><?= translate('claim_description', $i18n) ?><input type="text" id="claim-description" maxlength="300"></label>
        <label><?= translate('claim_number', $i18n) ?><input type="text" id="claim-number" maxlength="60"></label>
        <label><?= translate('amount_claimed', $i18n) ?><input type="number" id="claim-amount" step="0.01" min="0"></label>
        <button type="button" class="button thin" onClick="addClaim()"><i class="fa-solid fa-circle-plus"></i> <?= translate('add_claim', $i18n) ?></button>
      </div>
      <div id="insurance-development"></div>
      <div class="insurance-panel-actions">
        <button type="button" class="button secondary-button thin" onClick="markInsuranceReviewed()"><i class="fa-solid fa-check"></i> <?= translate('mark_reviewed', $i18n) ?></button>
        <button type="button" class="button secondary-button thin" onClick="extractPolicyData()"><i class="fa-solid fa-wand-magic-sparkles"></i> <?= translate('extract_from_documents', $i18n) ?></button>
      </div>
    </section>

    <?php $paperlessType = 'insurance'; require 'includes/paperless_panel.php'; ?>
    <?php $filesType = 'insurance'; require 'includes/files_panel.php'; ?>

    <div class="buttons">
      <input type="button" value="<?= translate('delete', $i18n) ?>" class="warning-button left thin"
        id="delete-insurance" style="display: none">
      <input type="button" value="<?= translate('cancel', $i18n) ?>" class="secondary-button thin"
        onClick="closeInsuranceForm()">
      <input type="submit" value="<?= translate('save', $i18n) ?>" class="thin" id="insurance-save-button">
    </div>
  </form>
</section>

<?php require 'includes/cancellation_modal.php'; ?>
<script src="scripts/paperless.js?<?= $assetVersion ?>"></script>
<script src="scripts/files.js?<?= $assetVersion ?>"></script>
<script src="scripts/insurance.js?<?= $assetVersion ?>"></script>
<script src="scripts/module-filter.js?<?= $assetVersion ?>"></script>
<?php
$db->close();
require_once 'includes/footer.php';
?>
