<?php
$version = "v5.11.1"; // upstream Wallos version this build is based on
// Wallos Extended release (written by the release build into RELEASE, e.g. v5.11.1-ext.6).
// Updates are checked against the Wallos Extended releases on GitHub.
$extRepository = 'tim247/wallos-extended';
$releaseFile = __DIR__ . '/../RELEASE';
$extRelease = is_file($releaseFile) ? trim((string) file_get_contents($releaseFile)) : '';
if (!preg_match('/^v\d+\.\d+\.\d+(-ext\.\d+)?$/', $extRelease)) {
    $extRelease = ''; // a development checkout has no release
}
$installedVersion = $extRelease !== '' ? $extRelease : $version . '-dev';
$assetVersion = $extRelease !== '' ? $extRelease : $version . '-dev';

if (!function_exists('extVersionCompare')) {
    /**
     * Compares Wallos Extended versions: v5.11.1 < v5.11.1-ext.1 < v5.11.1-ext.6 < v5.12.0.
     * (version_compare() would read "-ext" as a pre-release and sort it below v5.11.1.)
     * A development build ("-dev") counts as newer than any release of the same base,
     * so it never shows an update for its own base version.
     * @return int -1, 0 or 1
     */
    function extVersionCompare(string $a, string $b): int
    {
        $parse = function (string $v): ?array {
            if (!preg_match('/^v?(\d+)\.(\d+)\.(\d+)(?:-ext\.(\d+)|-(dev))?$/', trim($v), $m)) {
                return null;
            }
            return [(int) $m[1], (int) $m[2], (int) $m[3], !empty($m[5]) ? PHP_INT_MAX : (int) ($m[4] ?? 0)];
        };
        $pa = $parse($a);
        $pb = $parse($b);
        return $pa === null || $pb === null ? 0 : $pa <=> $pb;
    }
}
?>
