<?php
// Document section inside the contract / insurance / loan edit form.
// Expects $paperlessType ('contract' | 'insurance' | 'loan'); only rendered
// when Paperless is configured ($paperlessEnabled).
if (empty($paperlessEnabled)) {
  return;
}
?>
<div class="form-group paperless-panel" data-type="<?= $paperlessType ?>">
  <label><i class="fa-solid fa-paperclip"></i> <?= translate('paperless_documents', $i18n) ?></label>
  <p class="paperless-hint" hidden><?= translate('paperless_save_first', $i18n) ?></p>
  <div class="paperless-body" hidden>
    <ul class="paperless-list paperless-attached"></ul>
    <div class="paperless-suggestions" hidden>
      <span class="paperless-subtitle"><?= translate('paperless_suggestions', $i18n) ?></span>
      <ul class="paperless-list paperless-suggestion-list"></ul>
    </div>
    <div class="paperless-search">
      <input type="text" class="paperless-query" autocomplete="off"
        placeholder="<?= translate('paperless_search_placeholder', $i18n) ?>">
      <button type="button" class="button thin paperless-search-button">
        <i class="fa-solid fa-magnifying-glass"></i>
      </button>
    </div>
    <ul class="paperless-list paperless-results"></ul>
    <small class="paperless-tag-hint"><?= translate('paperless_tag_hint', $i18n) ?> <code class="paperless-tag-name"></code></small>
  </div>
</div>
