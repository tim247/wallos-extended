<?php
require_once 'connect.php';
ob_start();
require_once 'run_migrations.php';
ob_end_clean();
require_once 'checkuser.php';
require_once 'checksession.php';
require_once 'checkredirect.php';
require_once 'currency_formatter.php';

require_once 'libs/csrf.php';

require_once 'i18n/languages.php';
require_once 'i18n/getlang.php';
require_once 'i18n/' . $lang . '.php';

require_once 'getsettings.php';
require_once 'update_next_payment.php';

require_once 'version.php';

if ($userCount == 0) {
  $db->close();
  header("Location: registration.php");
  exit();
}

$demoMode = getenv('DEMO_MODE');

$theme = "automatic";
if (isset($settings['theme'])) {
  $theme = $settings['theme'];
}

$updateThemeSettings = false;
if (isset($settings['update_theme_setttings'])) {
  $updateThemeSettings = $settings['update_theme_setttings'];
}

$colorTheme = "blue";
if (isset($settings['color_theme'])) {
  $colorTheme = $settings['color_theme'];
}

$customCss = "";
if (isset($settings['customCss'])) {
  $customCss = $settings['customCss'];
}

if (isset($themeValue)) {
  $cookieExpire = time() + (30 * 24 * 60 * 60);
  setcookie('theme', $themeValue, [
    'expires' => $cookieExpire,
    'samesite' => 'Lax'
  ]);
}

$isAdmin = $_SESSION['userId'] == 1;

$locale = isset($_COOKIE['user_locale']) ? $_COOKIE['user_locale'] : 'en_US';
$formatter = new IntlDateFormatter(
  $locale, 
  IntlDateFormatter::MEDIUM,
  IntlDateFormatter::NONE
);

function hex2rgb($hex)
{
  $hex = str_replace("#", "", $hex);
  if (strlen($hex) == 3) {
    $r = hexdec(substr($hex, 0, 1) . substr($hex, 0, 1));
    $g = hexdec(substr($hex, 1, 1) . substr($hex, 1, 1));
    $b = hexdec(substr($hex, 2, 1) . substr($hex, 2, 1));
  } else {
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
  }
  return "$r, $g, $b";
}

$mobileNavigation = $settings['mobile_nav'] ? "mobile-navigation" : "";

?>
<!DOCTYPE html>
<html dir="<?= $languages[$lang]['dir'] ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Wallos Extended</title>
  <meta name="apple-mobile-web-app-title" content="Wallos Extended">
  <meta name="theme-color" content="<?= $theme == "light" ? "#FFFFFF" : "#12151C" ?>" id="theme-color" />
  <meta name="referrer" content="no-referrer">
  <link rel="icon" type="image/png" href="images/icon/favicon.ico" sizes="16x16">
  <link rel="apple-touch-icon" href="images/icon/apple-touch-icon.png">
  <link rel="apple-touch-icon" sizes="152x152" href="images/icon/apple-touch-icon-152.png">
  <link rel="apple-touch-icon" sizes="180x180" href="images/icon/apple-touch-icon-180.png">
  <link rel="manifest" href="manifest.php" crossorigin="use-credentials">
  <link rel="stylesheet" href="styles/theme.css?<?= $assetVersion ?>">
  <link rel="stylesheet" href="styles/styles.css?<?= $assetVersion ?>">
  <link rel="stylesheet" href="styles/dark-theme.css?<?= $assetVersion ?>" id="dark-theme" <?= $theme != "dark" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/red.css?<?= $assetVersion ?>" id="red-theme" <?= $colorTheme != "red" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/green.css?<?= $assetVersion ?>" id="green-theme" <?= $colorTheme != "green" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/yellow.css?<?= $assetVersion ?>" id="yellow-theme" <?= $colorTheme != "yellow" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/themes/purple.css?<?= $assetVersion ?>" id="purple-theme" <?= $colorTheme != "purple" ? "disabled" : "" ?>>
  <link rel="stylesheet" href="styles/barlow.css">
  <link rel="stylesheet" href="styles/font-awesome.min.css">
  <link rel="stylesheet" href="styles/brands.css">
  <script type="text/javascript" src="scripts/all.js?<?= $assetVersion ?>"></script>
  <script type="text/javascript" src="scripts/common.js?<?= $assetVersion ?>"></script>
  <script type="text/javascript">
    window.theme = <?= json_encode($theme, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
    window.update_theme_settings = "<?= $updateThemeSettings ?>";
    window.lang = "<?= $lang ?>";
    window.colorTheme = <?= json_encode($colorTheme, JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS) ?>;
    window.mobileNavigation = "<?= $settings['mobileNavigation'] == "true" ?>";
    window.csrfToken = "<?= htmlspecialchars(generate_csrf_token()) ?>";
  </script>
  <style>
    <?= htmlspecialchars($customCss, ENT_QUOTES, 'UTF-8') ?>
  </style>
  <?php
  if (isset($settings['customColors'])) {
    ?>
    <style id="custom_theme_colors">
      :root {
        <?php if (isset($settings['customColors']['main_color']) && !empty($settings['customColors']['main_color'])): ?>
          --main-color:
            <?= $settings['customColors']['main_color'] ?>
          ;
          --main-color-rgb:
            <?= hex2rgb($settings['customColors']['main_color']) ?>
          ;
        <?php endif; ?>
        <?php if (isset($settings['customColors']['accent_color']) && !empty($settings['customColors']['accent_color'])): ?>
          --accent-color:
            <?= $settings['customColors']['accent_color'] ?>
          ;
          --accent-color-rgb:
            <?= hex2rgb($settings['customColors']['accent_color']) ?>
          ;
        <?php endif; ?>
        <?php if (isset($settings['customColors']['hover_color']) && !empty($settings['customColors']['hover_color'])): ?>
          --hover-color:
            <?= $settings['customColors']['hover_color'] ?>
          ;
          --hover-color-rgb:
            <?= hex2rgb($settings['customColors']['hover_color']) ?>
          ;
        <?php endif; ?>
      }
    </style>
    <?php
  }
  ?>
  <script type="text/javascript" src="scripts/i18n/<?= $lang ?>.js?<?= $assetVersion ?>"></script>
  <script type="text/javascript" src="scripts/i18n/getlang.js?<?= $assetVersion ?>"></script>
  <script type="text/javascript" src="scripts/password-toggle.js?<?= $assetVersion ?>"></script>
  <script>
    if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
      if (!sessionStorage.getItem('sw_prefetched')) {
        navigator.serviceWorker.controller.postMessage({ type: 'PREFETCH_PAGES' });
        sessionStorage.setItem('sw_prefetched', '1');
      }
    }
  </script>
</head>

<body class="<?= $theme ?> <?= $languages[$lang]['dir'] ?> <?= $mobileNavigation ?>">
  <header>
    <div class="contain">
      <div class="logo">
        <a href=".">
          <div class="logo-image" title="Wallos Extended">
            <?php include "images/siteicons/svg/logo.php"; ?>
          </div>
        </a>
      </div>
      <nav>
        <div class="dropdown">
          <button class="dropbtn" onClick="toggleDropdown()">
            <img src="<?= htmlspecialchars($userData['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="me" id="avatar">
            <span id="user" class="mobileNavigationHideOnMobile"><?= $userData['username'] ?></span>
          </button>
          <?php
          // Menu in groups. Icon: an SVG from images/siteicons/svg/mobile-menu or a Font Awesome class.
          // 'nav' = also in the bottom bar, hidden here on phones when that bar is on.
          $menuGroups = [
            'menu_overview' => [
              ['.', 'svg:home', 'dashboard', true],
              ['tasks.php', 'fa-inbox', 'task_inbox'],
              ['calendar.php', 'svg:calendar', 'calendar', true],
              ['yearly.php', 'fa-calendar-days', 'yearly_overview'],
              ['stats.php', 'svg:statistics', 'stats', true],
            ],
            'menu_costs' => [
              ['subscriptions.php', 'svg:subscriptions', 'subscriptions', true],
              ['contracts.php', 'svg:contracts', 'contracts'],
              ['insurance.php', 'svg:insurance', 'insurances'],
              ['financing.php', 'svg:financing', 'financing'],
              ['consumption.php', 'svg:consumption', 'consumption'],
            ],
            'menu_planning' => [
              ['finances.php', 'fa-sack-dollar', 'finances'],
              ['report.php', 'fa-file-invoice', 'cost_report'],
              ['wrapped.php', 'fa-gift', 'wrapped'],
              ['annual_review.php', 'fa-clipboard-check', 'annual_review'],
              ['person.php', 'fa-users', 'person_view'],
            ],
            'menu_household' => [
              ['reminders.php', 'fa-calendar-check', 'reminders'],
              ['inventory.php', 'fa-boxes-stacked', 'inventory'],
              ['vehicles.php', 'fa-car', 'vehicles'],
              ['children.php', 'fa-child', 'children'],
              ['emergency.php', 'fa-kit-medical', 'emergency'],
            ],
            'menu_tools' => [
              ['quick.php', 'fa-bolt', 'quick_capture'],
              ['bankimport.php', 'fa-building-columns', 'bank_import'],
              ['import.php', 'fa-file-import', 'import_csv'],
              ['history.php', 'fa-clock-rotate-left', 'change_log'],
            ],
            'menu_account' => array_filter([
              ['settings.php', 'svg:settings', 'settings', true],
              ['profile.php', 'svg:profile', 'profile'],
              $isAdmin ? ['admin.php', 'svg:admin', 'admin'] : null,
              ['help.php', 'fa-circle-question', 'help'],
              ['about.php', 'svg:about', 'about'],
              ['#install-app', 'fa-mobile-screen-button', 'install_app'],
              $settings['disableLogin'] == 0 ? ['logout.php', 'svg:logout', 'logout'] : null,
            ]),
          ];
          $currentPage = basename($_SERVER['PHP_SELF']);
          ?>
          <div class="dropdown-content menu-grouped">
            <?php foreach ($menuGroups as $groupKey => $items):
              // A read-only account only sees what it may open.
              $items = array_filter($items, fn($item) => empty($isReadOnlyAccount) || $item[0] === '.' || $item[0] === '#install-app' || readonlyAllows($item[0]));
              if (!$items) continue; ?>
              <div class="menu-group">
                <div class="menu-group-title"><?= translate($groupKey, $i18n) ?></div>
                <?php foreach ($items as $item):
                  [$href, $icon, $label] = $item;
                  $classes = trim((!empty($item[3]) ? 'mobileNavigationHideOnMobile ' : '') . (($href === '.' ? 'index.php' : $href) === $currentPage ? 'active' : '')); ?>
                  <a href="<?= $href ?>"<?= $classes ? ' class="' . $classes . '"' : '' ?>>
                    <?php if (str_starts_with($icon, 'svg:')) {
                      include 'images/siteicons/svg/mobile-menu/' . substr($icon, 4) . '.php';
                    } else { ?><i class="fa-solid <?= $icon ?>"></i><?php } ?>
                    <?= translate($label, $i18n) ?></a>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </nav>
    </div>
  </header>

  <?php
  // find out which page is being viewed
  $page = basename($_SERVER['PHP_SELF']);
  $dashboardClass = $page === 'index.php' ? 'active' : '';
  $subscriptionsClass = $page === 'subscriptions.php' ? 'active' : '';
  $contractsClass = $page === 'contracts.php' ? 'active' : '';
  $insuranceClass = $page === 'insurance.php' ? 'active' : '';
  $financingClass = $page === 'financing.php' ? 'active' : '';
  $consumptionClass = $page === 'consumption.php' ? 'active' : '';
  $calendarClass = $page === 'calendar.php' ? 'active' : '';
  $statsClass = $page === 'stats.php' ? 'active' : '';
  $settingsClass = $page === 'settings.php' ? 'active' : '';
  $profileClass = $page === 'profile.php' ? 'active' : '';
  ?>

  <?php
  if ($settings['mobile_nav'] == 1) {
    ?>
    <nav class="mobile-nav">
        <a href="." class="nav-link <?= $dashboardClass ?>" title="<?= translate('dashboard', $i18n) ?>">
          <?php include "images/siteicons/svg/mobile-menu/home.php"; ?>
          <?= translate('dashboard', $i18n) ?>
        </a>
        <a href="subscriptions.php" class="nav-link <?= $subscriptionsClass ?>" title="<?= translate('subscriptions', $i18n) ?>">
          <?php include "images/siteicons/svg/mobile-menu/subscriptions.php"; ?>
          <?= translate('subscriptions', $i18n) ?>
        </a>
        <a href="calendar.php" class="nav-link <?= $calendarClass ?>" title="<?= translate('calendar', $i18n) ?>">
          <?php include "images/siteicons/svg/mobile-menu/calendar.php"; ?>
          <?= translate('calendar', $i18n) ?>
        </a>
        <a href="stats.php" class="nav-link <?= $statsClass ?>" title="<?= translate('stats', $i18n) ?>">
          <?php include "images/siteicons/svg/mobile-menu/statistics.php"; ?>
          <?= translate('stats', $i18n) ?>
        </a>
        <a href="settings.php" class="nav-link <?= $settingsClass ?>" title="<?= translate('settings', $i18n) ?>">
          <?php include "images/siteicons/svg/mobile-menu/settings.php"; ?>
          <?= translate('settings', $i18n) ?>
        </a>
    </nav>
    <?php
  }
  ?>

  <main>
    <?php if (!empty($isReadOnlyAccount)): ?>
      <div class="readonly-banner"><i class="fa-solid fa-eye"></i> <?= sprintf(translate('readonly_banner', $i18n), htmlspecialchars($readOnlyViewerName)) ?></div>
    <?php endif; ?>
