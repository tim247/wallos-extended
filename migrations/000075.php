<?php
// Meters: own order (drag and drop on the consumption page). Existing meters keep today's order.
if (!$db->querySingle("SELECT COUNT(*) FROM pragma_table_info('meters') WHERE name = 'sort_order'")) {
    $db->exec("ALTER TABLE meters ADD COLUMN sort_order INTEGER DEFAULT 0");
    $db->exec("UPDATE meters SET sort_order = (SELECT COUNT(*) FROM meters m2 WHERE m2.user_id = meters.user_id
        AND (m2.meter_type < meters.meter_type OR (m2.meter_type = meters.meter_type AND (lower(m2.name) < lower(meters.name)
        OR (lower(m2.name) = lower(meters.name) AND m2.id <= meters.id)))))");
}
