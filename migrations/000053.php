<?php
// This migration adds indexes for the new household finance tables.

$db->exec('CREATE INDEX IF NOT EXISTS idx_contracts_user ON contracts (user_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_insurances_user ON insurances (user_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_loans_user ON loans (user_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_loan_extra_payments_loan ON loan_extra_payments (loan_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_meters_user ON meters (user_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_meter_readings_meter_date ON meter_readings (meter_id, reading_date)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_price_changes_subscription ON subscription_price_changes (subscription_id)');

?>
