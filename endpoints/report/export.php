<?php
// CSV export of every running cost (Excel, tax advisor). GET link, so no CSRF; needs a login.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/household_costs.php';

if (empty($userId)) {
    http_response_code(403);
    exit;
}

$labels = ['subscription' => 'subscriptions', 'contract' => 'contracts', 'insurance' => 'insurances', 'loan' => 'financing', 'meter' => 'consumption'];
$members = [];
$stmt = $db->prepare('SELECT id, name FROM household WHERE user_id = :userId');
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $members[(int) $row['id']] = $row['name'];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="wallos-kosten-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
$num = fn($value) => number_format($value, 2, ',', '');
// Leading =, +, - or @ would run as a formula in Excel.
$text = fn($value) => preg_match('/^[=+\-@\t\r]/', (string) $value) ? "'" . $value : (string) $value;
fputcsv($out, [translate('category', $i18n), translate('name', $i18n), translate('report_payment', $i18n),
    translate('report_monthly', $i18n), translate('report_yearly', $i18n), translate('report_payer', $i18n), translate('tax_deductible', $i18n)], ';');
foreach (householdCostItems($db, (int) $userId) as $item) {
    fputcsv($out, [translate($labels[$item['type']], $i18n), $text($item['name']), $num($item['amount']), $num($item['monthly']),
        $num($item['monthly'] * 12), $text($members[$item['payer_id']] ?? ''), $item['tax'] ? 'x' : ''], ';');
}
fclose($out);
