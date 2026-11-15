<?php
// Global search over every area and the uploaded documents.
require_once 'includes/header.php';

$q = trim((string) ($_GET['q'] ?? ''));
require_once 'includes/global_search.php';
$hits = array_map(fn($h) => [$h['icon'], $h['href'], $h['name'], $h['info']], globalSearch($db, (int) $userId, $q));
?>

<section class="contain">
  <h2><?= translate('search', $i18n) ?></h2>
  <form method="get" class="form-group-inline">
    <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" autofocus minlength="2"
      placeholder="<?= translate('search_placeholder', $i18n) ?>">
    <input type="submit" class="button thin" value="<?= translate('search', $i18n) ?>">
  </form>
  <?php if ($q !== ''): ?>
    <ul class="savings-list">
      <?php foreach ($hits as [$icon, $href, $name, $info]): ?>
        <li><a href="<?= htmlspecialchars($href) ?>"><i class="fa-solid <?= $icon ?>"></i> <?= htmlspecialchars($name) ?></a>
          <?php if ($info !== ''): ?><small> · <?= htmlspecialchars(mb_substr($info, 0, 80)) ?></small><?php endif; ?></li>
      <?php endforeach; ?>
      <?php if (!$hits): ?><li><?= translate('search_no_results', $i18n) ?></li><?php endif; ?>
    </ul>
  <?php endif; ?>
</section>

<?php require_once 'includes/footer.php'; ?>
