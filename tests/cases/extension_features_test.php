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
