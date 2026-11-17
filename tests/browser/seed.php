<?php
// Sample data for the browser smoke test (CI only): the registered test account gets
// subscriptions, contracts, insurances, loans, meters with readings, inventory, an emergency
// entry and a read-only login. Usage: php tests/browser/seed.php <username> <readonly-password>
if (PHP_SAPI !== 'cli' || $argc < 3) {
    exit(1);
}
$db = new SQLite3(getenv('WALLOS_DB') ?: __DIR__ . '/../../db/wallos.db'); // WALLOS_DB: a copy for local tries
$db->busyTimeout(5000);
$username = SQLite3::escapeString($argv[1]);
$u = (int) $db->querySingle("SELECT id FROM user WHERE username = '$username'");
if (!$u) {
    fwrite(STDERR, "user not found\n");
    exit(1);
}
$c = (int) $db->querySingle("SELECT main_currency FROM user WHERE id = $u");
$m = (int) $db->querySingle("SELECT id FROM household WHERE user_id = $u ORDER BY id LIMIT 1");
$db->exec("INSERT INTO household (name, user_id) VALUES ('Partner', $u)");
$pm = "(SELECT id FROM payment_methods WHERE user_id = $u LIMIT 1)";
$cat = "(SELECT id FROM categories WHERE user_id = $u LIMIT 1)";
for ($i = 1; $i <= 12; $i++) {
    $db->exec("INSERT INTO subscriptions (name, price, currency_id, next_payment, cycle, frequency, user_id, payer_user_id, payment_method_id, category_id, notify, inactive, trial_end)
        VALUES ('Abo mit einem recht langen Namen $i', " . (4 + $i) . ", $c, date('now', '+$i days'), 3, 1, $u, $m, $pm, $cat, 0, 0, " . ($i === 1 ? "date('now', '+5 days')" : 'NULL') . ")");
}
for ($i = 1; $i <= 5; $i++) {
    $db->exec("INSERT INTO contracts (user_id, name, provider, price, currency_id, cycle, frequency, payer_user_id, start_date)
        VALUES ($u, 'Vertrag $i', 'Anbieter mit langem Namen $i', " . (20 + $i) . ", $c, 3, 1, $m, '2024-01-01')");
}
foreach (['liability', 'household', 'car', 'health', 'disability'] as $i => $type) {
    $db->exec("INSERT INTO insurances (user_id, name, insurer, insurance_type, price, currency_id, cycle, frequency, payer_user_id, start_date, coverage_amount)
        VALUES ($u, 'Versicherung $type', 'Versicherer $i', '$type', " . (10 + $i * 9) . ", $c, 3, 1, $m, '2022-01-01', " . ($type === 'disability' ? 1200 : 'NULL') . ")");
}
$db->exec("INSERT INTO insurance_claims (user_id, insurance_id, claim_date, description, amount_claimed, status)
    VALUES ($u, (SELECT id FROM insurances WHERE user_id = $u LIMIT 1), '2025-03-01', 'Wasserschaden', 800, 'reported')");
$db->exec("INSERT INTO loans (user_id, name, lender, principal, interest_rate, monthly_rate, start_date, currency_id, loan_type, extra_allowance_pct)
    VALUES ($u, 'Hausdarlehen', 'Bank', 200000, 3.1, 950, '2023-01-01', $c, 'annuity', 5), ($u, 'Autokredit', 'Bank', 20000, 5.9, 400, '2025-01-01', $c, 'annuity', 0)");
$db->exec("INSERT INTO meters (user_id, name, meter_type, unit, price_per_unit, base_fee_monthly, currency_id) VALUES ($u, 'Strom', 'electricity', 'kWh', 0.34, 12, $c)");
$meter = $db->lastInsertRowID();
$value = 1000.0;
for ($d = strtotime('-400 days'); $d <= time(); $d += 7 * 86400) {
    $db->exec(sprintf("INSERT OR IGNORE INTO meter_readings (meter_id, user_id, reading_date, reading_value) VALUES (%d, %d, '%s', %.1f)", $meter, $u, date('Y-m-d', $d), $value));
    $value += 60;
}
$db->exec("INSERT INTO inventory (user_id, name, purchase_date, price, warranty_until) VALUES ($u, 'Waschmaschine', '2025-01-10', 699, date('now', '+20 days'))");
$db->exec("INSERT INTO emergency_entries (user_id, section, name, info, detail, updated_at) VALUES ($u, 'contacts', 'Hausarzt', '030 1234567', '', date('now'))");
$db->exec("INSERT INTO user (username, email, password, main_currency, language, readonly_of)
    VALUES ('" . $username . "_read', 'read@example.test', '" . password_hash($argv[2], PASSWORD_DEFAULT) . "', $c, 'de', $u)");
echo "seeded user $u\n";
