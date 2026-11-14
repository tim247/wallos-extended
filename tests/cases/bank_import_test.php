<?php
// Bank statement import: parsing German exports and comparing with recorded costs.

require_once WALLOS_ROOT . '/includes/bank_import.php';

wallos_test('amounts in German and English notation are parsed', function () {
    assert_same(-1234.56, bankParseAmount('-1.234,56'), 'value');
    assert_same(-12.99, bankParseAmount('-12,99 €'), 'value');
    assert_same(1234.56, bankParseAmount('1,234.56'), 'value');
    assert_same(null, bankParseAmount(''), 'value');
});

wallos_test('a Sparkasse-style export with preamble and Windows-1252 encoding is read', function () {
    $csv = "Kontoauszug;;\n\n"
        . '"Auftragskonto";"Buchungstag";"Valutadatum";"Buchungstext";"Verwendungszweck";"Begünstigter/Zahlungspflichtiger";"Betrag";"Währung"' . "\n"
        . '"DE1";"01.10.26";"01.10.26";"LASTSCHRIFT";"Mobilfunk Oktober";"Vodafone GmbH";"-39,99";"EUR"' . "\n"
        . '"DE1";"02.10.26";"02.10.26";"GUTSCHRIFT";"Gehalt";"Arbeitgeber";"2.500,00";"EUR"' . "\n";
    $rows = bankParseCsv(mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'));
    assert_same(2, count($rows), 'value');
    assert_same(['date' => '2026-10-01', 'amount' => -39.99, 'payee' => 'Vodafone GmbH', 'text' => 'LASTSCHRIFT Mobilfunk Oktober'], $rows[0], 'value');
});

wallos_test('debits are matched by name, price changes and unrecorded recurring payments are found', function () {
    $bookings = [
        ['date' => '2026-09-01', 'amount' => -39.99, 'payee' => 'Vodafone GmbH', 'text' => ''],
        ['date' => '2026-10-01', 'amount' => -44.99, 'payee' => 'Vodafone GmbH', 'text' => ''],
        ['date' => '2026-09-15', 'amount' => -9.99, 'payee' => 'Spotify AB', 'text' => ''],
        ['date' => '2026-10-15', 'amount' => -9.99, 'payee' => 'Spotify AB', 'text' => ''],
        ['date' => '2026-10-03', 'amount' => -55.00, 'payee' => 'Supermarkt', 'text' => ''],
    ];
    $items = [
        ['type' => 'contract', 'id' => 1, 'name' => 'Handy', 'match' => 'Handy Vodafone', 'amount' => 44.99, 'monthly' => 44.99, 'payer_id' => 0],
        ['type' => 'subscription', 'id' => 2, 'name' => 'Netflix', 'match' => 'Netflix', 'amount' => 12.99, 'monthly' => 12.99, 'payer_id' => 0],
        ['type' => 'insurance', 'id' => 3, 'name' => 'Haftpflicht', 'match' => 'Haftpflicht Vodafone', 'amount' => 30.00, 'monthly' => 30.00, 'payer_id' => 0],
    ];
    $result = bankCompare($bookings, $items);
    assert_same(2, count($result['matched']), 'value');
    assert_same(false, $result['matched'][0]['changed'], 'closest amount wins'); // amount (44.99) wins
    assert_same(true, $result['matched'][1]['changed'], 'value');
    assert_same('Netflix', $result['missing'][0]['name'], 'value');
    assert_same(1, count($result['untracked']), 'value');
    assert_same('Spotify AB', $result['untracked'][0]['payee'], 'value');
    assert_same('2026-09-01', $result['from'], 'value');
});
