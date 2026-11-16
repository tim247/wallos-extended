<?php
// Exchange rates come from frankfurter.dev (ECB reference rates, no account, no key) instead of Fixer.
// Everyone moves over, allratestoday.com (also keyless) stays as chosen; old Fixer keys stay in the row, unused.
$db->exec("UPDATE fixer SET provider = 2 WHERE provider NOT IN (2, 3)");
$db->exec("INSERT INTO fixer (api_key, provider, user_id) SELECT '', 2, id FROM user WHERE id NOT IN (SELECT user_id FROM fixer)");
// New accounts (registration, admin, OIDC) get a provider without any code path having to remember it.
$db->exec("CREATE TRIGGER IF NOT EXISTS user_default_exchange_provider AFTER INSERT ON user
    BEGIN INSERT INTO fixer (api_key, provider, user_id) SELECT '', 2, NEW.id WHERE NOT EXISTS (SELECT 1 FROM fixer WHERE user_id = NEW.id); END");
