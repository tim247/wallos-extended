<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
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
  'disability' => 'insurance_type_disability',
  'legal' => 'insurance_type_legal',
  'health' => 'insurance_type_health',
  'building' => 'insurance_type_building',
  'pet' => 'insurance_type_pet',
  'travel' => 'insurance_type_travel',
  'accident' => 'insurance_type_accident',
  'other' => 'insurance_type_other',
];

$insuranceTypeIcons = [
  'liability' => 'fa-shield-halved',
  'household' => 'fa-couch',
  'car' => 'fa-car',
  'life' => 'fa-heart-pulse',
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

$headerClass = count($insurances) > 0 ? "main-actions" : "main-actions hidden";
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
      <a href="insurance.php<?= $showArchived ? '' : '?state=archived' ?>" class="button secondary-button"
        title="<?= translate($showArchived ? 'show_active' : 'show_archive', $i18n) ?>">
        <i class="fa-solid <?= $showArchived ? 'fa-list' : 'fa-box-archive' ?>"></i>
        <span class="hideOnMobile"><?= translate($showArchived ? 'show_active' : 'show_archive', $i18n) ?></span>
      </a>
    </div>
  </header>

  <?php if ($showArchived): ?>
    <h2 class="archive-title"><i class="fa-solid fa-box-archive"></i> <?= translate('archive', $i18n) ?></h2>
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
      <div class="module-card <?= $insurance['archived'] ? 'archived' : '' ?>" data-id="<?= $insurance['id'] ?>">
        <div class="module-card-main" onClick="openEditInsurance(event, <?= $insurance['id'] ?>)">
          <div class="module-card-icon"><i class="fa-solid <?= $typeIcon ?>"></i></div>
          <div class="module-card-info">
            <span class="module-card-title"><?= $insurance['name'] ?></span>
            <span class="module-card-subtitle">
              <?= $insurance['insurer'] ?>
              <span class="module-card-category"><?= translate($insuranceTypes[$insurance['insurance_type']] ?? 'insurance_type_other', $i18n) ?></span>
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
            <?php if (!$insurance['archived']): ?>
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
          <input type="text" id="insurance-insurer" name="insurer" autocomplete="off"
            placeholder="<?= translate('insurer', $i18n) ?>">
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
          <label for="insurance-start-date"><?= translate('start_date', $i18n) ?></label>
          <div class="date-wrapper">
            <input type="date" id="insurance-start-date" name="start_date" autocomplete="off" required>
          </div>
        </div>
        <div class="split50">
          <label for="insurance-renewal-term"><?= translate('renewal_term_months', $i18n) ?></label>
          <input type="number" min="1" id="insurance-renewal-term" name="renewal_term_months" value="12">
        </div>
      </div>
    </div>

    <div class="form-group">
      <div class="inline grow">
        <input type="checkbox" id="insurance-tax-deductible" name="tax_deductible">
        <label for="insurance-tax-deductible" class="grow"><?= translate('tax_deductible', $i18n) ?></label>
      </div>
    </div>

    <div class="form-group">
      <div class="inline grow">
        <input type="checkbox" id="insurance-auto-renews" name="auto_renews" checked
          onchange="toggleInsuranceRenewalFields()">
        <label for="insurance-auto-renews" class="grow"><?= translate('automatically_renews', $i18n) ?></label>
      </div>
    </div>

    <div class="form-group hide" id="insurance-end-date-group">
      <label for="insurance-end-date"><?= translate('end_date', $i18n) ?></label>
      <div class="date-wrapper">
        <input type="date" id="insurance-end-date" name="end_date" autocomplete="off">
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

    <div class="form-group">
      <input type="text" id="insurance-url" name="url" autocomplete="off" placeholder="<?= translate('url', $i18n) ?>">
    </div>

    <div class="form-group">
      <input type="text" id="insurance-notes" name="notes" autocomplete="off"
        placeholder="<?= translate('notes', $i18n) ?>">
    </div>

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
<script src="scripts/paperless.js?<?= $version ?>"></script>
<script src="scripts/files.js?<?= $version ?>"></script>
<script src="scripts/insurance.js?<?= $version ?>"></script>
<?php
$db->close();
require_once 'includes/footer.php';
?>
