<?php
// Every translation key used in the code exists in English (the fallback) and German.
// Other languages fall back to English, so a missing key there shows English, never the raw key.

function extension_translation_keys(string $language): array
{
    $i18n = [];
    include WALLOS_ROOT . "/includes/i18n/$language.php";
    return $i18n;
}

function extension_js_keys(string $language): array
{
    // Keys are written bare (key:) or quoted ('key':) depending on the file.
    preg_match_all('/^\s*["\']?([a-z0-9_]+)["\']?\s*:/m', file_get_contents(WALLOS_ROOT . "/scripts/i18n/$language.js"), $matches);
    return array_flip($matches[1]);
}

function extension_php_sources(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WALLOS_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_ends_with($path, '.php') && !preg_match('#/(docker|tests|libs|vendor|\.git|includes/i18n)/#', $path)) {
            $files[] = $path;
        }
    }
    return $files;
}

wallos_test('every PHP translation key exists in English and German', function () {
    $en = extension_translation_keys('en');
    $de = extension_translation_keys('de');
    $missing = [];
    foreach (extension_php_sources() as $file) {
        preg_match_all("/translate\\(\\s*'([a-z0-9_]+)'\\s*,\\s*\\\$(i18n|translations)/", file_get_contents($file), $matches);
        foreach (array_unique($matches[1]) as $key) {
            if (!isset($en[$key]) || !isset($de[$key])) {
                $missing[] = $key . ' (' . basename($file) . (isset($en[$key]) ? ', de' : ', en') . ')';
            }
        }
    }
    assert_same([], array_values(array_unique($missing)), 'missing PHP translation keys');
});

wallos_test('every JavaScript translation key exists in English and German', function () {
    $en = extension_js_keys('en');
    $de = extension_js_keys('de');
    $missing = [];
    foreach (array_merge(glob(WALLOS_ROOT . '/scripts/*.js'), extension_php_sources()) as $file) {
        preg_match_all('/\btranslate\(\s*["\']([a-z0-9_]+)["\']\s*\)/', file_get_contents($file), $matches);
        foreach (array_unique($matches[1]) as $key) {
            if (!isset($en[$key]) || !isset($de[$key])) {
                $missing[] = $key . ' (' . basename($file) . ')';
            }
        }
    }
    assert_same([], array_values(array_unique($missing)), 'missing JavaScript translation keys');
});

wallos_test('every JavaScript translation key exists in every language file', function () {
    // scripts/i18n/getlang.js has no English fallback: a missing key shows "[Translation Missing]".
    $used = [];
    foreach (array_merge(glob(WALLOS_ROOT . '/scripts/*.js'), extension_php_sources()) as $file) {
        preg_match_all('/\btranslate\(\s*["\']([a-z0-9_]+)["\']\s*\)/', file_get_contents($file), $matches);
        $used += array_flip($matches[1]);
    }
    $missing = [];
    foreach (glob(WALLOS_ROOT . '/scripts/i18n/*.js') as $file) {
        $language = basename($file, '.js');
        if ($language === 'getlang') {
            continue;
        }
        $keys = extension_js_keys($language);
        foreach (array_keys($used) as $key) {
            if (!isset($keys[$key])) {
                $missing[] = "$language: $key";
            }
        }
    }
    assert_same([], $missing, 'missing keys per language');
});

wallos_test('getlang.js holds only the translate function', function () {
    $source = file_get_contents(WALLOS_ROOT . '/scripts/i18n/getlang.js');
    assert_true(!preg_match('/^\s*[a-z0-9_]+\s*:/m', $source), 'no translation entries in getlang.js');
});
