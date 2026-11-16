<?php

require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
require_once 'includes/price_helpers.php';
require_once 'includes/cancellation_helpers.php';

function getContractBillingCycle($cycle, $frequency, $i18n)
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

require_once 'includes/paperless.php';
$paperlessEnabled = paperlessConfig($db, $userId) !== null;
$paperlessDocCounts = $paperlessEnabled ? paperlessDocCounts($db, $userId, 'contract') : [];
$showArchived = isset($_GET['state']) && $_GET['state'] === 'archived';

$sql = "SELECT * FROM contracts WHERE user_id = :userId AND archived = :archived";
$params = [':archived' => $showArchived ? 1 : 0];

if (isset($_GET['category']) && $_GET['category'] !== '') {
  $categoryIds = array_map('intval', explode(',', $_GET['category']));
  $sql .= " AND category_id IN (" . implode(',', $categoryIds) . ")";
}

$sql .= " ORDER BY name COLLATE NOCASE ASC";

$stmt = $db->prepare($sql);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindValue(':archived', $params[':archived'], SQLITE3_INTEGER);
$result = $stmt->execute();

$contracts = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $contracts[] = $row;
}

$today = date('Y-m-d');
$totalMonthly = 0.0;
$mainCurrencyId = $main_currency;
$mainCurrencyCode = $currencies[$mainCurrencyId]['code'] ?? 'EUR';

foreach ($contracts as &$contract) {
  $price = floatval($contract['price']);
  if (isset($settings['convertCurrency']) && $settings['convertCurrency'] === 'true' && $contract['currency_id'] != $mainCurrencyId) {
    $price = getPriceConverted($price, $contract['currency_id'], $db, $userId);
  }
  $contract['monthly_price'] = getPricePerMonth($contract['cycle'], $contract['frequency'], $price);
  if (!$contract['archived']) {
    $totalMonthly += $contract['monthly_price'];
  }
  $contract['deadline_info'] = getNextCancellationDeadline($contract, $today);
}
unset($contract);

$headerClass = count($contracts) > 0 ? "main-actions" : "main-actions hidden";
?>

<?php
$filterGroups = [];
foreach ($contracts as $filterContract) {
  if (isset($categories[$filterContract['category_id']]) && $categories[$filterContract['category_id']]['name'] !== 'No category') {
    $filterGroups[$filterContract['category_id']] = $categories[$filterContract['category_id']]['name'];
  }
}
$filterGroupTitle = translate('category', $i18n);
$archiveUrl = 'contracts.php' . ($showArchived ? '' : '?state=archived');
?>
<section class="contain">
  <header class="<?= $headerClass ?>" id="main-actions">
    <button class="button" onClick="addContract()">
      <i class="fa-solid fa-circle-plus"></i>
      <?= translate('new_contract', $i18n) ?>
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

  <div class="module-cards" id="contracts">
    <?php foreach ($contracts as $contract):
      $deadline = $contract['deadline_info'];
      $deadlineBadgeClass = '';
      if ($contract['cancellation_status']) {
        $deadlineBadgeClass = 'cancelled';
      } elseif ($deadline !== null) {
        $daysToDeadline = (strtotime($deadline['deadline']) - strtotime($today)) / 86400;
        if ($daysToDeadline <= ($contract['notify_days_before'] ?: 30)) {
          $deadlineBadgeClass = 'warning';
        }
      }
      ?>
      <div class="module-card <?= $contract['archived'] ? 'archived' : '' ?>" data-id="<?= $contract['id'] ?>"
        data-name="<?= $contract['name'] ?>" data-price="<?= round((float) $contract['monthly_price'], 2) ?>"
        data-deadline="<?= $contract['deadline_info']['deadline'] ?? '' ?>" data-member-name="<?= $members[$contract['payer_user_id']]['name'] ?? '' ?>"
        data-member="<?= (int) $contract['payer_user_id'] ?>" data-group="<?= (int) $contract['category_id'] ?>">
        <div class="module-card-main" onClick="openEditContract(event, <?= $contract['id'] ?>)">
          <div class="module-card-info">
            <span class="module-card-title"><?= $contract['name'] ?></span>
            <span class="module-card-subtitle">
              <?= $contract['provider'] ?>
              <?= wallos_member_tag($members, $contract['payer_user_id']) ?>
              <?php if (isset($categories[$contract['category_id']]) && $categories[$contract['category_id']]['name'] !== 'No category'): ?>
                <span class="module-card-category"><?= $categories[$contract['category_id']]['name'] ?></span>
              <?php endif; ?>
            </span>
          </div>
          <div class="module-card-price">
            <span><?= CurrencyFormatter::format($contract['price'], $currencies[$contract['currency_id']]['code'] ?? $mainCurrencyCode) ?></span>
            <span class="module-card-cycle"><?= getContractBillingCycle($contract['cycle'], $contract['frequency'], $i18n) ?></span>
          </div>
        </div>
        <div class="module-card-footer">
          <?php if (!empty($paperlessDocCounts[$contract['id']])): ?>
            <span class="deadline-badge doc-badge" title="<?= translate('paperless_documents', $i18n) ?>"><i class="fa-solid fa-paperclip"></i> <?= $paperlessDocCounts[$contract['id']] ?></span>
          <?php endif; ?>
          <?php if ($contract['cancellation_status']): ?>
            <span class="deadline-badge cancelled"><i class="fa-solid fa-ban"></i> <?= translate('cancelled', $i18n) ?>
              <?php if (!empty($contract['cancellation_confirmed_date'])): ?>
                · <?= translate('cancellation_badge_confirmed', $i18n) ?> <?= date('d.m.Y', strtotime($contract['cancellation_confirmed_date'])) ?>
              <?php elseif (!empty($contract['cancellation_sent_date'])): ?>
                · <?= translate('cancellation_badge_sent', $i18n) ?> <?= date('d.m.Y', strtotime($contract['cancellation_sent_date'])) ?>
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
            <?php if (!$contract['archived']): ?>
              <button class="image-button" title="<?= translate('cancellation_letter', $i18n) ?>"
                onClick="openCancellation(event, 'contract', <?= $contract['id'] ?>)">
                <i class="fa-solid fa-envelope-open-text"></i>
              </button>
            <?php endif; ?>
            <?php if ($contract['archived']): ?>
              <button class="image-button" title="<?= translate('unarchive', $i18n) ?>"
                onClick="archiveContract(event, <?= $contract['id'] ?>, 0)">
                <i class="fa-solid fa-box-open"></i>
              </button>
            <?php else: ?>
              <button class="image-button" title="<?= translate('archive', $i18n) ?>"
                onClick="archiveContract(event, <?= $contract['id'] ?>, 1)">
                <i class="fa-solid fa-box-archive"></i>
              </button>
            <?php endif; ?>
          </span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (count($contracts) == 0): ?>
    <div class="empty-page">
      <img src="images/siteimages/empty.png" alt="<?= translate('empty_page', $i18n) ?>" />
      <p>
        <?= translate($showArchived ? 'no_archived_items' : 'no_contracts_yet', $i18n) ?>
      </p>
      <?php if (!$showArchived): ?>
        <button class="button" onClick="addContract()">
          <i class="fa-solid fa-circle-plus"></i>
          <?= translate('add_first_contract', $i18n) ?>
        </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<section class="subscription-form" id="contract-form">
  <header>
    <h3 id="contract-form-title"><?= translate('add_contract', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeContractForm()"></span>
  </header>
  <form action="endpoints/contracts/add.php" method="post" id="contract-form-data">
    <input type="hidden" id="contract-id" name="id">

    <div class="form-group">
      <input type="text" id="contract-name" name="name" autocomplete="off"
        placeholder="<?= translate('contract_name', $i18n) ?>" required>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <input type="text" id="contract-provider" name="provider" autocomplete="off"
            placeholder="<?= translate('provider', $i18n) ?>">
        </div>
        <div class="split50">
          <input type="text" id="contract-number" name="contract_number" autocomplete="off"
            placeholder="<?= translate('contract_number', $i18n) ?>">
        </div>
      </div>
    </div>

    <div class="form-group-inline">
      <input type="number" step="0.01" id="contract-price" name="price" autocomplete="off"
        placeholder="<?= translate('price', $i18n) ?>" required>
      <select id="contract-currency" name="currency_id">
        <?php foreach ($currencies as $currency): ?>
          <option value="<?= $currency['id'] ?>" <?= $currency['id'] == $main_currency ? 'selected' : '' ?>>
            <?= $currency['name'] ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label for="contract-cycle"><?= translate('payment_every', $i18n) ?></label>
      <div class="inline">
        <select id="contract-frequency" name="frequency">
          <?php for ($i = 1; $i <= 366; $i++): ?>
            <option value="<?= $i ?>"><?= $i ?></option>
          <?php endfor; ?>
        </select>
        <select id="contract-cycle" name="cycle">
          <?php foreach ($cycles as $cycle): ?>
            <?php if ($cycle['id'] == 5) continue; ?>
            <option value="<?= $cycle['id'] ?>" <?= $cycle['id'] == 3 ? "selected" : "" ?>>
              <?= translate(strtolower($cycle['name']), $i18n) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="contract-payment-method"><?= translate('payment_method', $i18n) ?></label>
          <select id="contract-payment-method" name="payment_method_id">
            <?php foreach ($payment_methods as $payment): ?>
              <option value="<?= $payment['id'] ?>"><?= $payment['name'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="split50">
          <label for="contract-payer"><?= translate('paid_by', $i18n) ?></label>
          <select id="contract-payer" name="payer_user_id">
            <?php foreach ($members as $member): ?>
              <option value="<?= $member['id'] ?>"><?= $member['name'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label for="contract-category"><?= translate('category', $i18n) ?></label>
      <select id="contract-category" name="category_id">
        <?php foreach ($categories as $category): ?>
          <option value="<?= $category['id'] ?>"><?= categoryLabel($category['name'], $i18n) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <div class="inline">
        <div class="split50">
          <label for="contract-start-date"><?= translate('start_date', $i18n) ?></label>
          <div class="date-wrapper">
            <input type="date" id="contract-start-date" name="start_date" autocomplete="off" required>
          </div>
        </div>
        <div class="split50">
          <label for="contract-initial-term"><?= translate('initial_term_months', $i18n) ?></label>
          <input type="number" min="0" id="contract-initial-term" name="initial_term_months" value="12">
        </div>
      </div>
    </div>

    <div class="form-group">
      <div class="inline grow">
        <input type="checkbox" id="contract-tax-deductible" name="tax_deductible">
        <label for="contract-tax-deductible" class="grow"><?= translate('tax_deductible', $i18n) ?></label>
      </div>
    </div>

    <div class="form-group">
      <div class="inline grow">
        <input type="checkbox" id="contract-auto-renews" name="auto_renews" checked
          onchange="toggleContractRenewalFields()">
        <label for="contract-auto-renews" class="grow"><?= translate('automatically_renews', $i18n) ?></label>
      </div>
    </div>

    <div class="form-group" id="contract-renewal-group">
      <label for="contract-renewal-term"><?= translate('renewal_term_months', $i18n) ?></label>
      <input type="number" min="1" id="contract-renewal-term" name="renewal_term_months" value="12">
    </div>

    <div class="form-group hide" id="contract-end-date-group">
      <label for="contract-end-date"><?= translate('end_date', $i18n) ?></label>
      <div class="date-wrapper">
        <input type="date" id="contract-end-date" name="end_date" autocomplete="off">
      </div>
    </div>

    <div class="form-group">
      <label><?= translate('notice_period', $i18n) ?></label>
      <div class="inline">
        <div class="split50">
          <select id="contract-notice-months" name="notice_period_months">
            <?php for ($i = 0; $i <= 12; $i++): ?>
              <option value="<?= $i ?>" <?= $i == 3 ? 'selected' : '' ?>><?= $i ?> <?= translate('months', $i18n) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="split50">
          <select id="contract-notice-days" name="notice_period_days">
            <?php for ($i = 0; $i <= 30; $i++): ?>
              <option value="<?= $i ?>"><?= $i ?> <?= translate('days', $i18n) ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="form-group-inline grow">
      <input type="checkbox" id="contract-notifications" name="notifications" onchange="toggleContractNotifyDays()">
      <label for="contract-notifications" class="grow"><?= translate('cancellation_reminder', $i18n) ?></label>
    </div>

    <div class="form-group" id="contract-notify-days-group">
      <label for="contract-notify-days"><?= translate('notify_me', $i18n) ?></label>
      <select id="contract-notify-days" name="notify_days_before" disabled>
        <?php for ($i = 1; $i <= 180; $i++): ?>
          <option value="<?= $i ?>" <?= $i == 30 ? 'selected' : '' ?>><?= $i ?> <?= translate('days_before', $i18n) ?></option>
        <?php endfor; ?>
      </select>
    </div>

    <div class="form-group">
      <div class="inline grow">
        <input type="checkbox" id="contract-cancellation-status" name="cancellation_status">
        <label for="contract-cancellation-status" class="grow"><?= translate('already_cancelled', $i18n) ?></label>
      </div>
    </div>

    <div class="form-group">
      <input type="text" id="contract-url" name="url" autocomplete="off" placeholder="<?= translate('url', $i18n) ?>">
    </div>

    <div class="form-group">
      <input type="text" id="contract-notes" name="notes" autocomplete="off"
        placeholder="<?= translate('notes', $i18n) ?>">
    </div>

    <?php $paperlessType = 'contract'; require 'includes/paperless_panel.php'; ?>
    <?php $filesType = 'contract'; require 'includes/files_panel.php'; ?>

    <div class="buttons">
      <input type="button" value="<?= translate('delete', $i18n) ?>" class="warning-button left thin"
        id="delete-contract" style="display: none">
      <input type="button" value="<?= translate('cancel', $i18n) ?>" class="secondary-button thin"
        onClick="closeContractForm()">
      <input type="submit" value="<?= translate('save', $i18n) ?>" class="thin" id="contract-save-button">
    </div>
  </form>
</section>

<?php require 'includes/cancellation_modal.php'; ?>
<script src="scripts/paperless.js?<?= $assetVersion ?>"></script>
<script src="scripts/files.js?<?= $assetVersion ?>"></script>
<script src="scripts/contracts.js?<?= $assetVersion ?>"></script>
<script src="scripts/module-filter.js?<?= $assetVersion ?>"></script>
<?php
$db->close();
require_once 'includes/footer.php';
?>
