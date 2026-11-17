<?php
// Read-only accounts (Settings > Read-only access): a login that sees the data of the
// owning user but cannot change anything. The session keeps the viewer's own id, so
// $isAdmin (header.php) stays false; $userId is switched to the owner for reading.
//
// Secure by default: a viewer only reaches the pages and endpoints listed here.
// Settings, profile, admin and every writing endpoint stay closed, because they would
// show the owner's secrets (SMTP password, API key, tokens) or change data.

const READONLY_PAGES = ['index.php', 'subscriptions.php', 'contracts.php', 'insurance.php', 'financing.php',
    'consumption.php', 'calendar.php', 'yearly.php', 'report.php', 'stats.php', 'search.php', 'history.php',
    'finances.php', 'wrapped.php', 'reminders.php', 'inventory.php', 'emergency.php', 'help.php', 'about.php', 'logout.php', 'person.php', 'tasks.php'];

const READONLY_ENDPOINTS = ['subscriptions/get.php', 'subscription/get.php', 'subscriptions/export.php',
    'subscription/exportcalendar.php', 'contracts/get.php', 'insurance/get.php', 'financing/get.php',
    'consumption/get_meter.php', 'consumption/get_chart_data.php', 'files/list.php', 'files/download.php',
    'report/export.php', 'report/export_all.php', 'search/query.php',
    // read-only computations and exports
    'consumption/weather.php', 'insurance/tax_export.php', 'financing/simulate.php'];

/** Owner id of a read-only account, or null for a normal account. */
function readonlyOwnerOf(SQLite3 $db, int $viewerId): ?int
{
    // Before the migration has run (first request after an update hits an endpoint),
    // the column does not exist yet - and no read-only account can exist either.
    $stmt = @$db->prepare('SELECT readonly_of FROM user WHERE id = :id');
    if (!$stmt) {
        return null;
    }
    $stmt->bindValue(':id', $viewerId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return !empty($row['readonly_of']) ? (int) $row['readonly_of'] : null;
}

/** Is this script open to read-only accounts? $script is relative to the app root. */
function readonlyAllows(string $script): bool
{
    $script = ltrim(str_replace('\\', '/', $script), '/');
    return in_array($script, READONLY_PAGES, true)
        || (str_starts_with($script, 'endpoints/') && in_array(substr($script, 10), READONLY_ENDPOINTS, true));
}

/** The requested script relative to the app root (works in a sub folder, too). */
function readonlyCurrentScript(): string
{
    $root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $file = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
    return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : basename($file);
}
