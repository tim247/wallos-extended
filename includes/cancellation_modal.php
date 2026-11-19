<?php
// Cancellation dialog shared by contracts.php and insurance.php:
// letter details + PDF download, and the status "sent / confirmed".
?>
<section class="subscription-form" id="cancellation-form">
  <header>
    <h3 id="cancellation-title"><?= translate('cancellation_letter', $i18n) ?></h3>
    <span class="fa-solid fa-xmark close-form" onClick="closeCancellation()"></span>
  </header>

  <p class="cancellation-status" id="cancellation-status"></p>

  <div class="form-group">
    <label for="cancellation-sender-name"><?= translate('cancellation_sender', $i18n) ?></label>
    <input type="text" id="cancellation-sender-name" autocomplete="name"
      placeholder="<?= translate('cancellation_sender_name', $i18n) ?>">
  </div>
  <div class="form-group">
    <textarea id="cancellation-sender-address" rows="2" autocomplete="street-address"
      placeholder="<?= translate('cancellation_sender_address', $i18n) ?>"></textarea>
  </div>
  <div class="form-group">
    <div class="inline">
      <div class="split50">
        <label for="cancellation-place"><?= translate('cancellation_place', $i18n) ?></label>
        <input type="text" id="cancellation-place" autocomplete="off">
      </div>
      <div class="split50">
        <label for="cancellation-date"><?= translate('cancellation_letter_date', $i18n) ?></label>
        <input type="date" id="cancellation-date">
      </div>
    </div>
  </div>
  <div class="form-group">
    <label for="cancellation-recipient-address"><?= translate('cancellation_recipient_address', $i18n) ?></label>
    <textarea id="cancellation-recipient-address" rows="3"
      placeholder="<?= translate('cancellation_recipient_address_hint', $i18n) ?>"></textarea>
  </div>
  <div class="form-group">
    <label for="cancellation-terminate-on"><?= translate('cancellation_terminate_on', $i18n) ?></label>
    <input type="date" id="cancellation-terminate-on">
    <small id="cancellation-deadline-hint" class="cancellation-hint"></small>
  </div>

  <div class="buttons">
    <input type="button" value="<?= translate('cancel', $i18n) ?>" class="secondary-button thin"
      onClick="closeCancellation()">
    <input type="button" value="<?= translate('cancellation_create_pdf', $i18n) ?>" class="thin"
      id="cancellation-generate" onClick="generateCancellationLetter()">
  </div>

  <div class="form-group" id="cancellation-mail" hidden>
    <label for="cancellation-mail-text"><?= translate('cancellation_mail_text', $i18n) ?></label>
    <textarea id="cancellation-mail-text" rows="9" readonly></textarea>
    <div class="buttons">
      <button type="button" class="button secondary-button thin" data-done="<?= translate('copied', $i18n) ?>" onClick="copyCancellationText(this)"><i class="fa-solid fa-copy"></i> <?= translate('copy_text', $i18n) ?></button>
    </div>
  </div>

  <hr>
  <div class="form-group">
    <label for="cancellation-status-date"><?= translate('cancellation_status', $i18n) ?></label>
    <input type="date" id="cancellation-status-date">
  </div>
  <div class="buttons cancellation-status-buttons">
    <input type="button" value="<?= translate('cancellation_reset', $i18n) ?>" class="secondary-button thin"
      onClick="setCancellationStatus('reset')">
    <input type="button" value="<?= translate('cancellation_mark_sent', $i18n) ?>" class="thin"
      onClick="setCancellationStatus('sent')">
    <input type="button" value="<?= translate('cancellation_mark_confirmed', $i18n) ?>" class="thin"
      onClick="setCancellationStatus('confirmed')">
  </div>
</section>
<script src="scripts/cancellation.js?<?= $assetVersion ?>"></script>
