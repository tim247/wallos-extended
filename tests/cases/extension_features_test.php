<?php
// Wallos Extended features: change log, price history, budgets, insurance check,
// savings goals, CSV import and the read-only access list.

require_once WALLOS_ROOT . '/includes/import_helpers.php';
require_once WALLOS_ROOT . '/includes/readonly_account.php';
// finances.php and household_costs.php load the real price helpers, so they are required inside
// the tests: cancellation_notifications_test stubs getPriceConverted() when it is not defined yet.

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

wallos_test('the Bausparvertrag replaces the loan at the end of the loan term', function () {
    require_once WALLOS_ROOT . '/includes/financing_calculations.php';
    $loan = ['principal' => 100000, 'interest_rate' => 3, 'monthly_rate' => 0, 'start_date' => '2024-01-15',
        'term_months' => 120, 'interest_only_months' => 0, 'loan_type' => 'interest_only'];
    assert_same('2034-01-15', loanTermEnd($loan), 'start + 120 months');
    $schedule = computeLoanSchedule($loan, [], '2025-01-01', [], [], loanTermEnd($loan));
    assert_same('2034-01-15', $schedule['redeemed_on'], 'redeemed at the end of the term');
    assert_true(!empty(end($schedule['rows'])['redemption']), 'the last row is the redemption');

    $bauspar = ['principal' => 100000, 'interest_rate' => 0.1, 'monthly_rate' => 300, 'start_date' => '2024-01-15',
        'bausparen_min_savings_pct' => 40, 'bausparen_loan_rate' => 2, 'bausparen_loan_monthly' => 600];
    $byQuota = computeBausparSchedule($bauspar, '2025-01-01');
    $byTerm = computeBausparSchedule($bauspar, '2025-01-01', '2034-01-15');
    assert_same('2034-01-15', $byTerm['payout_date'], 'payout on the term end');
    assert_true($byQuota['payout_date'] !== $byTerm['payout_date'], 'not the quota date any more');
    assert_same(120, count($byTerm['savings_rows']), 'saving until then');
    assert_true(abs($byTerm['loan_amount'] - (100000 - $byTerm['accumulated_savings'])) < 0.01, 'loan = sum - savings at payout');
});

wallos_test('an announced price applies on its date, dated in the price history', function () {
    require_once WALLOS_ROOT . '/includes/pending_prices.php';
    $db = wallos_test_open_database();
    require WALLOS_ROOT . '/migrations/000078.php';
    $user = extension_insert($db, "INSERT INTO user (username, email, password, main_currency) VALUES ('p', 'p@example.com', 'x', 1)");
    $id = extension_insert($db, "INSERT INTO insurances (user_id, name, price, currency_id, cycle, frequency, pending_price, pending_price_date)
        VALUES ($user, 'Hausrat', 10, 1, 3, 1, 12.5, '2027-01-01')");
    assert_same('2027-01-01', announcedPriceIncreases($db, $user, '2026-11-17')[0]['changed_at'], 'announced on the dashboard');
    assert_same(0, applyPendingPrices($db, $user, '2026-12-31'), 'not yet due');
    assert_same(10.0, $db->querySingle("SELECT price FROM insurances WHERE id = $id"), 'old price until then');
    assert_same(1, applyPendingPrices($db, $user, '2027-01-03'), 'due - applied a few days late');
    assert_same(12.5, $db->querySingle("SELECT price FROM insurances WHERE id = $id"), 'new price');
    assert_same(null, $db->querySingle("SELECT pending_price FROM insurances WHERE id = $id"), 'announcement cleared');
    assert_same('2027-01-01', $db->querySingle("SELECT changed_at FROM price_history WHERE item_type = 'insurance' AND item_id = $id"), 'history dated on the announced day');
    assert_same([null, null], pendingPriceFromRequest(['pending_price' => '12', 'pending_price_date' => '']), 'a price without a date is ignored');
});

wallos_test('health insurance has no cancellation deadline, a normal one keeps it', function () {
    require_once WALLOS_ROOT . '/includes/cancellation_helpers.php';
    $item = ['start_date' => '2024-01-01', 'initial_term_months' => 12, 'renewal_term_months' => 12, 'auto_renews' => 1, 'notice_period_months' => 3];
    assert_true(getNextCancellationDeadline($item + ['cancel_mode' => 'cancellable'], '2026-06-01') !== null, 'cancellable: deadline');
    assert_same(null, getNextCancellationDeadline($item + ['cancel_mode' => 'switch_only'], '2026-06-01'), 'switch only: none');
    assert_same(null, getNextCancellationDeadline($item + ['cancel_mode' => 'not_cancellable'], '2026-06-01'), 'not cancellable: none');
    assert_true(getNextCancellationDeadline($item, '2026-06-01') !== null, 'contracts (no column) unchanged');
    $db = wallos_test_open_database();
    $db->exec("INSERT INTO insurances (user_id, name, price, currency_id, cycle, frequency, insurance_type) VALUES (1, 'TK', 900, 1, 3, 1, 'health')");
    $db->exec("ALTER TABLE insurances DROP COLUMN cancel_mode");
    require WALLOS_ROOT . '/migrations/000079.php';
    assert_same('switch_only', $db->querySingle("SELECT cancel_mode FROM insurances WHERE name = 'TK'"), 'existing health insurance becomes switch only');
});

wallos_test('an insurance with a 6-year first term and 12-month renewals', function () {
    require_once WALLOS_ROOT . '/includes/cancellation_helpers.php';
    $item = ['start_date' => '2022-01-01', 'initial_term_months' => 72, 'renewal_term_months' => 12, 'auto_renews' => 1, 'notice_period_months' => 3, 'cancel_mode' => 'cancellable'];
    $first = getNextCancellationDeadline($item, '2026-11-17');
    assert_same('2028-01-01', $first['term_end'], 'first term ends after 6 years');
    assert_same('2027-10-01', $first['deadline'], '3 months before');
    $later = getNextCancellationDeadline($item, '2028-03-01');
    assert_same('2029-01-01', $later['term_end'], 'then yearly');
});

wallos_test('insurance insights: premiums paid, balance, development, gaps, reminders, documents', function () {
    require_once WALLOS_ROOT . '/includes/insurance_insights.php';
    $ins = ['start_date' => '2024-01-01', 'price' => 120.0, 'cycle' => 4, 'frequency' => 1, 'payer_user_id' => 1, 'insurance_type' => 'household', 'insured_persons' => ''];
    $history = [['id' => 1, 'old_price' => 100.0, 'new_price' => 120.0, 'changed_at' => '2025-01-01']];
    $periods = insurancePricePeriods($ins, $history);
    assert_same(100.0, $periods[0]['price'], 'the first year at the old price');
    $paid = insurancePremiumsBetween($ins, $periods, '2024-01-01', '2026-01-01');
    assert_true(abs($paid - 220.0) < 1.5, 'two years: 100 + 120 (' . $paid . ')');
    $balance = insuranceBalance($ins, $periods, [['status' => 'settled', 'amount_paid' => 300], ['status' => 'reported', 'amount_paid' => 0]], '2026-01-01');
    assert_same(300.0, $balance['benefits'], 'only settled claims count');
    assert_same(1, $balance['open'], 'one open claim');
    $dev = premiumDevelopment($ins, $periods, '2025-06-01');
    assert_same(20.0, $dev[2025]['change'], '+20 % in 2025');
    assert_same(2.2, $dev[2024]['inflation'], 'inflation of 2024');

    $health = ['insurance_type' => 'health', 'insured_persons' => '1,2', 'payer_user_id' => 1];
    $liability = ['insurance_type' => 'liability', 'insured_persons' => '', 'payer_user_id' => 1];
    assert_same([], coverageGaps([1, 2, 3], [$health, $liability]), 'liability covers all, a missing health insurance is not reported');
    assert_same([1 => ['liability'], 2 => ['liability']], coverageGaps([1, 2], [['insurance_type' => 'health', 'insured_persons' => '', 'payer_user_id' => 1]]), 'no liability at all');

    assert_same(13700.0, householdUnderinsurance($ins + ['coverage_amount' => 50000], 98), '98 m² need 63,700 €');
    assert_same(null, householdUnderinsurance($ins + ['coverage_amount' => 70000], 98), 'enough');
    assert_true(insuranceReviewDue(['last_review' => null, 'start_date' => '2023-01-01'], '2026-01-01'), 'never checked, older than 2 years');
    assert_true(!insuranceReviewDue(['last_review' => '2025-03-01', 'start_date' => '2010-01-01'], '2026-01-01'), 'checked last year');
    assert_true(motorSwitchReminderDue(['insurance_type' => 'car', 'kfz_reminded_year' => 2025], '2026-10-15'), 'October: compare motor insurance');
    assert_true(!motorSwitchReminderDue(['insurance_type' => 'car', 'kfz_reminded_year' => 2026], '2026-10-15'), 'once a year');
    assert_same('tax_basic_provision', insuranceTaxCategory('health'), 'health insurance is basic provision');

    $data = extractPolicyData("Versicherungsschein-Nr.: HR-4711/22\nVersicherungsbeginn: 01.03.2024\nVertragslaufzeit 3 Jahre\nJahresbeitrag 189,40 EUR");
    assert_same('HR-4711/22', $data['policy_number'], 'policy number');
    assert_same(189.4, $data['price'], 'premium');
    assert_same('2024-03-01', $data['start_date'], 'start');
    assert_same(36, $data['initial_term_months'], 'term');
    $pdf = "%PDF-1.4\n1 0 obj<</Length 9 /Filter /FlateDecode>>stream\n" . gzcompress("BT (Beitrag 12,50 EUR) Tj ET") . "\nendstream\nendobj";
    assert_true(str_contains(pdfPlainText($pdf), 'Beitrag 12,50 EUR'), 'text from a compressed PDF stream');
});

wallos_test('report a claim or pay it yourself, and the provision check per earner', function () {
    require_once WALLOS_ROOT . '/includes/insurance_insights.php';
    $small = claimWorthReporting(400, 300, 150);
    assert_same(100.0, $small['payout'], 'after the deductible');
    assert_same(450.0, $small['break_even'], 'deductible + refund');
    assert_true(!$small['worth_it'], 'a 400 € claim does not pay off');
    assert_true(claimWorthReporting(2000, 300, 150)['worth_it'], 'a 2,000 € claim does');

    $bu = ['insurance_type' => 'disability', 'insured_persons' => '1', 'payer_user_id' => 1, 'coverage_amount' => 1200];
    $gaps = provisionGaps([1 => 2500, 2 => 1800], [$bu], 2);
    $keys = array_map(fn($g) => $g['key'] . ':' . $g['member'], $gaps);
    assert_same(['provision_bu_low:1', 'provision_no_life:1', 'provision_no_bu:2'], $keys, 'BU too low and no life insurance for the main earner, no BU for the second');
    assert_same(550.0, $gaps[0]['value'], '70 % of 2,500 = 1,750, 1,200 covered');
    assert_same([], provisionGaps([1 => 0], [], 1), 'no income, no gap');
});

wallos_test('planned rate change, extra repayment allowance and the loans overview', function () {
    require_once WALLOS_ROOT . '/includes/financing_calculations.php';
    $loan = ['id' => 1, 'name' => 'Haus', 'principal' => 100000, 'interest_rate' => 3, 'monthly_rate' => 500, 'start_date' => '2025-01-01', 'loan_type' => 'annuity'];
    $plain = computeLoanSchedule($loan, [], '2025-01-01');
    $changed = computeLoanSchedule($loan + ['rate_changes' => [['valid_from' => '2027-01-01', 'monthly_rate' => 800]]], [], '2025-01-01');
    assert_same(500.0, $changed['rows'][0]['payment'], 'old rate before the change');
    $row2027 = current(array_filter($changed['rows'], fn($r) => $r['date'] === '2027-02-01'));
    assert_same(800.0, $row2027['payment'], 'new rate from 2027');
    assert_true($changed['payoff_date'] < $plain['payoff_date'], 'paid off earlier');

    $allowance = extraRepaymentAllowance($loan + ['extra_allowance_pct' => 5], [['payment_date' => '2026-03-01', 'amount' => 2000], ['payment_date' => '2025-03-01', 'amount' => 900]], 2026);
    assert_same(5000.0, $allowance['allowed'], '5 % of 100,000');
    assert_same(3000.0, $allowance['left'], '2,000 used this year');
    assert_same(null, extraRepaymentAllowance($loan, [], 2026), 'no allowance set');

    $car = ['id' => 2, 'name' => 'Auto', 'interest_rate' => 6, 'monthly_rate' => 300, 'loan_type' => 'annuity', 'schedule' => ['error' => null, 'balance_as_of' => 10000, 'payoff_date' => '2028-01-01']];
    $house = $loan + ['schedule' => ['error' => null, 'balance_as_of' => 90000, 'payoff_date' => '2045-01-01']];
    $o = loansOverview([$house, $car]);
    assert_same(100000.0, $o['debt'], 'total debt');
    assert_same(3.3, $o['interest'], 'weighted interest');
    assert_same('2045-01-01', $o['debt_free'], 'debt-free with the last loan');
    assert_same('Auto', $o['priority'][0]['name'], 'highest interest first');
});

wallos_test('emergency dossier: account numbers masked, reviews due, checklist from the data', function () {
    require_once WALLOS_ROOT . '/includes/emergency_entries.php';
    assert_same('Girokonto …3000', emergencyMaskNumbers('Girokonto DE89 3704 0044 0532 0130 00'), 'IBAN keeps the last 4');
    assert_same('Karte …1234', emergencyMaskNumbers('Karte 4111-1111-1111-1234'), 'card number too');
    assert_same('Tel. 0171 1234567', emergencyMaskNumbers('Tel. 0171 1234567'), 'a phone number stays');
    assert_true(emergencyReviewDue('2026-01-01', null, '2026-08-01'), 'checked more than 6 months ago');
    assert_true(!emergencyReviewDue(null, null, '2026-08-01'), 'nothing entered yet');
    assert_same(1, count(emergencyDirectivesDue([['updated_at' => '2023-05-01'], ['updated_at' => '2026-01-01']], '2026-06-01')), 'one directive older than 2 years');
    $i18n = [];
    $list = emergencyChecklist([['name' => 'Risikoleben', 'insurance_type' => 'life'], ['name' => 'Hausrat', 'insurance_type' => 'household']], [['name' => 'Handy']], [['name' => 'Netflix']], [], $i18n);
    assert_same(['Risikoleben'], $list['now'][2]['items'], 'life insurance first');
    assert_same(['Hausrat'], $list['weeks'][2]['items'], 'other insurances later');
    assert_same(['Netflix'], $list['weeks'][4]['items'], 'subscriptions to cancel');
});

wallos_test('the second trusted person has an own link with a limited scope', function () {
    require_once WALLOS_ROOT . '/includes/deadman.php';
    $db = wallos_test_open_database();
    require WALLOS_ROOT . '/migrations/000085.php';
    $user = extension_insert($db, "INSERT INTO user (username, email, password, main_currency, last_seen) VALUES ('d2', 'd2@example.com', 'x', 1, '2026-01-01')");
    $first = str_repeat('a1', 32);
    $second = str_repeat('b2', 32);
    $db->exec("INSERT INTO deadman_settings (user_id, enabled, days, trusted_email, access_code_hash, triggered_at, access_token, token_expires,
        trusted2_email, trusted2_scope, access_code2_hash, access_token2, token2_expires)
        VALUES ($user, 1, 30, 'a@example.com', 'h', '2026-02-01', '$first', date('now', '+30 days'), 'b@example.com', 'limited', 'h2', '$second', date('now', '+30 days'))");
    assert_same('all', deadmanByToken($db, $first)['_scope'], 'first person sees everything');
    $row = deadmanByToken($db, $second);
    assert_same(2, $row['_slot'], 'second link');
    assert_same('limited', $row['_scope'], 'limited scope');
    deadmanTouch($db, $user, '2026-01-01');
    assert_same(null, deadmanByToken($db, $second), 'activity withdraws the second link too');
});

wallos_test('task inbox: done hides until a new occurrence, later for a week, kinds can be switched off', function () {
    require_once WALLOS_ROOT . '/includes/task_inbox.php';
    $tasks = [
        ['key' => 'trial:1:2026-12-01', 'kind' => 'deadline', 'date' => '2026-12-01'],
        ['key' => 'warranty:2:2026-12-10', 'kind' => 'warranty', 'date' => '2026-12-10'],
        ['key' => 'review:3:2024-01-01', 'kind' => 'insurance_review', 'date' => '2026-11-20'],
    ];
    $states = [
        'trial:1:2026-12-01' => ['status' => 'done', 'until' => null],
        'warranty:2:2026-12-10' => ['status' => 'later', 'until' => '2026-11-27'],
    ];
    assert_same(['review:3:2024-01-01'], array_column(filterTasks($tasks, $states, '2026-11-20'), 'key'), 'done and later hidden');
    assert_same(['warranty:2:2026-12-10', 'review:3:2024-01-01'], array_column(filterTasks($tasks, $states, '2026-11-27'), 'key'), 'later is back after a week');
    $next = [['key' => 'trial:1:2027-03-01', 'kind' => 'deadline', 'date' => '2027-03-01']];
    assert_same(1, count(filterTasks($next, $states, '2027-02-20')), 'a new trial end shows again');

    $db = wallos_test_open_database();
    require WALLOS_ROOT . '/migrations/000086.php';
    assert_true(notificationEnabled($db, 77, 'warranty'), 'on by default');
    $db->exec("INSERT OR REPLACE INTO notification_prefs (user_id, kind, enabled) VALUES (77, 'warranty', 0)");
    assert_true(!notificationEnabled($db, 77, 'warranty'), 'switched off');
});

wallos_test('cancellation package: one PDF per contract, insurance and subscription, building blocks left out', function () {
    require_once WALLOS_ROOT . '/includes/emergency_letters.php';
    $db = wallos_test_open_database();
    foreach (['000079', '000080', '000081', '000082', '000083'] as $m) {
        require WALLOS_ROOT . "/migrations/$m.php";
    }
    $u = extension_insert($db, "INSERT INTO user (username, email, password, main_currency) VALUES ('pk', 'pk@example.com', 'x', 1)");
    $db->exec("INSERT INTO contracts (user_id, name, provider, price, currency_id, cycle, frequency, payer_user_id) VALUES ($u, 'Handy', 'Telekom', 30, 1, 3, 1, 1)");
    $policy = extension_insert($db, "INSERT INTO insurances (user_id, name, insurer, price, currency_id, cycle, frequency) VALUES ($u, 'Haftpflicht', 'HUK', 5, 1, 3, 1)");
    $db->exec("INSERT INTO insurances (user_id, name, insurer, price, currency_id, cycle, frequency, parent_id) VALUES ($u, 'Hausrat (Baustein)', 'HUK', 5, 1, 3, 1, $policy)");
    $db->exec("INSERT INTO subscriptions (name, price, currency_id, next_payment, cycle, frequency, user_id, payer_user_id, payment_method_id, inactive) VALUES ('Netflix', 13, 1, date('now'), 3, 1, $u, 1, 1, 0)");
    assert_same(['Handy', 'Haftpflicht', 'Netflix'], array_column(emergencyLetterItems($db, $u), 'name'), 'block of the policy left out');
    $zipPath = emergencyLetterZip($db, $u, ['name' => 'Anna Muster', 'address' => "Weg 1\n12345 Ort", 'place' => 'Ort'], 'Max Muster');
    $zip = new ZipArchive();
    $zip->open($zipPath);
    assert_same(3, $zip->numFiles, 'three letters');
    $pdf = $zip->getFromIndex(0);
    assert_true(str_starts_with($pdf, '%PDF'), 'a PDF');
    assert_true(str_contains($pdf, 'Max Muster'), 'names the deceased');
    $zip->close();
    unlink($zipPath);
});

wallos_test('contract types: details per type, statutory terms, leasing mileage, switch calculator', function () {
    require_once WALLOS_ROOT . '/includes/contract_types.php';
    $details = contractDetailsFromRequest('leasing', ['detail_km_per_year' => '10000', 'detail_return_date' => '2027-01-01', 'detail_bandwidth' => '100', 'detail_extra_km_cost' => '0,12']);
    assert_same(['km_per_year' => 10000.0, 'extra_km_cost' => 0.12, 'return_date' => '2027-01-01'], $details, 'only leasing fields, numbers parsed');
    assert_same(['bandwidth' => '250 Mbit'], contractDetails(['contract_type' => 'internet', 'details' => json_encode(['bandwidth' => '250 Mbit', 'cold_rent' => 900])]), 'foreign fields dropped');

    assert_same(['renewal' => 1, 'notice' => 1], statutoryContractTerms('mobile', '2023-05-01'), 'consumer contract after March 2022');
    assert_same(null, statutoryContractTerms('mobile', '2021-05-01'), 'older contract');
    assert_same(null, statutoryContractTerms('rent', '2024-01-01'), 'rent has its own rules');

    $km = leasingMileage(['km_per_year' => 10000, 'km_current' => 15000, 'km_date' => '2025-01-01', 'return_date' => '2027-01-01', 'extra_km_cost' => 0.1], '2024-01-01', '2025-01-01');
    // 2024 is a leap year, so the figures are about, not exactly, 45,000 / 15,000.
    assert_true(abs($km['expected'] - 45000) < 200, '15,000 km in the first year -> about 45,000 after three');
    assert_true(abs($km['extra_km'] - 15000) < 200, 'about 30,000 allowed');
    assert_true(abs($km['extra_cost'] - 1500) < 20, 'about 15,000 × 0.10 €');

    $switch = contractSwitchComparison(40, 30, 100, 20, 24);
    assert_same(960.0, $switch['current'], 'current over 24 months');
    assert_same(640.0, $switch['offer'], 'offer minus bonus plus setup fee');
    assert_same(320.0, $switch['saving'], 'saving');
});

wallos_test('vehicles: consumption counts what was filled after the first fill-up', function () {
    require_once WALLOS_ROOT . '/includes/household_extras.php';
    $fuel = [
        ['fuel_date' => '2026-01-01', 'liters' => 40, 'cost' => 70, 'mileage' => 10000],
        ['fuel_date' => '2026-02-01', 'liters' => 30, 'cost' => 54, 'mileage' => 10500],
        ['fuel_date' => '2026-03-01', 'liters' => 35, 'cost' => 63, 'mileage' => 11000],
    ];
    $stats = vehicleFuelStats($fuel, '2026-06-01');
    assert_same(11000, $stats['mileage'], 'highest mileage');
    assert_same(6.5, $stats['per100'], '65 l over 1000 km');
    assert_same(0.12, $stats['cost_per_km'], '117 € over 1000 km');
    assert_same(15.58, $stats['monthly'], '187 € in the last year / 12');
    assert_same(null, vehicleFuelStats([$fuel[0]], '2026-06-01')['per100'], 'one fill-up: no consumption yet');
});

wallos_test('children: U check-ups are due in their window, long-gone ones are not nagged about', function () {
    require_once WALLOS_ROOT . '/includes/household_extras.php';
    $exams = childExams('2025-01-15', 'U2,U3', '2025-07-20');
    assert_same('done', $exams['U3']['state'], 'ticked off');
    assert_same('overdue', $exams['U4']['state'], 'U4 window closed in May, not done');
    assert_same('due', $exams['U5']['state'], 'U5 window 15.07.-15.08.');
    assert_same('upcoming', $exams['U6']['state'], 'U6 later');
    assert_same('past', childExams('2015-01-01', '', '2026-01-01')['U9']['state'], 'U9 years ago');
    assert_same(10, childAge('2015-06-01', '2026-01-01'), 'age in full years');
    assert_same([], childExams(null, '', '2026-01-01'), 'no birthdate, no check-ups');
    assert_true(readonlyAllows('vehicles.php') && readonlyAllows('children.php'), 'read-only accounts see both pages');
});

wallos_test('price trend: change since the first known price against inflation', function () {
    require_once WALLOS_ROOT . '/includes/price_trend.php';
    $history = [
        ['item_type' => 'contract', 'item_id' => 1, 'old_price' => 40, 'new_price' => 45, 'changed_at' => '2022-03-01'],
        ['item_type' => 'contract', 'item_id' => 1, 'old_price' => 45, 'new_price' => 50, 'changed_at' => '2024-03-01'],
    ];
    $items = [['type' => 'contract', 'id' => 1, 'name' => 'Strom', 'monthly' => 50.0], ['type' => 'subscription', 'id' => 1, 'name' => 'Abo', 'monthly' => 10.0]];
    $trend = priceTrend($history, $items, '2025-06-01');
    assert_same(1, count($trend['items']), 'only items whose price changed are listed');
    assert_same(25.0, $trend['items'][0]['change'], '40 → 50');
    assert_same(10.6, $trend['items'][0]['inflation'], 'index 2022 → 2025');
    assert_same(50.0, $trend['then'], '40 + 10 per month at first prices');
    assert_same(20.0, $trend['change'], '50 → 60 in total');
    assert_same(2.0, inflationBetween('2025-01-01', '2026-01-01'), 'after the table: 2 % a year');
});

wallos_test('fixed-cost ratio: traffic light at 50 and 70 percent of income', function () {
    require_once WALLOS_ROOT . '/includes/price_trend.php';
    assert_same(['share' => 50.0, 'light' => 'green'], fixedCostRatio(1500, 3000), 'half');
    assert_same('yellow', fixedCostRatio(2000, 3000)['light'], 'two thirds');
    assert_same('red', fixedCostRatio(2400, 3000)['light'], '80 %');
    assert_same(null, fixedCostRatio(500, 0), 'no income');
});

wallos_test('dashboard: only known panel keys can be switched off', function () {
    require_once WALLOS_ROOT . '/includes/dashboard_panels.php';
    assert_same(['hints', 'vehicles'], dashboardHiddenPanels(['dashboard_hidden' => 'hints,bogus,vehicles']), 'unknown keys dropped');
    assert_same([], dashboardHiddenPanels([]), 'nothing set: everything shown');
    assert_true(!readonlyAllows('endpoints/settings/dashboard_panels.php'), 'read-only accounts cannot change it');
});

wallos_test('cron logs are found in Docker and in the LXC log folder', function () {
    require_once WALLOS_ROOT . '/includes/system_health.php';
    $dir = sys_get_temp_dir() . '/wallos-cronlog-' . getmypid();
    @mkdir("$dir/docker", 0777, true);
    @mkdir("$dir/lxc", 0777, true);
    file_put_contents("$dir/lxc/checkforupdates.log", 'ok');
    assert_same("$dir/lxc", cronLogDir(["$dir/docker", "$dir/lxc"]), 'LXC folder used when only it has logs');
    file_put_contents("$dir/docker/checkforupdates.log", 'ok');
    assert_same("$dir/docker", cronLogDir(["$dir/docker", "$dir/lxc"]), 'Docker folder first');
    array_map('unlink', glob("$dir/*/*.log"));
    assert_same("$dir/docker", cronLogDir(["$dir/docker", "$dir/lxc"]), 'no logs anywhere: the first folder');
});

wallos_test('Bausparvertrag deposits start on the first debit date when set', function () {
    require_once WALLOS_ROOT . '/includes/financing_calculations.php';
    $loan = ['start_date' => '2026-01-08', 'monthly_rate' => 100, 'principal' => 20000, 'interest_rate' => 0.1, 'bausparen_min_savings_pct' => 40, 'bausparen_fee' => 200];
    $dates = fn($loan) => array_slice(array_column(computeBausparSchedule($loan, '2026-11-18')['savings_rows'], 'date'), 0, 4);
    assert_same(['2026-01-08', '2026-02-08', '2026-03-08', '2026-04-08'], $dates($loan), 'default: one month after the start');
    assert_same(['2026-01-08', '2026-01-30', '2026-02-28', '2026-03-30'], $dates($loan + ['first_payment_date' => '2026-01-30']), 'fee at the start, deposits from the first debit');
});

wallos_test('the price trend compares the first known price with the current one', function () {
    require_once WALLOS_ROOT . '/includes/price_trend.php';
    // A backdated entry alone (15 from 2021, no change recorded since) against the current price of 21.
    $history = [['item_type' => 'contract', 'item_id' => 1, 'old_price' => 15, 'new_price' => 15, 'changed_at' => '2021-01-01']];
    $trend = priceTrend($history, [['type' => 'contract', 'id' => 1, 'name' => 'Strom', 'monthly' => 21.0, 'price' => 21.0]], '2026-11-18');
    assert_same(40.0, $trend['items'][0]['change'], '+40 % since 2021');
    assert_same(15.0, $trend['items'][0]['then'], 'then 15');
});

wallos_test('duplicate insurances only when the same person is covered twice', function () {
    require_once WALLOS_ROOT . '/includes/insurance_insights.php';
    $bu = fn($name, $persons, $payer = 1) => ['name' => $name, 'insurance_type' => 'disability', 'insured_persons' => $persons, 'payer_user_id' => $payer];
    assert_same([], duplicateInsurances([$bu('BU Tim', '1'), $bu('BU Lisa', '2')]), 'two people: no duplicate');
    assert_same([], duplicateInsurances([$bu('BU Tim', '', 1), $bu('BU Lisa', '', 2)]), 'no persons named: the payer counts');
    assert_same(['disability' => ['BU alt', 'BU neu']], duplicateInsurances([$bu('BU alt', '1'), $bu('BU neu', '1')]), 'same person twice');
    $car = fn($name) => ['name' => $name, 'insurance_type' => 'car', 'insured_persons' => '', 'payer_user_id' => 1];
    assert_same([], duplicateInsurances([$car('Golf'), $car('Polo')]), 'two cars are normal');
});
