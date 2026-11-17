<?php
// CSV for the tax return: premiums of the insurances marked tax-deductible in a year,
// with the section of the return they belong to.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/insurance_insights.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(403);
    exit;
}
$year = (int) ($_GET['year'] ?? (int) date('Y') - 1);
if ($year < 2000 || $year > (int) date('Y')) {
    $year = (int) date('Y') - 1;
}
$from = "$year-01-01";
$to = ($year + 1) . '-01-01';

$stmt = $db->prepare('SELECT i.*, c.code AS currency FROM insurances i LEFT JOIN currencies c ON c.id = i.currency_id
    WHERE i.user_id = :userId AND i.tax_deductible = 1 ORDER BY i.insurance_type, i.name');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="versicherungen-steuer-' . $year . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for spreadsheet programs
fputcsv($out, [translate('tax_section', $i18n), translate('name', $i18n), translate('insurer', $i18n), translate('policy_number', $i18n),
    translate('insurance_type', $i18n), sprintf(translate('premiums_in_year', $i18n), $year), translate('currency', $i18n)], ';');
$totals = [];
while ($insurance = $result->fetchArray(SQLITE3_ASSOC)) {
    $history = [];
    $h = $db->prepare("SELECT id, old_price, new_price, changed_at FROM price_history WHERE item_type = 'insurance' AND item_id = :id AND user_id = :userId");
    $h->bindValue(':id', $insurance['id'], SQLITE3_INTEGER);
    $h->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $hr = $h->execute();
    while ($row = $hr->fetchArray(SQLITE3_ASSOC)) {
        $history[] = $row;
    }
    $start = max($from, $insurance['start_date'] ?: $from);
    $end = $insurance['archived'] && !empty($insurance['archived_at']) ? min($to, $insurance['archived_at']) : $to;
    $premium = $end > $start ? insurancePremiumsBetween($insurance, insurancePricePeriods($insurance, $history), $start, $end) : 0.0;
    if ($premium <= 0) {
        continue;
    }
    $section = translate(insuranceTaxCategory($insurance['insurance_type']), $i18n);
    $totals[$section] = ($totals[$section] ?? 0) + $premium;
    $type = translate('insurance_type_' . $insurance['insurance_type'], $i18n);
    fputcsv($out, [$section, html_entity_decode($insurance['name']), html_entity_decode((string) $insurance['insurer']), html_entity_decode((string) $insurance['policy_number']),
        $type, number_format($premium, 2, ',', ''), $insurance['currency']], ';');
}
fputcsv($out, [], ';');
foreach ($totals as $section => $sum) {
    fputcsv($out, [$section, translate('total', $i18n), '', '', '', number_format($sum, 2, ',', ''), ''], ';');
}
fclose($out);
$db->close();
