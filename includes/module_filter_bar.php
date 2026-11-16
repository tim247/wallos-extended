<?php
// Toolbar for the contract and insurance lists, built like the one on the
// subscriptions page: search, filter menu (person, category / type), sort,
// archive and list / grid view. Filtering and sorting happen in the browser on
// the cards' data attributes (scripts/module-filter.js).
// Expects $members, $filterGroups ([value => label]), $filterGroupTitle, $archiveUrl, $showArchived, $i18n.
$filterMembers = count($members) > 1 ? $members : [];
?>
<button class="button secondary-button mobile-search-toggle" type="button" title="<?= translate('search', $i18n) ?>"
  onClick="openModuleSearch()">
  <i class="fa-solid fa-magnifying-glass"></i>
</button>
<div class="search">
  <input type="text" autocomplete="off" id="module-search" placeholder="<?= translate('search', $i18n) ?>"
    aria-label="<?= translate('search', $i18n) ?>" />
  <span class="fa-solid fa-magnifying-glass search-icon"></span>
  <span class="fa-solid fa-xmark clear-search" id="module-search-clear"></span>
</div>

<?php if ($filterMembers || count($filterGroups) > 1): ?>
  <div class="filtermenu on-dashboard">
    <button class="button secondary-button" type="button" id="module-filter-button" title="<?= translate('filter', $i18n) ?>">
      <i class="fa-solid fa-filter"></i>
    </button>
    <div class="filtermenu-content" id="module-filter">
      <?php if ($filterMembers): ?>
        <div class="filtermenu-submenu" data-filter="member">
          <div class="filter-title"><?= translate('member', $i18n) ?></div>
          <?php foreach ($filterMembers as $member): ?>
            <div class="filter-item" data-value="<?= (int) $member['id'] ?>"><?= $member['name'] ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (count($filterGroups) > 1): ?>
        <div class="filtermenu-submenu" data-filter="group">
          <div class="filter-title"><?= $filterGroupTitle ?></div>
          <?php foreach ($filterGroups as $value => $label): ?>
            <div class="filter-item" data-value="<?= htmlspecialchars((string) $value) ?>"><?= $label ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="sort-container">
  <button class="button secondary-button" type="button" id="module-sort-button" title="<?= translate('sort', $i18n) ?>">
    <i class="fa-solid fa-arrow-down-wide-short"></i>
  </button>
  <div class="sort-options" id="module-sort">
    <ul>
      <li data-sort="name" class="selected"><?= translate('name', $i18n) ?></li>
      <li data-sort="price"><?= translate('price', $i18n) ?></li>
      <li data-sort="deadline"><?= translate('cancellable_until', $i18n) ?></li>
      <?php if ($filterMembers): ?>
        <li data-sort="member"><?= translate('member', $i18n) ?></li>
      <?php endif; ?>
    </ul>
  </div>
</div>

<a href="<?= $archiveUrl ?>" class="button secondary-button"
  title="<?= translate($showArchived ? 'show_active' : 'show_archive', $i18n) ?>">
  <i class="fa-solid <?= $showArchived ? 'fa-list' : 'fa-box-archive' ?>"></i>
</a>

<div class="view-toggle" id="module-view-toggle">
  <button type="button" class="view-toggle-button selected" data-view="list" title="<?= translate('list_view', $i18n) ?>">
    <i class="fa-solid fa-list"></i>
  </button>
  <button type="button" class="view-toggle-button" data-view="grid" title="<?= translate('grid_view', $i18n) ?>">
    <i class="fa-solid fa-table-cells-large"></i>
  </button>
</div>
