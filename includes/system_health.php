<?php
// Is the installation healthy? Nightly backup present and cron jobs running.
// Used by the daily notification job (backup) and the admin's dashboard (both: a stopped
// cron cannot report itself, so the dashboard is the only place that notices).

/** Where the cron jobs log: /var/log/cron in Docker, /var/log/wallos-extended in the Proxmox LXC. */
function cronLogDir(array $candidates = ['/var/log/cron', '/var/log/wallos-extended']): string
{
    foreach ($candidates as $dir) {
        if (glob($dir . '/*.log')) {
            return $dir;
        }
    }
    return $candidates[0];
}

/**
 * @return array<int, array{key: string, since: ?string}> translation key + date of the last sign of life
 */
function systemHealthIssues(SQLite3 $db, ?int $now = null, ?string $cronLogDir = null, ?string $backupDir = null): array
{
    $now ??= time();
    $cronLogDir ??= cronLogDir();
    $backupDir ??= dirname(__DIR__) . '/db/backups';
    $limit = $now - 2 * 86400;
    $issues = [];

    $keep = (int) ($db->querySingle('SELECT backup_keep FROM admin WHERE id = 1') ?? 14);
    if ($keep > 0) {
        $backups = glob($backupDir . '/wallos-*.db') ?: [];
        $newest = $backups ? max(array_map('filemtime', $backups)) : null;
        // No backup at all only counts once the backup job has run (its log exists):
        // a fresh installation has none yet.
        if ($newest !== null ? $newest < $limit : is_file($cronLogDir . '/autobackup.log')) {
            $issues[] = ['key' => 'health_backup_stale', 'since' => $newest ? date('Y-m-d', $newest) : null];
        }
    }

    // checkforupdates writes a line every 6 hours, autobackup every night: no log touched
    // for two days means cron has stopped. Only where the logs exist (Docker, LXC).
    $logs = is_dir($cronLogDir) ? (glob($cronLogDir . '/*.log') ?: []) : [];
    if ($logs) {
        $newestLog = max(array_map('filemtime', $logs));
        if ($newestLog < $limit) {
            $issues[] = ['key' => 'health_cron_stale', 'since' => date('Y-m-d', $newestLog)];
        }
    }
    return $issues;
}
