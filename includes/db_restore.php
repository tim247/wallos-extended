<?php
// Restoring a raw SQLite database file (wallos.db) from any older version.
// The file is validated first, the current database is kept as a safety copy,
// and the migrations bring the old schema up to date.

/**
 * Checks that $path is an intact SQLite file with the core Wallos tables and at
 * least one user. Returns null when fine, otherwise the i18n key of the problem.
 */
function dbRestoreValidate(string $path): ?string
{
    $handle = @fopen($path, 'rb');
    $header = $handle ? fread($handle, 16) : '';
    if ($handle) {
        fclose($handle);
    }
    if ($header !== "SQLite format 3\0") {
        return 'restore_invalid_db';
    }

    try {
        $candidate = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $candidate->enableExceptions(true); // a damaged file must not print warnings into the JSON answer
        $candidate->busyTimeout(5000);
        if ($candidate->querySingle('PRAGMA quick_check') !== 'ok') {
            $candidate->close();
            return 'restore_db_corrupt';
        }
        $tables = [];
        $result = $candidate->query("SELECT name FROM sqlite_master WHERE type = 'table'");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tables[] = $row['name'];
        }
        $hasCoreTables = in_array('user', $tables, true) && in_array('subscriptions', $tables, true);
        $hasUser = $hasCoreTables && (int) $candidate->querySingle('SELECT COUNT(*) FROM user') > 0;
        $candidate->close();
    } catch (Throwable $e) {
        return 'restore_invalid_db';
    }
    return $hasUser ? null : 'restore_invalid_db';
}

/** Copies the current database next to it; returns the file name or null when there is none. */
function dbRestoreSafetyCopy(string $dbDir): ?string
{
    $current = $dbDir . '/wallos.db';
    if (!is_file($current)) {
        return null;
    }
    $name = 'before-restore-' . date('Ymd-His') . '.db';
    return copy($current, $dbDir . '/' . $name) ? $name : null;
}

/** Replaces db/wallos.db with $source (also clears stale journal files). */
function dbRestoreInstall(string $source, string $dbDir): bool
{
    foreach (['-journal', '-wal', '-shm'] as $suffix) {
        @unlink($dbDir . '/wallos.db' . $suffix);
    }
    @unlink($dbDir . '/wallos.db');
    if (@rename($source, $dbDir . '/wallos.db')) {
        return true;
    }
    $copied = @copy($source, $dbDir . '/wallos.db');
    @unlink($source);
    return $copied;
}

/** True when the uploaded file is a raw database file (by extension). */
function dbRestoreIsRawDb(array $file): bool
{
    return strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) === 'db';
}
