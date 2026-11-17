<?php
// Global search over every area and the uploaded documents (search.php and the dashboard box).

/**
 * @return array<int, array{icon: string, href: string, name: string, info: string, area: string}>
 *   area = translation key of the section the hit belongs to
 */
function globalSearch(SQLite3 $db, int $userId, string $q, int $perArea = 50): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) {
        return [];
    }
    $sources = [
        ['subscriptions', 'fa-credit-card', 'subscriptions.php', "SELECT id, name, notes AS info FROM subscriptions WHERE user_id = :userId AND (name LIKE :q OR notes LIKE :q OR url LIKE :q)"],
        ['contracts', 'fa-file-signature', 'contracts.php', "SELECT id, name, provider || ' ' || contract_number AS info FROM contracts WHERE user_id = :userId AND (name LIKE :q OR provider LIKE :q OR contract_number LIKE :q OR notes LIKE :q)"],
        ['insurances', 'fa-shield-halved', 'insurance.php', "SELECT id, name, insurer || ' ' || policy_number AS info FROM insurances WHERE user_id = :userId AND (name LIKE :q OR insurer LIKE :q OR policy_number LIKE :q OR notes LIKE :q OR coverage_notes LIKE :q OR contact_name LIKE :q)"],
        ['financing', 'fa-landmark', 'financing.php?id=', "SELECT id, name, lender AS info FROM loans WHERE user_id = :userId AND (name LIKE :q OR lender LIKE :q OR notes LIKE :q)"],
        ['consumption', 'fa-gauge', 'consumption.php', "SELECT id, name, meter_number AS info FROM meters WHERE user_id = :userId AND (name LIKE :q OR meter_number LIKE :q)"],
        ['reminders', 'fa-calendar-check', 'reminders.php', "SELECT id, name, holder || ' ' || notes AS info FROM reminders WHERE user_id = :userId AND (name LIKE :q OR holder LIKE :q OR notes LIKE :q)"],
        ['inventory', 'fa-boxes-stacked', 'inventory.php', "SELECT id, name, location || ' ' || serial_number AS info FROM inventory WHERE user_id = :userId AND (name LIKE :q OR location LIKE :q OR category LIKE :q OR serial_number LIKE :q OR notes LIKE :q)"],
        ['item_files', 'fa-paperclip', 'endpoints/files/download.php?id=', "SELECT id, filename AS name, item_type AS info FROM item_files WHERE user_id = :userId AND filename LIKE :q"],
    ];
    // LIKE wildcards in the input are searched literally.
    $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    $hits = [];
    foreach ($sources as [$area, $icon, $link, $sql]) {
        $stmt = @$db->prepare(str_replace('LIKE :q', "LIKE :q ESCAPE '\\'", $sql) . ' LIMIT ' . max(1, $perArea));
        if (!$stmt) {
            continue; // table missing before a migration
        }
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':q', $pattern);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $hits[] = [
                'icon' => $icon,
                'href' => str_ends_with($link, '=') ? $link . $row['id'] : $link,
                'name' => (string) $row['name'],
                'info' => trim((string) $row['info']),
                'area' => $area,
            ];
        }
    }
    return $hits;
}
