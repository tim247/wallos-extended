<?php
// CSV import of subscriptions, contracts and insurances (moving over from Excel, Finanzguru exports & co.).
require_once 'includes/header.php';
require_once 'includes/bank_import.php'; // bankParseAmount, bankParseDate
require_once 'includes/import_helpers.php';

$template = "Bereich;Name;Betrag;Turnus;Anbieter;Beginn\nVertrag;Fitnessstudio;29,90;monatlich;FitX;01.01.2025\n"
    . "Versicherung;Haftpflicht;65,00;jährlich;HUK;01.03.2024\nAbo;Netflix;13,99;monatlich;;15.02.2023\n";

$messages = [];
$imported = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['csv'] ?? null;
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $messages[] = translate('error', $i18n);
    } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
        $messages[] = translate('file_upload_failed', $i18n);
    } else {
        $content = (string) file_get_contents($file['tmp_name']);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\r|\n/', preg_replace('/^\xEF\xBB\xBF/', '', $content));
        $delimiter = substr_count($lines[0] ?? '', ';') >= substr_count($lines[0] ?? '', ',') ? ';' : ',';
        $mainCurrency = (int) $main_currency;
        $one = fn(string $sql) => $db->querySingle(str_replace(':userId', (string) (int) $userId, $sql));
        $paymentMethod = (int) ($one('SELECT MIN(id) FROM payment_methods WHERE user_id = :userId') ?: 1);
        $payer = (int) ($one('SELECT MIN(id) FROM household WHERE user_id = :userId') ?: 1);

        $db->exec('BEGIN');
        foreach (array_slice($lines, 1) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
            [$area, $name, $amount, $cycleText, $provider, $start] = array_pad($cells, 6, '');
            $area = mb_strtolower($area);
            $table = match (true) {
                str_starts_with($area, 'abo') || str_starts_with($area, 'sub') => 'subscriptions',
                str_starts_with($area, 'vertr') || str_starts_with($area, 'contr') => 'contracts',
                str_starts_with($area, 'vers') || str_starts_with($area, 'ins') => 'insurances',
                default => null,
            };
            $price = bankParseAmount($amount);
            $cycle = importCycle($cycleText);
            $startDate = $start !== '' ? bankParseDate($start) : date('Y-m-d');
            if (!$table || $name === '' || $price === null || !$cycle || !$startDate) {
                $messages[] = sprintf(translate('import_line_skipped', $i18n), $number + 2, $line);
                continue;
            }
            $name = mb_substr(strip_tags($name), 0, 200);
            $provider = mb_substr(strip_tags($provider), 0, 200);
            $sql = match ($table) {
                'subscriptions' => 'INSERT INTO subscriptions (user_id, name, price, currency_id, next_payment, start_date, cycle, frequency, payment_method_id, payer_user_id, category_id, notes, auto_renew)
                    VALUES (:userId, :name, :price, :currency, :start, :start, :cycle, :frequency, :payment, :payer, 1, :provider, 1)',
                'contracts' => 'INSERT INTO contracts (user_id, name, provider, price, currency_id, start_date, cycle, frequency, payment_method_id, payer_user_id)
                    VALUES (:userId, :name, :provider, :price, :currency, :start, :cycle, :frequency, :payment, :payer)',
                'insurances' => 'INSERT INTO insurances (user_id, name, insurer, price, currency_id, start_date, cycle, frequency, payment_method_id, payer_user_id)
                    VALUES (:userId, :name, :provider, :price, :currency, :start, :cycle, :frequency, :payment, :payer)',
            };
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':provider', $provider);
            $stmt->bindValue(':price', abs($price), SQLITE3_FLOAT);
            $stmt->bindValue(':currency', $mainCurrency, SQLITE3_INTEGER);
            $stmt->bindValue(':start', $startDate);
            $stmt->bindValue(':cycle', $cycle[0], SQLITE3_INTEGER);
            $stmt->bindValue(':frequency', $cycle[1], SQLITE3_INTEGER);
            $stmt->bindValue(':payment', $paymentMethod, SQLITE3_INTEGER);
            $stmt->bindValue(':payer', $payer, SQLITE3_INTEGER);
            if ($stmt->execute()) {
                $imported++;
            }
        }
        $db->exec('COMMIT');
    }
}
?>

<section class="contain bank-import">
  <h2><?= translate('import_csv', $i18n) ?></h2>
  <p class="yearly-intro"><?= translate('import_csv_intro', $i18n) ?></p>
  <pre class="import-template"><?= htmlspecialchars($template) ?></pre>
  <p><a href="data:text/csv;charset=utf-8,<?= rawurlencode("\xEF\xBB\xBF" . $template) ?>" download="wallos-vorlage.csv">
    <i class="fa-solid fa-download"></i> <?= translate('import_template', $i18n) ?></a></p>

  <form method="post" enctype="multipart/form-data" class="form-group-inline">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
    <input type="file" name="csv" accept=".csv,.txt" required>
    <input type="submit" class="button thin" value="<?= translate('import', $i18n) ?>">
  </form>

  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    <p><i class="fa-solid fa-circle-check"></i> <?= sprintf(translate('import_done', $i18n), $imported) ?></p>
    <?php foreach ($messages as $message): ?>
      <p class="error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($message) ?></p>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php require_once 'includes/footer.php'; ?>
