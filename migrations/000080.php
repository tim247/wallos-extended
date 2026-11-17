<?php
// Insurances: first term apart from the renewal term (e.g. 6 years, then 12 months).
// Existing insurances keep the 12 months the deadline calculation assumed so far.
$existing = [];
$columnQuery = $db->query('PRAGMA table_info(insurances)');
while ($column = $columnQuery->fetchArray(SQLITE3_ASSOC)) {
    $existing[] = $column['name'];
}
if (!in_array('initial_term_months', $existing)) {
    $db->exec('ALTER TABLE insurances ADD COLUMN initial_term_months INTEGER DEFAULT 12');
}
