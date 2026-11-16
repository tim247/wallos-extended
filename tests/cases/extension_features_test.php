<?php
// Wallos Extended features: change log, price history, budgets, insurance check,
// savings goals, CSV import and the read-only access list.

require_once WALLOS_ROOT . '/includes/import_helpers.php';
require_once WALLOS_ROOT . '/includes/readonly_account.php';
// finances.php and household_costs.php load the real price helpers, so they are required inside/n// the tests: cancellation_notifications_test stubs getPriceConverted() when it is not defined yet./n
function extension_insert(SQLite3 $db, string $sql): int
{
    assert_true($db->exec($sql), 'insert: ' . $db->lastErrorMsg());
    return $db->lastInsertRowID();
}

wallos_test('the change log records added, changed and deleted entries, not cron updates', function () {
    $db = wallos_test_open_database();
    $id = extension_insert($db, "INSERT INTO contracts (user_id, name, price) VALUES (1, 'Gym', 20)");
    $db->exec("UPDATE contracts SET price = 25 WHERE id = $id");
    $db->exec("UPDATE contracts SET last_notified_deadline = '2026-01-01' WHERE id = $id"); // not tracked
    $db->exec("DELETE FROM contracts WHERE id = $id");
    $sub = extension_insert($db, "INSERT INTO subscriptions (user_id, name, price, next_payment) VALUES (1, 'Netflix', 9.99, '2026-01-01')");
    $db->exec("UPDATE subscriptions SET next_payment = '2026-02-01' WHERE id = $sub"); // moved by the cron job

    $rows = [];
    $result = $db->query('SELECT item_type, action, detail FROM audit_log ORDER BY id');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row['item_type'] . ':' . $row['action'];
    }
    assert_same(['contract:add', 'contract:edit', 'contract:delete', 'subscription:add'], $rows, 'logged actions');
    assert_same('price: 20.0 → 25.0; ', $db->querySingle("SELECT detail FROM audit_log WHERE action = 'edit'"), 'edit detail');
});

wallos_test('price changes are recorded in the price history', function () {
    $db = wallos_test_open_database();
    $id = extension_insert($db, "INSERT INTO insurances (user_id, name, price) VALUES (1, 'Haftpflicht', 60)");
    $db->exec("UPDATE insurances SET price = 66 WHERE id = $id");
    $db->exec("UPDATE insurances SET name = 'Privathaftpflicht' WHERE id = $id"); // no price change
    $row = $db->querySingle('SELECT item_type, old_price, new_price, notified FROM price_history', true);
    assert_same(['item_type' => 'insurance', 'old_price' => 60.0, 'new_price' => 66.0, 'notified' => 0], $row, 'history row');
    assert_same(1, $db->querySingle('SELECT COUNT(*) FROM price_history'), 'one row only');
});

wallos_test('category budgets sum the monthly costs per category', function () {
    require_once WALLOS_ROOT . '/includes/household_costs.php';
    $db = wallos_test_open_database();
    $category = extension_insert($db, "INSERT INTO categories (name, user_id, budget) VALUES ('Streaming', 1, 20)");
    extension_insert($db, "INSERT INTO categories (name, user_id, budget) VALUES ('Ohne Budget', 1, 0)");
    $items = [
        ['category_id' => $category, 'monthly' => 12.5],
        ['category_id' => $category, 'monthly' => 9.0],
        ['category_id' => 0, 'monthly' => 100.0],
    ];
    assert_same([['name' => 'Streaming', 'budget' => 20.0, 'spent' => 21.5]], categoryBudgets($db, 1, $items), 'budgets');
});

wallos_test('the insurance check asks for basics only when insurances are tracked', function () {
    require_once WALLOS_ROOT . '/includes/household_costs.php';
    $db = wallos_test_open_database();
    assert_same([], missingInsurances($db, 1), 'nothing tracked, nothing asked');
    extension_insert($db, "INSERT INTO insurances (user_id, name, insurance_type) VALUES (1, 'Haftpflicht', 'liability')");
    assert_same(['household'], missingInsurances($db, 1), 'household missing');
    extension_insert($db, "INSERT INTO loans (user_id, name, principal, interest_rate, monthly_rate, start_date) VALUES (1, 'Haus', 200000, 3, 900, '2024-01-01')");
    assert_same(['household', 'building'], missingInsurances($db, 1), 'a loan suggests owned property');
});

wallos_test('savings goals compute the monthly amount still needed', function () {
    require_once WALLOS_ROOT . '/includes/finances.php';
    $db = wallos_test_open_database();
    extension_insert($db, "INSERT INTO savings_goals (user_id, name, target_amount, saved_amount, target_date) VALUES (1, 'Auto', 12000, 2000, '2027-11-01')");
    extension_insert($db, "INSERT INTO savings_goals (user_id, name, target_amount, saved_amount, target_date) VALUES (1, 'Urlaub', 1000, 1000, '2027-06-01')");
    extension_insert($db, "INSERT INTO savings_goals (user_id, name, target_amount, saved_amount) VALUES (1, 'Puffer', 3000, 500)");
    $goals = array_column(financeGoals($db, 1, '2026-11-01'), null, 'name');
    assert_same(12, $goals['Auto']['months_left'], 'months left');
    assert_same(833.33, $goals['Auto']['monthly_needed'], 'monthly needed');
    assert_same(0, $goals['Urlaub']['monthly_needed'], 'reached goal needs nothing');
    assert_same(1.0, (float) $goals['Urlaub']['progress'], 'progress');
    assert_same(0, $goals['Puffer']['monthly_needed'], 'no date, no monthly amount');
});

wallos_test('the CSV import understands German and English cycles', function () {
    assert_same([3, 1], importCycle('monatlich'), 'monthly');
    assert_same([3, 1], importCycle(''), 'empty = monthly');
    assert_same([3, 3], importCycle('Vierteljährlich'), 'quarterly');
    assert_same([3, 6], importCycle('halbjährlich'), 'half-yearly');
    assert_same([4, 1], importCycle('jährlich'), 'yearly');
    assert_same([4, 1], importCycle('yearly'), 'yearly en');
    assert_same([2, 1], importCycle('wöchentlich'), 'weekly');
    assert_same(null, importCycle('ab und zu'), 'unknown');
});

wallos_test('read-only accounts reach only reading pages and endpoints', function () {
    foreach (['index.php', 'report.php', 'endpoints/contracts/get.php', 'endpoints/files/download.php'] as $allowed) {
        assert_true(readonlyAllows($allowed), "$allowed should be open");
    }
    foreach (['settings.php', 'profile.php', 'admin.php', 'import.php', 'bankimport.php', 'quick.php',
        'endpoints/contracts/add.php', 'endpoints/admin/deleteuser.php', 'endpoints/db/backup.php',
        'endpoints/settings/readonlyaccounts.php', 'endpoints/report/share.php', 'endpoints/files/upload.php',
        'endpoints/../settings.php', 'contracts/get.php', 'api/finance/get_items.php'] as $denied) {
        assert_true(!readonlyAllows($denied), "$denied should be closed");
    }
});

wallos_test('a read-only account points to its owner', function () {
    $db = wallos_test_open_database();
    $owner = extension_insert($db, "INSERT INTO user (username, email, password, main_currency) VALUES ('owner', 'o@example.com', 'x', 1)");
    $viewer = extension_insert($db, "INSERT INTO user (username, email, password, main_currency, readonly_of) VALUES ('partner', '', 'x', 1, $owner)");
    assert_same($owner, readonlyOwnerOf($db, $viewer), 'owner');
    assert_same(null, readonlyOwnerOf($db, $owner), 'normal account');
});

wallos_test('the budget plan shows each kind of cost with its share of the income', function () {
    require_once WALLOS_ROOT . '/includes/finances.php';
    $incomes = [['monthly' => 3000.0], ['monthly' => 250.0]];
    $costs = [['type' => 'loan', 'monthly' => 900.0], ['type' => 'insurance', 'monthly' => 150.0], ['type' => 'insurance', 'monthly' => 50.0]];
    $lines = array_column(financeBreakdown($incomes, $costs, [['monthly' => 600.0]], [['monthly_needed' => 100.0]]), null, 'key');
    assert_same(['insurance', 'loan', 'variable', 'savings'], array_keys($lines), 'only lines with costs, in order');
    assert_same(200.0, $lines['insurance']['monthly'], 'insurances summed');
    assert_same(round(900 / 3250, 6), round($lines['loan']['share'], 6), 'loan share of income');
});

wallos_test('the outlook lists incomes that end and leaves out ended ones', function () {
    require_once WALLOS_ROOT . '/includes/finances.php';
    $db = wallos_test_open_database();
    $incomes = [
        ['name' => 'Kindergeld', 'monthly' => 255.0, 'end_date' => '2030-08-31'],
        ['name' => 'Gehalt', 'monthly' => 3000.0, 'end_date' => null],
    ];
    assert_same([['date' => '2030-08-31', 'name' => 'Kindergeld', 'delta' => -255.0]], financeOutlook($db, 1, $incomes, '2026-11-15'), 'events');
});

wallos_test('the household split shares all costs in proportion to income', function () {
    require_once WALLOS_ROOT . '/includes/finances.php';
    $db = wallos_test_open_database();
    $anna = extension_insert($db, "INSERT INTO household (name, user_id) VALUES ('Anna', 1)");
    $ben = extension_insert($db, "INSERT INTO household (name, user_id) VALUES ('Ben', 1)");
    $incomes = [['monthly' => 3000.0, 'payer_user_id' => $anna], ['monthly' => 1000.0, 'payer_user_id' => $ben]];
    $costs = [['monthly' => 1000.0, 'payer_id' => $anna], ['monthly' => 600.0, 'payer_id' => 0]];
    $people = array_column(financePerPerson($db, 1, $incomes, $costs, [['monthly' => 400.0, 'payer_user_id' => $ben]]), null, 'name');
    assert_same(1500.0, $people['Anna']['fair'], 'Anna: 3/4 of 2000');
    assert_same(500.0, $people['Ben']['fair'], 'Ben: 1/4 of 2000');
    assert_same(1000.0, $people['Anna']['pays'], 'Anna pays her own costs');
    assert_same(400.0, $people['Ben']['pays'], 'Ben pays the variable expense');
});

wallos_test('the health check reports a stale backup and a stopped cron', function () {
    require_once WALLOS_ROOT . '/includes/system_health.php';
    $db = wallos_test_open_database();
    $dir = WALLOS_TEST_TMP . '/health-' . uniqid();
    mkdir("$dir/logs", 0700, true);
    mkdir("$dir/backups", 0700, true);
    $now = time();
    $keys = fn() => array_column(systemHealthIssues($db, $now, "$dir/logs", "$dir/backups"), 'key');

    assert_same([], $keys(), 'fresh installation: no logs, no backups, no alarm');
    touch("$dir/logs/autobackup.log", $now - 3600);
    assert_same(['health_backup_stale'], $keys(), 'backup job ran but left no backup');
    touch("$dir/backups/wallos-20260101-031500.db", $now - 3600);
    assert_same([], $keys(), 'fresh backup and recent log');
    touch("$dir/backups/wallos-20260101-031500.db", $now - 3 * 86400);
    touch("$dir/logs/autobackup.log", $now - 3 * 86400);
    assert_same(['health_backup_stale', 'health_cron_stale'], $keys(), 'everything older than two days');
    $db->exec('UPDATE admin SET backup_keep = 0');
    assert_same(['health_cron_stale'], $keys(), 'backups switched off');
});

wallos_test('inventory items lose value linearly over their useful life', function () {
    require_once WALLOS_ROOT . '/includes/life_admin.php';
    assert_same(1000.0, inventoryCurrentValue(1000, '2026-01-01', 0, '2030-01-01'), 'no lifetime: purchase price');
    assert_same(1000.0, inventoryCurrentValue(1000, null, 5, '2030-01-01'), 'no date: purchase price');
    assert_same(500.0, round(inventoryCurrentValue(1000, '2024-01-01', 4, '2026-01-01'), -1), 'half the lifetime: half the value');
    assert_same(0.0, inventoryCurrentValue(1000, '2010-01-01', 5, '2026-01-01'), 'never below zero');
});

wallos_test('a done reminder is planned again after its interval', function () {
    require_once WALLOS_ROOT . '/includes/life_admin.php';
    assert_same('2028-11-15', reminderNextDue('2026-11-15', 24), 'TÜV every 24 months');
    assert_same(null, reminderNextDue('2026-11-15', 0), 'one-off: no next date');
    foreach (REMINDER_CATEGORIES as $key => [$kind]) {
        assert_true(in_array($kind, ['document', 'maintenance'], true), "$key has a valid kind");
    }
});

wallos_test('read-only accounts see the household pages but cannot save', function () {
    foreach (['reminders.php', 'inventory.php', 'emergency.php'] as $page) {
        assert_true(readonlyAllows($page), "$page open");
    }
    assert_true(!readonlyAllows('endpoints/life/save.php'), 'life/save.php closed');
});

wallos_test('the inactivity guard counts the days from the last sign-in', function () {
    require_once WALLOS_ROOT . '/includes/deadman.php';
    $armed = ['enabled' => 1, 'days' => 90];
    assert_same(90, deadmanDaysLeft($armed, '2026-11-15', '2026-11-15'), 'seen today');
    assert_same(14, deadmanDaysLeft($armed, '2026-08-31', '2026-11-15'), 'first reminder');
    assert_same(-1, deadmanDaysLeft($armed, '2026-08-16', '2026-11-15'), 'overdue');
    assert_same(null, deadmanDaysLeft(['enabled' => 0, 'days' => 90], '2026-08-16', '2026-11-15'), 'switched off');
    assert_same(null, deadmanDaysLeft($armed, null, '2026-11-15'), 'never seen');
});

wallos_test('signing in again withdraws a sent link, and only valid tokens open the dossier', function () {
    require_once WALLOS_ROOT . '/includes/deadman.php';
    $db = wallos_test_open_database();
    $user = extension_insert($db, "INSERT INTO user (username, email, password, main_currency, last_seen) VALUES ('o', 'o@example.com', 'x', 1, '2026-01-01')");
    $token = str_repeat('ab', 32);
    $db->exec("INSERT INTO deadman_settings (user_id, enabled, days, trusted_email, access_code_hash, triggered_at, access_token, token_expires)
        VALUES ($user, 1, 30, 't@example.com', 'h', '2026-02-01', '$token', date('now', '+30 days'))");
    assert_true(deadmanByToken($db, $token) !== null, 'valid link opens');
    assert_same(null, deadmanByToken($db, str_repeat('cd', 32)), 'unknown token');
    assert_same(null, deadmanByToken($db, "' OR 1=1 --"), 'malformed token');
    deadmanTouch($db, $user, '2026-01-01');
    assert_same(null, deadmanByToken($db, $token), 'link withdrawn after sign-in');
    assert_same(date('Y-m-d'), $db->querySingle("SELECT last_seen FROM user WHERE id = $user"), 'activity stored');
});

wallos_test('Wallos Extended versions compare in release order', function () {
    require_once WALLOS_ROOT . '/includes/version.php';
    assert_same(-1, extVersionCompare('v5.11.1-ext.5', 'v5.11.1-ext.6'), 'ext.5 < ext.6');
    assert_same(-1, extVersionCompare('v5.11.1-ext.9', 'v5.11.1-ext.10'), 'numeric, not text order');
    assert_same(-1, extVersionCompare('v5.11.1', 'v5.11.1-ext.1'), 'base < first extended release');
    assert_same(-1, extVersionCompare('v5.11.1-ext.6', 'v5.12.0-ext.1'), 'new base wins');
    assert_same(0, extVersionCompare('v5.11.1-ext.6', 'v5.11.1-ext.6'), 'same');
    assert_same(1, extVersionCompare('v5.11.1-dev', 'v5.11.1-ext.6'), 'a development build shows no update for its base');
    assert_same(0, extVersionCompare('garbage', 'v5.11.1-ext.6'), 'unknown format: no update shown');
});

wallos_test('email notifications fall back to the admin mail server', function () {
    require_once WALLOS_ROOT . '/includes/instance_config.php';
    $db = wallos_test_open_database();
    $db->exec("UPDATE admin SET smtp_address = 'mail.example.com', smtp_port = 465, encryption = 'ssl', smtp_username = 'u', from_email = 'wallos@example.com'");
    $server = wallos_email_server($db, ['smtp_address' => '', 'from_email' => '', 'other_emails' => 'a@example.com']);
    assert_same('mail.example.com', $server['smtp_address'], 'admin server used');
    assert_same('wallos@example.com', $server['from_email'], 'admin sender used');
    assert_same('a@example.com', $server['other_emails'], 'recipients kept');
    $own = wallos_email_server($db, ['smtp_address' => 'own.example.com', 'from_email' => 'me@example.com']);
    assert_same('own.example.com', $own['smtp_address'], 'own server kept');
});

wallos_test('umlauts in user names survive registration and get repaired', function () {
    foreach (['registration.php', 'endpoints/admin/adduser.php'] as $file) {
        assert_true(strpos(file_get_contents(WALLOS_ROOT . "/$file"), 'htmlentities(') === false, "$file stores umlauts as text");
    }
    $db = wallos_test_open_database();
    $id = extension_insert($db, "INSERT INTO user (username, firstname, email, password, main_currency) VALUES ('j&uuml;rgen', 'J&amp;uuml;rgen &amp;amp; Co', 'j@example.com', 'x', 1)");
    require WALLOS_ROOT . '/migrations/000071.php';
    $row = $db->querySingle("SELECT username, firstname FROM user WHERE id = $id", true);
    assert_same('jürgen', $row['username'], 'entity decoded');
    assert_same('Jürgen &amp; Co', $row['firstname'], 'double encoding repaired, & stays escaped once');
});

wallos_test('exchange rates move to frankfurter.dev, also for new accounts', function () {
    $db = wallos_test_open_database();
    $fixer = extension_insert($db, "INSERT INTO user (username, email, password, main_currency) VALUES ('f', 'f@example.com', 'x', 1)");
    $db->exec("DELETE FROM fixer WHERE user_id = $fixer");
    $db->exec("INSERT INTO fixer (api_key, provider, user_id) VALUES ('oldkey', 0, $fixer)");
    $none = extension_insert($db, "INSERT INTO user (username, email, password, main_currency) VALUES ('n', 'n@example.com', 'x', 1)");
    $db->exec("DELETE FROM fixer WHERE user_id = $none");
    require WALLOS_ROOT . '/migrations/000072.php';
    assert_same(2, $db->querySingle("SELECT provider FROM fixer WHERE user_id = $fixer"), 'Fixer user moved');
    assert_same(2, $db->querySingle("SELECT provider FROM fixer WHERE user_id = $none"), 'user without provider gets one');
    $new = extension_insert($db, "INSERT INTO user (username, email, password, main_currency) VALUES ('w', 'w@example.com', 'x', 1)");
    assert_same(1, $db->querySingle("SELECT COUNT(*) FROM fixer WHERE user_id = $new AND provider = 2"), 'new account gets frankfurter');
});

wallos_test('household members show as coloured tags, readable on any colour', function () {
    require_once WALLOS_ROOT . '/includes/member_tag.php';
    $members = [1 => ['name' => 'Tim', 'color' => '#ffeb3b'], 2 => ['name' => 'Anna', 'color' => '#1e3a8a'], 3 => ['name' => 'Bob', 'color' => 'red;}']];
    assert_true(str_contains(wallos_member_tag($members, 1), 'color: #1a1a1a'), 'dark text on yellow');
    assert_true(str_contains(wallos_member_tag($members, 2), 'color: #fff'), 'white text on navy');
    assert_true(!str_contains(wallos_member_tag($members, 3), 'style'), 'invalid colour ignored');
    assert_same('', wallos_member_tag([1 => $members[1]], 1), 'no tag for a one-person household');
    $db = wallos_test_open_database();
    require WALLOS_ROOT . '/migrations/000073.php';
    assert_same(1, $db->querySingle("SELECT COUNT(*) FROM pragma_table_info('household') WHERE name = 'color'"), 'colour column');
});

wallos_test('the Bausparvertrag closing fee starts the balance below zero', function () {
    require_once WALLOS_ROOT . '/includes/financing_calculations.php';
    $loan = ['principal' => 50000, 'interest_rate' => 0.1, 'monthly_rate' => 200, 'start_date' => '2026-01-15',
        'bausparen_min_savings_pct' => 40, 'bausparen_loan_rate' => 2.5, 'bausparen_loan_monthly' => 300];
    $without = computeBausparSchedule($loan, '2026-01-20');
    $with = computeBausparSchedule($loan + ['bausparen_fee' => 800], '2026-01-20');
    assert_same(-800.0, $with['balance_as_of'], 'balance starts at minus the fee');
    assert_same(-800.0, $with['savings_rows'][0]['balance'], 'first chart point below zero');
    assert_same(0.0, $with['savings_rows'][1]['interest'], 'no interest on a negative balance');
    assert_true(count($with['savings_rows']) - 1 > count($without['savings_rows']), 'the fee delays the payout');
});

wallos_test('a tilgungsfreies Darlehen without repayment rate runs until the Bausparvertrag redeems it', function () {
    require_once WALLOS_ROOT . '/includes/financing_calculations.php';
    $loan = ['principal' => 100000, 'interest_rate' => 3, 'monthly_rate' => 0, 'start_date' => '2024-01-01',
        'term_months' => null, 'interest_only_months' => 0, 'loan_type' => 'interest_only'];
    $s = computeLoanSchedule($loan, [], '2024-06-01', [], [], '2032-05-01');
    assert_same(null, $s['error'], 'no repayment rate needed');
    assert_same('2032-05-01', $s['redeemed_on'], 'redeemed by the Bausparvertrag');
    assert_same(250.0, $s['rows'][0]['payment'], 'interest only before');
    $end = computeLoanSchedule(array_merge($loan, ['term_months' => 60]), [], '2024-06-01');
    $last = end($end['rows']);
    assert_same(0.0, $last['balance'], 'endfällig: repaid at term end');
    assert_same(60, count($end['rows']), 'after exactly the term');
});

wallos_test('meters keep their order when sort_order is introduced', function () {
    $db = wallos_test_open_database();
    $u = extension_insert($db, "INSERT INTO user (username, email, password, main_currency) VALUES ('m', 'm@example.com', 'x', 1)");
    foreach ([['water', 'Wasser'], ['electricity', 'B-Strom'], ['electricity', 'A-Strom']] as [$type, $name]) {
        $db->exec("INSERT INTO meters (user_id, name, meter_type) VALUES ($u, '$name', '$type')");
    }
    require WALLOS_ROOT . '/migrations/000075.php';
    $names = [];
    $r = $db->query("SELECT name FROM meters WHERE user_id = $u ORDER BY sort_order = 0, sort_order, meter_type ASC, name COLLATE NOCASE ASC");
    while ($row = $r->fetchArray(SQLITE3_ASSOC)) $names[] = $row['name'];
    assert_same(['A-Strom', 'B-Strom', 'Wasser'], $names, 'type, then name - as before');
});

wallos_test('consumption costs use the price valid on each day', function () {
    require_once WALLOS_ROOT . '/includes/consumption_insights.php';
    $segments = [['from' => '2025-01-01', 'to' => '2025-03-02', 'delta' => 600, 'days' => 60, 'per_day' => 10]];
    $meter = ['price_per_unit' => 0.40, 'base_fee_monthly' => 0, 'price_valid_from' => '2025-01-31'];
    $tariffs = meterTariffList($meter, [['valid_from' => '2024-01-01', 'price_per_unit' => 0.30, 'base_fee_monthly' => 0]]);
    $cost = costInWindow($segments, $tariffs, '2025-01-01', '2025-03-02', 1.0);
    assert_same(600.0, $cost['units'], 'all units');
    assert_same(210.0, $cost['work'], '30 days at 0.30 + 30 days at 0.40');
    assert_same(0.3, tariffAt($tariffs, '2025-01-15')['price'], 'old price before the change');
    assert_same(0.4, tariffAt($tariffs, '2025-02-01')['price'], 'new price after');
});

wallos_test('consumption insights: year comparison, outliers, benchmark, degree days, PV, bills', function () {
    require_once WALLOS_ROOT . '/includes/consumption_insights.php';
    $years = yearComparison(['2024-01' => 100.0, '2025-01' => 90.0, '2025-02' => 80.0]);
    assert_same([2024, 2025], array_keys($years), 'one row per year');
    assert_same(80.0, $years[2025][2], 'february 2025');
    assert_same(null, $years[2024][2], 'no data stays empty');

    $normal = [];
    for ($d = 0; $d < 120; $d += 10) {
        $normal[] = ['from' => date('Y-m-d', strtotime("2025-01-01 +$d days")), 'to' => date('Y-m-d', strtotime('2025-01-01 +' . ($d + 10) . ' days')), 'delta' => 50, 'days' => 10, 'per_day' => 5];
    }
    assert_same(null, detectOutlier($normal), 'steady use is no outlier');
    $leak = $normal;
    $leak[] = ['from' => '2025-05-01', 'to' => '2025-05-08', 'delta' => 70, 'days' => 7, 'per_day' => 10];
    assert_same(2.0, detectOutlier($leak)['ratio'], 'double the usual use');

    assert_same(3200.0, householdBenchmark(['meter_type' => 'electricity'], 3, 0), '3 persons electricity');
    assert_same(91.2, householdBenchmark(['meter_type' => 'water'], 2, 0), '2 persons water m³');
    assert_same(null, householdBenchmark(['meter_type' => 'gas', 'conversion_factor' => null], 2, 0), 'gas needs the living area');

    $hdd = heatingDegreeDays(['2025-01-01' => 0.0, '2025-01-02' => 20.0, '2025-02-01' => 10.0]);
    assert_same(['2025-01' => 15.0, '2025-02' => 5.0], $hdd, 'only days below 15 °C count');

    $pv = pvBalance(5000, 3000, 2500);
    assert_same(2000.0, $pv['self'], 'self-consumption');
    assert_same(40.0, $pv['self_rate'], 'self-consumption rate');
    assert_same(44.4, $pv['autarky'], 'autarky');
    $amort = pvAmortization(10000, '2023-01-01', 2000, 0.35, 3000, 0.08, '2025-01-01');
    assert_same(940.0, $amort['benefit'], '2000 × 0.35 + 3000 × 0.08');
    assert_same(10.6, $amort['years'], 'payback in years');

    $segments = [['from' => '2024-01-01', 'to' => '2025-01-01', 'delta' => 3660, 'days' => 366, 'per_day' => 10]];
    $tariffs = meterTariffList(['price_per_unit' => 0.30, 'base_fee_monthly' => 0, 'price_valid_from' => null], []);
    $ok = checkBill(['period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'units' => 3660, 'amount' => 1098], $segments, $tariffs, 1.0, true);
    assert_same('ok', $ok['status'], 'bill matches the readings');
    $off = checkBill(['period_from' => '2024-01-01', 'period_to' => '2024-12-31', 'units' => 4300, 'amount' => 1290], $segments, $tariffs, 1.0, true);
    assert_same('deviation', $off['status'], 'bill far above the readings');
    $none = checkBill(['period_from' => '2023-01-01', 'period_to' => '2023-12-31', 'units' => 1, 'amount' => 1], $segments, $tariffs, 1.0, true);
    assert_same('no_data', $none['status'], 'no readings for that period');
});

wallos_test('heating that rises every autumn is no outlier, a rise against last year is', function () {
    require_once WALLOS_ROOT . '/includes/consumption_insights.php';
    $segments = [];
    $perDay = fn(int $month) => in_array($month, [11, 12, 1, 2], true) ? 8.0 : 2.0;
    for ($d = strtotime('2024-08-01'); $d < strtotime('2025-11-10'); $d += 14 * 86400) {
        $p = $perDay((int) date('n', $d));
        $segments[] = ['from' => date('Y-m-d', $d), 'to' => date('Y-m-d', $d + 14 * 86400), 'delta' => $p * 14, 'days' => 14, 'per_day' => $p];
    }
    assert_same(null, detectOutlier($segments), 'november like last november');
    $last = array_pop($segments);
    $last['per_day'] *= 2;
    $last['delta'] *= 2;
    $segments[] = $last;
    assert_true(detectOutlier($segments) !== null, 'twice last november is reported');
});

wallos_test('base load, tariff switch hints and the last-month comparison', function () {
    require_once WALLOS_ROOT . '/includes/consumption_insights.php';
    $seg = fn($from, $days, $perDay) => ['from' => $from, 'to' => date('Y-m-d', strtotime("$from +$days days")), 'delta' => $perDay * $days, 'days' => $days, 'per_day' => $perDay];
    $short = [$seg('2026-01-01', 3, 9.0), $seg('2026-01-04', 3, 7.2), $seg('2026-01-07', 3, 8.0), $seg('2026-01-10', 3, 10.0)];
    assert_same(300, estimateBaseLoad($short, '2026-02-01')['watts'], '7.2 kWh a day = 300 W');
    assert_same(null, estimateBaseLoad([$seg('2026-01-01', 30, 9.0)], '2026-02-01'), 'monthly readings say nothing about the base load');

    $hints = tariffSwitchHints(['price_guarantee_until' => '2026-03-01', 'reference_price' => 0.30, 'price_per_unit' => 0.36], '2026-02-01');
    assert_same(['guarantee', 'expensive'], array_column($hints, 'key'), 'both reasons');
    assert_same(20, $hints[1]['percent'], '20 % above the good offer');
    assert_same([], tariffSwitchHints(['price_guarantee_until' => '2027-01-01', 'reference_price' => 0.35, 'price_per_unit' => 0.36], '2026-02-01'), 'nothing to do');

    $segments = [$seg('2024-12-01', 450, 10.0)];
    $cmp = lastMonthComparison($segments, ['2025-01' => 310.0, '2026-01' => 279.0], '2026-02-15');
    assert_same('2026-01', $cmp['month'], 'last complete month');
    assert_same(-10.0, $cmp['change'], '10 % less than a year before');
    assert_same(null, lastMonthComparison([$seg('2025-12-01', 40, 10.0)], ['2026-01' => 100.0], '2026-02-15'), 'no reading after the month ended');
});
