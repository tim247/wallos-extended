<?php
// Expects $db to be set by the caller.

$migrationsDir = __DIR__ . '/../migrations/';

$completedMigrations = [];

$migrationTableExists = $db
    ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'")
    ->fetchArray(SQLITE3_ASSOC) !== false;

if ($migrationTableExists) {
    $migrationQuery = $db->query('SELECT migration FROM migrations');
    while ($row = $migrationQuery->fetchArray(SQLITE3_ASSOC)) {
        $completedMigrations[] = str_replace('../../', '', $row['migration']);
    }
}

// A database from the original Wallos 5.x has its own migrations 000047+ recorded,
// which Wallos Extended ships as 000101+ (its 000047-000060 are the extensions).
// Recognised by those entries without the contracts table of the extensions.
$hasContracts = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='contracts'") !== null;
if (!$hasContracts && in_array('migrations/000047.php', $completedMigrations, true)) {
    foreach ($completedMigrations as $done) {
        $n = (int) basename($done, '.php');
        if ($n >= 47 && $n <= 100) {
            $mapped = sprintf('migrations/%06d.php', $n + 54);
            $db->exec("UPDATE migrations SET migration = '$mapped' WHERE migration LIKE '%" . basename($done) . "'");
        }
    }
    $completedMigrations = array_map(function ($m) {
        $n = (int) basename($m, '.php');
        return ($n >= 47 && $n <= 100) ? sprintf('migrations/%06d.php', $n + 54) : $m;
    }, $completedMigrations);
    echo "Original Wallos 5.x database: migration numbers remapped.\n";
}

$allMigrations = array_map(
    fn($path) => 'migrations/' . basename($path),
    glob($migrationsDir . '*.php') ?: []
);

$requiredMigrations = array_diff($allMigrations, $completedMigrations);

if (count($requiredMigrations) === 0) {
    echo "No migrations to run.\n";
}

foreach ($requiredMigrations as $migration) {
    require_once $migrationsDir . basename($migration);

    $stmt = $db->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
    $stmt->bindValue(':migration', $migration, SQLITE3_TEXT);
    $stmt->execute();

    echo sprintf("Migration %s completed successfully.\n", $migration);
}
