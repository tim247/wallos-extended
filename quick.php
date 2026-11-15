<?php
// Mobile quick capture: a meter reading or a photo of a document, in a few taps.
require_once 'includes/header.php';

$rows = function (string $sql) use ($db, $userId): array {
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $out = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $out[] = $row;
    }
    return $out;
};
$meters = $rows("SELECT m.id, m.name, m.unit, (SELECT reading_value FROM meter_readings r WHERE r.meter_id = m.id ORDER BY reading_date DESC LIMIT 1) AS last
    FROM meters m WHERE m.user_id = :userId AND m.archived = 0 AND m.meter_type != 'pv_feed_in' ORDER BY m.name");
// Forecast per meter from the recent consumption (scripts/reading-forecast.js).
require_once 'includes/consumption_calculations.php';
foreach ($meters as &$meter) {
    $readings = $rows('SELECT reading_date, reading_value, is_meter_change FROM meter_readings WHERE meter_id = ' . (int) $meter['id'] . ' AND user_id = :userId ORDER BY reading_date');
    $forecast = computeForecast(computeConsumptionSegments($readings)['segments'], date('Y-m-d'));
    $meter['last_date'] = $readings ? end($readings)['reading_date'] : '';
    $meter['per_day'] = $forecast['per_day'] ?? '';
}
unset($meter);
$targets = array_merge(
    array_map(fn($r) => ['contract', $r['id'], $r['name']], $rows('SELECT id, name FROM contracts WHERE user_id = :userId AND archived = 0 ORDER BY name')),
    array_map(fn($r) => ['insurance', $r['id'], $r['name']], $rows('SELECT id, name FROM insurances WHERE user_id = :userId AND archived = 0 ORDER BY name')),
    array_map(fn($r) => ['loan', $r['id'], $r['name']], $rows('SELECT id, name FROM loans WHERE user_id = :userId AND archived = 0 ORDER BY name')),
);
?>

<section class="contain quick-capture">
  <h2><?= translate('quick_capture', $i18n) ?></h2>

  <?php if ($meters): ?>
    <form class="quick-card" onsubmit="return quickReading(this)">
      <h3><i class="fa-solid fa-gauge"></i> <?= translate('meter_reading', $i18n) ?></h3>
      <select name="meter_id" required>
        <?php foreach ($meters as $m): ?>
          <option value="<?= $m['id'] ?>" data-last-value="<?= $m['last'] ?? '' ?>" data-last-date="<?= htmlspecialchars($m['last_date']) ?>"
            data-per-day="<?= $m['per_day'] ?>"><?= htmlspecialchars($m['name']) ?><?= $m['last'] !== null ? ' (' . translate('last_reading', $i18n) . ' ' . $m['last'] . ' ' . htmlspecialchars($m['unit']) . ')' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="reading_value" inputmode="decimal" autocomplete="off" pattern="[0-9]+([.,][0-9]+)?" required
        placeholder="<?= translate('meter_reading', $i18n) ?>" title="<?= translate('reading_forecast_hint', $i18n) ?>">
      <input type="date" name="reading_date" value="<?= date('Y-m-d') ?>" required>
      <input type="submit" class="button" value="<?= translate('save', $i18n) ?>">
    </form>
  <?php endif; ?>

  <?php if ($targets): ?>
    <form class="quick-card" onsubmit="return quickPhoto(this)">
      <h3><i class="fa-solid fa-camera"></i> <?= translate('quick_photo', $i18n) ?></h3>
      <select name="target" required>
        <?php foreach ($targets as [$type, $id, $name]): ?>
          <option value="<?= $type ?>:<?= $id ?>"><?= htmlspecialchars($name) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="file" name="file" accept="image/*,application/pdf" capture="environment" required>
      <input type="submit" class="button" value="<?= translate('upload_file', $i18n) ?>">
    </form>
  <?php endif; ?>

  <?php if (!$meters && !$targets): ?>
    <p class="settings-notes"><?= translate('quick_capture_empty', $i18n) ?></p>
  <?php endif; ?>
</section>

<script src="scripts/reading-forecast.js?<?= $assetVersion ?>"></script>
<script>
  function quickDone(form, data) {
    if (data.success) {
      showSuccessMessage(data.message || translate('success'));
      form.querySelectorAll('input[type=number], input[type=file]').forEach(input => input.value = '');
    } else {
      showErrorMessage(data.message || translate('unknown_error'));
    }
  }
  // Forecast for the selected meter, as on the consumption page.
  const readingForm = document.querySelector('form[onsubmit^="return quickReading"]');
  if (readingForm) {
    const selected = () => readingForm.meter_id.selectedOptions[0];
    const forecast = () => readingForecastFromData(selected(), readingForm.reading_date)();
    const redraw = attachReadingForecast(readingForm.reading_value, forecast);
    readingForm.meter_id.addEventListener('change', redraw);
    readingForm.reading_date.addEventListener('change', redraw);
  }
  function quickReading(form) {
    const readingValue = form.reading_value.readingValue ? form.reading_value.readingValue() : parseFloat(form.reading_value.value);
    if (Number.isNaN(readingValue)) {
      showErrorMessage(translate('reading_value_missing'));
      return false;
    }
    fetch('endpoints/consumption/add_reading.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
      body: JSON.stringify({ meter_id: form.meter_id.value, reading_value: readingValue, reading_date: form.reading_date.value }),
    }).then(r => r.json()).then(data => {
      quickDone(form, data);
      if (data.success) location.reload(); // new last reading = new forecast
    }).catch(() => showErrorMessage(translate('unknown_error')));
    return false;
  }
  function quickPhoto(form) {
    const [type, id] = form.target.value.split(':');
    const body = new FormData();
    body.append('type', type);
    body.append('item_id', id);
    body.append('file', form.file.files[0]);
    fetch('endpoints/files/upload.php', { method: 'POST', headers: { 'X-CSRF-Token': window.csrfToken }, body })
      .then(r => r.json()).then(data => quickDone(form, data)).catch(() => showErrorMessage(translate('unknown_error')));
    return false;
  }
</script>

<?php require_once 'includes/footer.php'; ?>
