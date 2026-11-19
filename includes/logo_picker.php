<?php
// Logo field next to the name: preview / upload, web logo search. Shared by subscriptions, contracts and insurances.
// The search takes the name from the input marked data-logo-name (subscriptions: #name); needs scripts/logo-search.js.
if (!isset($googleSearchEnabled)) {
  $googleSearchEnabled = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='google_search'") > 0
    && $db->querySingle('SELECT COUNT(*) FROM google_search WHERE user_id = ' . (int) $userId . " AND api_key != ''") > 0;
}
?>
<style>
  .logo-preview:after {
    content: '<?= translate('upload_logo', $i18n) ?>';
  }
</style>
      <label for="logo" class="logo-preview">
        <img src="" alt="<?= translate('logo_preview', $i18n) ?>" id="form-logo">
      </label>
      <input type="file" id="logo" name="logo" accept="image/jpeg, image/png, image/gif, image/webp, image/svg+xml"
        onchange="handleFileSelect(event)" class="hidden-input">
      <input type="hidden" id="logo-url" name="logo-url">
      <div id="logo-search-button" class="image-button medium disabled" title="<?= translate('search_logo', $i18n) ?>"
        onClick="searchLogo()">
        <i class="fa-solid fa-magnifying-glass"></i>
      </div>
      <div class="logo-search-backdrop" id="logo-search-backdrop" onClick="closeLogoSearch()"></div>
      <div id="logo-search-results" class="logo-search"<?= !empty($googleSearchEnabled) ? ' data-google-search="1"' : '' ?>>
        <button type="button" class="close-logo-search" onClick="closeLogoSearch()" title="<?= translate('cancel', $i18n) ?>">
          <i class="fa-solid fa-xmark"></i>
        </button>
        <header>
          <h3 id="logo-search-title" data-title="<?= translate('web_search', $i18n) ?>">
            <?= translate('web_search', $i18n) ?>
          </h3>
        </header>
        <div class="logo-search-controls">
          <div class="logo-search-query">
            <input type="text" id="logo-search-query" autocomplete="off" inputmode="search"
              placeholder="<?= translate('search_logo', $i18n) ?>"
              aria-label="<?= translate('search_logo', $i18n) ?>">
            <button type="button" id="logo-search-submit" title="<?= translate('search', $i18n) ?>">
              <i class="fa-solid fa-magnifying-glass"></i>
            </button>
          </div>
          <div id="logo-search-nav" class="logo-search-nav"></div>
        </div>
        <div id="logo-search-images"></div>
      </div>
