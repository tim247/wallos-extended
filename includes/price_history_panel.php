<?php
// Price history inside the contract / insurance form. Expects $priceHistoryType ('contract' or 'insurance').
// Hidden for a new item; priceHistoryLoad() in scripts/price_history.js fills and shows it when editing.
?>
<details class="price-history" id="<?= $priceHistoryType ?>-price-history" data-type="<?= $priceHistoryType ?>" hidden
  data-labels="<?= htmlspecialchars(json_encode(array_combine(["since", "price", "change", "delete", "before", "total", "empty", "current"], array_map(fn($k) => translate($k, $i18n), ["price_history_since", "price", "change", "delete", "price_history_before", "price_history_total", "price_history_empty", "price_history_current"])))) ?>">
  <summary><i class="fa-solid fa-chart-line"></i> <?= translate('price_history', $i18n) ?></summary>
  <div class="price-history-list"></div>
  <div class="price-history-add">
    <input type="date" class="price-history-date" max="<?= date('Y-m-d') ?>" aria-label="<?= translate('price_history_since', $i18n) ?>">
    <input type="number" class="price-history-price" step="0.01" min="0.01" placeholder="<?= translate('price', $i18n) ?>" aria-label="<?= translate('price', $i18n) ?>">
    <button type="button" class="button thin" onclick="priceHistoryAdd(this)"><i class="fa-solid fa-circle-plus"></i> <?= translate('add', $i18n) ?></button>
  </div>
  <small class="loan-anchor-hint"><?= translate('price_history_hint', $i18n) ?></small>
</details>
<script src="scripts/price_history.js?<?= $assetVersion ?? '' ?>"></script>
