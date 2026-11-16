<?php
// Filter bar for the contract and insurance lists: text search plus chips for
// person and group (category / insurance type). Filtering happens in the browser
// on the cards' data-member / data-group attributes (scripts/module-filter.js).
// Expects $members, $filterGroups ([value => label]) and $i18n.
$filterMembers = count($members) > 1 ? $members : [];
?>
<div class="module-filter" id="module-filter">
  <div class="search">
    <input type="text" autocomplete="off" id="module-search" placeholder="<?= translate('search', $i18n) ?>"
      aria-label="<?= translate('search', $i18n) ?>" />
    <span class="fa-solid fa-magnifying-glass search-icon"></span>
  </div>
  <?php if ($filterMembers): ?>
    <div class="module-filter-chips" data-filter="member" role="group" aria-label="<?= translate('member', $i18n) ?>">
      <i class="fa-solid fa-user" aria-hidden="true"></i>
      <?php foreach ($filterMembers as $member): ?>
        <button type="button" class="module-filter-chip" data-value="<?= (int) $member['id'] ?>" aria-pressed="false"><?= $member['name'] ?></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (count($filterGroups) > 1): ?>
    <div class="module-filter-chips" data-filter="group" role="group">
      <i class="fa-solid fa-tag" aria-hidden="true"></i>
      <?php foreach ($filterGroups as $value => $label): ?>
        <button type="button" class="module-filter-chip" data-value="<?= htmlspecialchars((string) $value) ?>" aria-pressed="false"><?= $label ?></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <p class="module-filter-empty" hidden><?= translate('no_matching_entries', $i18n) ?></p>
</div>
