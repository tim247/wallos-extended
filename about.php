<?php
require_once 'includes/header.php';

$wallosIsUpToDate = true;
if (!is_null($settings['latest_version'])) {
    $latestVersion = $settings['latest_version'];
    if (extVersionCompare($installedVersion, $latestVersion) < 0) {
        $wallosIsUpToDate = false;
    }
}
?>

<section class="contain">

    <section class="account-section">
        <header>
            <h2><?= translate('about', $i18n) ?></h2>
        </header>
        <?php $repoUrl = 'https://github.com/' . $extRepository; ?>
        <div class="credits-list">
            <div>
                <h3>
                    Wallos Extended <?= htmlspecialchars($installedVersion) ?> <?= $demoMode ? "Demo" : "" ?>
                </h3>
                <span>
                    <?= translate('release_notes', $i18n) ?>
                    <a href="<?= $repoUrl ?>/releases<?= $extRelease !== '' ? '/tag/' . htmlspecialchars($extRelease) : '' ?>" target="_blank"
                        title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
            <?php if (!$wallosIsUpToDate): ?>
                <div class="update-available">
                    <h3>
                        <i class="fa-solid fa-info-circle"></i>
                        <?= translate('update_available', $i18n) ?> <?= htmlspecialchars($latestVersion) ?>
                    </h3>
                    <span>
                        <?= translate('release_notes', $i18n) ?>
                        <a href="<?= $repoUrl ?>/releases/tag/<?= htmlspecialchars($latestVersion) ?>" target="_blank"
                            title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        </a>
                    </span>
                </div>
            <?php endif; ?>
            <div>
                <h3><?= translate('about_changelog', $i18n) ?></h3>
                <span>
                    <?= translate('about_changelog_info', $i18n) ?>
                    <a href="<?= $repoUrl ?>/releases" target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
            <div>
                <h3><?= translate('about_project', $i18n) ?></h3>
                <span>
                    github.com/<?= $extRepository ?>
                    <a href="<?= $repoUrl ?>" target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
            <div>
                <h3><?= translate('about_handbook', $i18n) ?></h3>
                <span>
                    <?= translate('about_handbook_info', $i18n) ?>
                    <a href="<?= $repoUrl ?>/blob/main/docs/HILFE.md" target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
            <div>
                <h3><?= translate('issues_and_requests', $i18n) ?></h3>
                <span>
                    GitHub Issues
                    <a href="<?= $repoUrl ?>/issues" target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
            <div>
                <h3><?= translate('license', $i18n) ?></h3>
                <span>
                    GPLv3 · <?= translate('about_license_info', $i18n) ?>
                    <a href="<?= $repoUrl ?>/blob/main/NOTICE.md" target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
        </div>
    </section>

    <section class="account-section">
        <header>
            <h2><i class="fa-solid fa-code-fork"></i> <?= translate('about_fork', $i18n) ?></h2>
        </header>
        <div class="credits-list">
            <div>
                <h3><?= sprintf(translate('about_fork_title', $i18n), htmlspecialchars($version)) ?></h3>
                <span><?= translate('about_fork_info', $i18n) ?></span>
            </div>
            <div>
                <h3><?= translate('about_original', $i18n) ?></h3>
                <span>
                    github.com/ellite/Wallos
                    <a href="https://github.com/ellite/Wallos" target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
            <div>
                <h3><?= translate('about_original_author', $i18n) ?></h3>
                <span>
                    https://henrique.pt
                    <a href="https://henrique.pt/" target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
            <div>
                <h3><?= translate('about_extensions', $i18n) ?></h3>
                <ul class="about-extensions">
                    <?php foreach ([
                        ['fa-file-signature', 'about_ext_contracts'],
                        ['fa-landmark', 'about_ext_financing'],
                        ['fa-gauge', 'about_ext_consumption'],
                        ['fa-sack-dollar', 'about_ext_planning'],
                        ['fa-kit-medical', 'about_ext_household'],
                        ['fa-building-columns', 'about_ext_tools'],
                        ['fa-plug', 'about_ext_integrations'],
                        ['fa-shield-halved', 'about_ext_platform'],
                    ] as [$icon, $key]): ?>
                        <li><i class="fa-solid <?= $icon ?>"></i> <?= translate($key, $i18n) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </section>

    <section class="account-section">
        <header>
            <h2><?= translate("credits", $i18n) ?></h2>
        </header>
        <div class="credits-list">
            <div>
                <h3><?= translate('payment_icons', $i18n) ?></h3>
                <span>
                    https://www.figma.com/file/5IMW8JfoXfB5GRlPNdTyeg/Credit-Cards-and-Payment-Methods-Icons-(Community)
                    <a href="https://www.figma.com/file/5IMW8JfoXfB5GRlPNdTyeg/Credit-Cards-and-Payment-Methods-Icons-(Community)"
                        target="_blank" title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
            <div>
                <h3>ApexCharts</h3>
                <span>
                    https://apexcharts.com/
                    <a href="https://apexcharts.com/" target="_blank" title="<?= translate('external_url', $i18n) ?>"
                        rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
            <div>
                <h3>QRCode.js</h3>
                <span>
                    https://github.com/davidshimjs/qrcodejs
                    <a href="https://github.com/davidshimjs/qrcodejs" target="_blank"
                        title="<?= translate('external_url', $i18n) ?>" rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
            <div>
                <h3>Icons by icons8</h3>
                <span>
                    https://icons8.com/
                    <a href="https://icons8.com/" target="_blank" title="<?= translate('external_url', $i18n) ?>"
                        rel="noreferrer">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    </a>
                </span>
            </div>
        </div>
    </section>

</section>

<?php
require_once 'includes/footer.php';
?>