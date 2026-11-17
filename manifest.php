<?php

/**
 * Serves the PWA manifest dynamically so theme_color/background_color match
 * the user's actual theme instead of being hardcoded white - installed PWAs
 * (Android WebAPKs in particular) use the manifest's theme_color for the
 * status bar/header chrome, independent of the page's own <meta
 * name="theme-color"> tag.
 *
 * No login is required to read this (it's linked from the pre-auth pages
 * too), so theme comes from the same "theme"/"inUseTheme" cookies those
 * pages already use, not from a per-user DB setting.
 */

require_once __DIR__ . '/includes/theme_helpers.php';

$theme = sanitize_theme_mode($_COOKIE['theme'] ?? null);
if ($theme === 'automatic') {
    $theme = sanitize_resolved_theme($_COOKIE['inUseTheme'] ?? null);
}
$themeColor = $theme === 'light' ? '#FFFFFF' : '#12151C';
// Labels in German or English, from the language cookie or the browser.
$de = str_starts_with((string) ($_COOKIE['language'] ?? $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'de');

$manifest = [
    'short_name' => 'Wallos Extended',
    'name' => 'Wallos Extended',
    'icons' => [
        [
            'src' => 'images/icon/android-chrome-192x192.png',
            'type' => 'image/png',
            'sizes' => '192x192',
        ],
        [
            'src' => 'images/icon/android-chrome-512x512.png',
            'type' => 'image/png',
            'sizes' => '512x512',
        ],
        [
            'src' => 'images/icon/maskable_icon_x192.png',
            'type' => 'image/png',
            'sizes' => '192x192',
            'purpose' => 'maskable',
        ],
        [
            'src' => 'images/icon/maskable_icon_x512.png',
            'type' => 'image/png',
            'sizes' => '512x512',
            'purpose' => 'maskable',
        ],
    ],
    // Relative to the manifest, so an installation in a sub folder (/wallos/) works too.
    'start_url' => './index.php',
    'id' => './?app=wallos-extended', // its own id: installs next to an upstream Wallos instead of replacing it
    'shortcuts' => array_map(fn($s) => [
        'name' => $s[$de ? 1 : 0],
        'short_name' => $s[$de ? 1 : 0],
        'url' => $s[2],
        'icons' => [['src' => $s[3], 'type' => 'image/png', 'sizes' => '96x96']],
    ], [
        ['Quick capture', 'Schnellerfassung', 'quick.php', 'images/siteicons/pwa/dashboard.png'],
        ['Dashboard', 'Dashboard', 'index.php', 'images/siteicons/pwa/dashboard.png'],
        ['Subscriptions', 'Abos', 'subscriptions.php', 'images/siteicons/pwa/subscriptions.png'],
        ['Calendar', 'Kalender', 'calendar.php', 'images/siteicons/pwa/calendar.png'],
        ['Statistics', 'Statistik', 'stats.php', 'images/siteicons/pwa/stats.png'],
        ['Settings', 'Einstellungen', 'settings.php', 'images/siteicons/pwa/settings.png'],
    ]),
    'screenshots' => [
        [
            'src' => 'images/screenshots/desktop.png',
            'sizes' => '1000x750',
            'type' => 'image/png',
            'form_factor' => 'wide',
        ],
        [
            'src' => 'images/screenshots/mobile.png',
            'sizes' => '600x1000',
            'type' => 'image/png',
        ],
    ],
    'background_color' => $themeColor,
    'display' => 'standalone',
    'scope' => './',
    'theme_color' => $themeColor,
    'description' => $de
        ? 'Wallos Extended: Abos, Verträge, Versicherungen, Finanzierungen, Verbrauch und Vorsorge eines Haushalts.'
        : 'Wallos Extended: subscriptions, contracts, insurances, loans, consumption and provision of a household.',
    'lang' => $de ? 'de' : 'en',
    'display_override' => ['window-controls-overlay'],
];

if (!headers_sent()) {
    header('Content-Type: application/manifest+json; charset=utf-8');
}
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

?>
