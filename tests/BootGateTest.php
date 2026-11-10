<?php

declare(strict_types=1);

use FriendsOfRedaxo\BlockPeek\Picker\BackgroundRunner;
use FriendsOfRedaxo\BlockPeek\Picker\PickerBoot;

// Run: php -d zend.assertions=1 bin/console massif:exec-php "require 'src/addons/block_peek/tests/BootGateTest.php';"
if (ini_get('zend.assertions') !== '1') {
    exit("Run with -d zend.assertions=1\n");
}

assert(PickerBoot::shouldLoad('|1|', 'content/edit', true));
assert(!PickerBoot::shouldLoad('', 'content/edit', true));
assert(!PickerBoot::shouldLoad(null, 'content/edit', true));
assert(!PickerBoot::shouldLoad('|1|', 'structure', true));
assert(!PickerBoot::shouldLoad('|1|', 'content/edit', false));
assert((new ReflectionMethod(PickerBoot::class, 'shouldLoad'))->getNumberOfParameters() === 3, 'iframe inactivity is not a picker gate input');

$addon = rex_addon::get('block_peek');
$hadPath = $addon->hasConfig('picker_php_path');
$oldPath = $addon->getConfig('picker_php_path');
$statusPath = rex_path::addonCache('block_peek', 'picker/status.json');
$oldStatus = rex_file::get($statusPath);
$dir = rex_path::addonCache('block_peek', 'test-boot/');
$oldLog = rex_file::get(rex_path::addonCache('block_peek', 'picker/run.log'));
$settings = [];
foreach (['picker', 'inactive'] as $key) {
    $settings[$key] = [$addon->hasConfig($key), $addon->getConfig($key)];
}
$oldUser = rex::getUser();
$oldBackend = rex::isBackend();
$oldPage = rex_be_controller::getCurrentPage();
$oldRequest = rex::getProperty('request');
$oldPost = $_POST;
$oldGet = $_GET;
require_once __DIR__ . '/fixtures/PickerPageHarness.php';
$oldLogger = rex_logger::getFactoryClass();
rex_logger::setFactoryClass(\BlockPeekTests\CapturingLogger::class);
$oldRequestVars = $_REQUEST;
$indexPath = rex_path::addonData('block_peek', 'previews/previews.json');
$oldIndex = rex_file::get($indexPath);
$svgPath = null;
$oldSvg = null;
$statics = [];
foreach ([rex_view::class => ['jsFiles', 'cssFiles', 'jsProperties'], rex_extension::class => ['extensions']] as $class => $names) {
    foreach ($names as $name) {
        $property = new ReflectionProperty($class, $name);
        $statics[] = [$property, $property->getValue()];
    }
}

try {
    $_POST = $_GET = $_REQUEST = [];
    // Run the real boot file: turning off iframe previews must not turn off the picker.
    rex::setProperty('redaxo', true);
    rex::setProperty('request', \Symfony\Component\HttpFoundation\Request::create('http://localhost/redaxo/index.php'));
    $user = new class extends rex_user {
        public function __construct() {}
        public function isAdmin() { return true; }
    };
    $boot = function (): void { require $this->getPath('boot.php'); };
    $firstModule = \FriendsOfRedaxo\BlockPeek\Picker\PreviewGenerator::modules()[0];
    $firstSlug = \FriendsOfRedaxo\BlockPeek\Picker\PreviewStore::slug($firstModule['id'], $firstModule['key']);
    foreach ([
        ['', 'content/edit', true, '|1|', false, null],
        ['|1|', 'structure', true, '', false, null],
        ['|1|', 'content/edit', false, '', false, null],
        ['|1|', 'content/edit', true, '|1|', true, null],
        ['|1|', 'content/edit', true, '', true, null],
        ['|1|', 'content/edit', true, '', true, '{broken'],
        ['|1|', 'content/edit', true, '', true, json_encode([$firstSlug => 42])],
        ['|1|', 'content/edit', true, '', true, json_encode([$firstSlug => []])],
        ['|1|', 'content/edit', true, '', true, json_encode([$firstSlug => ['description' => []]])],
    ] as [$picker, $page, $loggedIn, $inactive, $expected, $corruptIndex]) {
        $loggedBefore = count(\BlockPeekTests\CapturingLogger::$messages);
        if ($corruptIndex !== null) {
            rex_file::put($indexPath, $corruptIndex);
        }
        foreach ($statics as [$property]) {
            $property->setValue(null, []);
        }
        $addon->setConfig('picker', $picker);
        $addon->setConfig('inactive', $inactive);
        rex::setProperty('user', $loggedIn ? $user : null);
        rex_be_controller::setCurrentPage($page);
        $boot->call($addon);
        rex_extension::registerPoint(new rex_extension_point('PACKAGES_INCLUDED'));
        assert(in_array($addon->getAssetsUrl('BlockPeekPicker.js'), rex_view::getJsFiles(), true) === $expected);
        assert(in_array($addon->getAssetsUrl('BlockPeekPicker.css'), rex_view::getCssFiles()['all'] ?? [], true) === $expected);
        assert(array_key_exists('block_peek_picker', rex_view::getJsProperties()) === ($expected && $corruptIndex === null));
        assert(array_key_exists('block_peek_picker_i18n', rex_view::getJsProperties()) === $expected, 'search translations must use the picker gate');
        if ($expected) {
            assert(rex_view::getJsProperties()['block_peek_picker_i18n'] === [
                'search_label' => rex_i18n::msg('block_peek_picker_search_label'),
                'search_placeholder' => rex_i18n::msg('block_peek_picker_search_placeholder'),
            ]);
        }
        if ($corruptIndex !== null) {
            assert(count(\BlockPeekTests\CapturingLogger::$messages) > $loggedBefore, 'corrupt preview data must be logged without aborting boot');
            $oldIndex === null ? rex_file::delete($indexPath) : rex_file::put($indexPath, $oldIndex);
        }
        foreach (rex_view::getJsProperties()['block_peek_picker'] ?? [] as $preview) {
            assert(array_keys($preview) === ['description', 'svg'], 'module source code must not reach JS');
        }
    }

    $render = function (string $page): string {
        \BlockPeekTests\rex_response::$redirect = null;
        ob_start();
        try {
            try {
                if ($page === 'picker') {
                    eval(\BlockPeekTests\pickerPageSource($this->getPath('pages/picker.php')));
                } else {
                    require $this->getPath('pages/' . $page . '.php');
                }
            } catch (\BlockPeekTests\PickerRedirect) {
            }
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    };
    rex::setProperty('user', $user);
    rex_be_controller::setCurrentPage('block_peek/picker');
    $addon->setConfig('picker', '');
    $html = $render->call($addon, 'picker');
    assert(str_contains($html, 'block_peek/settings') && !str_contains($html, '<table'), 'disabled picker must only link to settings');
    $_POST = ['picker_action' => 'save'];
    $render->call($addon, 'picker');
    assert(str_contains((string) \BlockPeekTests\rex_response::$redirect, 'picker_message=disabled'), 'POST after disabling the picker must redirect to GET');
    $_POST = [];
    $settings['picker_provider'] = [$addon->hasConfig('picker_provider'), $addon->getConfig('picker_provider')];
    $addon->setConfig('picker_provider', 'none');
    $addon->setConfig('picker', '|1|');
    $html = $render->call($addon, 'picker');
    assert(substr_count($html, '<th scope="row">') === count(\FriendsOfRedaxo\BlockPeek\Picker\PreviewGenerator::modules()));
    assert(!str_contains($html, '<form'), 'without a provider the page must be read-only');
    $html = $render->call($addon, 'settings');
    assert(str_contains($html, 'picker_context_paths') && str_contains($html, 'picker_prompt'));
    assert(!str_contains($html, 'name="picker_ai_profile"'), 'ai_platform is absent in this installation');
    assert(str_contains($html, '{reference_image_sentence}'), 'prompt placeholders must be listed');

    foreach (['auto' => 'gpt-6.1-sol / opus', '' => 'gpt-6.1-sol / opus', 'claude' => 'opus', 'codex' => 'gpt-6.1-sol', 'ai_platform' => ''] as $provider => $placeholder) {
        $addon->setConfig('picker_provider', $provider);
        $settingsHtml = $render->call($addon, 'settings');
        assert(preg_match('/<input[^>]*name="[^"]*picker_model[^"]*"[^>]*>/', $settingsHtml, $modelField) === 1);
        assert($placeholder === '' ? !str_contains($modelField[0], 'placeholder=') : str_contains($modelField[0], 'placeholder="' . $placeholder . '"'));
    }

    foreach (['picker_cli_path', 'picker_command'] as $key) {
        $settings[$key] = [$addon->hasConfig($key), $addon->getConfig($key)];
    }
    $addon->setConfig('picker_provider', 'codex');
    $addon->setConfig('picker_cli_path', __DIR__ . '/fixtures/fake-cli.sh');
    $addon->setConfig('picker_command', '{bin} ok');
    $module = \FriendsOfRedaxo\BlockPeek\Picker\PreviewGenerator::modules()[0];
    $slug = \FriendsOfRedaxo\BlockPeek\Picker\PreviewStore::slug($module['id'], $module['key']);
    $store = new \FriendsOfRedaxo\BlockPeek\Picker\PreviewStore();
    $entries = $store->all();
    unset($entries[$slug]);
    rex_file::put($indexPath, json_encode($entries, JSON_THROW_ON_ERROR));
    $_POST = ['picker_action' => 'save', 'picker_slug' => $slug, 'picker_description' => '<script>alert(1)</script>', 'picker_locked' => '1'];
    $_REQUEST = [rex_csrf_token::PARAM => 'invalid'];
    $render->call($addon, 'picker');
    assert(str_contains((string) \BlockPeekTests\rex_response::$redirect, 'picker_message=csrf_error'), 'invalid POST must redirect to GET');
    assert($store->get($slug) === null, 'invalid CSRF must not create or edit descriptions');
    $_REQUEST = [rex_csrf_token::PARAM => rex_csrf_token::factory('block_peek_picker')->getValue()];
    $html = $render->call($addon, 'picker');
    assert($store->get($slug)['description'] === '<script>alert(1)</script>' && $store->get($slug)['locked']);
    assert(str_contains((string) \BlockPeekTests\rex_response::$redirect, 'picker_message=saved'), 'save POST must redirect to GET');
    $_POST = [];
    $_GET = ['picker_message' => 'saved'];
    $html = $render->call($addon, 'picker');
    assert(str_contains($html, rex_escape(rex_i18n::msg('block_peek_picker_saved'))), 'GET must render the flash message');
    $_GET = [];
    assert(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($html, '<script>alert(1)</script>'), 'description output must be escaped');
    $svgPath = rex_path::addonData('block_peek', 'previews/' . rawurlencode($slug) . '.svg');
    $oldSvg = rex_file::get($svgPath);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(2)</script></svg>';
    $store->put($slug, $store->get($slug), $svg);
    $_POST = [];
    $html = $render->call($addon, 'picker');
    assert(str_contains($html, 'src="data:image/svg+xml;charset=utf-8,' . rawurlencode($svg) . '"'));
    assert(!str_contains($html, '<svg') && !str_contains($html, '<script>alert(2)'), 'stored SVG must only render as an image');
    $_POST = ['picker_action' => 'force'];
    $_REQUEST = [rex_csrf_token::PARAM => 'invalid'];
    $render->call($addon, 'picker');
    assert(rex_file::get(rex_path::addonCache('block_peek', 'picker/run.log')) === $oldLog, 'invalid CSRF must not launch generation');
    $_POST = [];
    $_REQUEST = [];

    $runningStatus = ['running' => true, 'pid' => getmypid(), 'started' => time(), 'done' => 2, 'total' => 19];
    rex_file::put($statusPath, json_encode($runningStatus, JSON_THROW_ON_ERROR));
    $html = $render->call($addon, 'picker');
    assert(!str_contains($html, '<form') && !str_contains($html, '<button'), 'running picker must hide editing and generation controls');
    assert(str_contains($html, '2/19') && str_contains($html, 'location.replace(') && !str_contains($html, 'location.reload('), 'running page must refresh a GET URL');
    assert(str_contains($html, json_encode(rex_url::currentBackendPage([], false), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)), 'refresh must target the current GET page');
    $beforeSave = $store->get($slug);
    $_REQUEST = [rex_csrf_token::PARAM => rex_csrf_token::factory('block_peek_picker')->getValue()];
    foreach (['save', 'generate', 'force'] as $action) {
        $_POST = ['picker_action' => $action, 'picker_slug' => $slug, 'picker_description' => 'Rejected'];
        $render->call($addon, 'picker');
        assert(str_contains((string) \BlockPeekTests\rex_response::$redirect, 'picker_message=running'), "$action during a run must redirect with a running message");
        assert($store->get($slug) === $beforeSave, 'running saves must not edit descriptions');
    }
    rex_file::delete($statusPath);
    $addon->setConfig('picker_php_path', '/missing/php');
    foreach (['generate', 'force'] as $action) {
        $_POST = ['picker_action' => $action];
        $render->call($addon, 'picker');
        assert(str_contains((string) \BlockPeekTests\rex_response::$redirect, 'picker_message=start_error'), "$action failure must redirect to GET");
    }
    $_POST = [];
    $_REQUEST = [];

    $addon->setConfig('picker_php_path', '');
    assert(is_file(BackgroundRunner::phpBinary()) && is_executable(BackgroundRunner::phpBinary()), 'Herd CLI must resolve to executable PHP');
    $addon->setConfig('picker_php_path', '/custom/php');
    assert(BackgroundRunner::phpBinary() === '/custom/php');
    assert(!BackgroundRunner::start(null, false), 'an invalid PHP binary must not report a launch');

    rex_file::delete($statusPath);
    assert(BackgroundRunner::status() === ['running' => false]);
    rex_file::put($statusPath, '{broken');
    assert(BackgroundRunner::status() === ['running' => false]);
    $activeStatus = ['running' => true, 'pid' => getmypid(), 'started' => time(), 'done' => 2, 'total' => 19, 'log' => ['Hero: ok']];
    rex_file::put($statusPath, json_encode($activeStatus, JSON_THROW_ON_ERROR));
    assert(BackgroundRunner::status() === $activeStatus);
    if (function_exists('posix_kill') && !posix_kill(1, 0) && posix_get_last_error() === 1) {
        rex_file::put($statusPath, json_encode(['pid' => 1] + $activeStatus, JSON_THROW_ON_ERROR));
        assert(BackgroundRunner::status()['running'], 'EPERM means the process is alive');
    }
    $deadStatus = ['pid' => 2147483647] + $activeStatus;
    rex_file::put($statusPath, json_encode($deadStatus, JSON_THROW_ON_ERROR));
    assert(!BackgroundRunner::status()['running'], 'dead generation PID must stop auto-refresh');
    rex_file::delete($statusPath);

    $resolve = new ReflectionMethod(BackgroundRunner::class, 'phpBinaryFor');
    foreach (['php84-fpm' => 'php84', 'php-fpm' => 'php'] as $fpm => $cli) {
        $sibling = $dir . 'herd/' . $cli;
        rex_file::put($sibling, "#!/bin/sh\nexit 0\n");
        chmod($sibling, 0755);
        assert($resolve->invoke(null, $dir . 'herd/' . $fpm, '/missing') === $sibling, 'FPM must prefer the executable sibling CLI');
        chmod($sibling, 0644);
        assert($resolve->invoke(null, $dir . 'herd/' . $fpm, '/fallback') === '/fallback/php');
    }
    assert($resolve->invoke(null, '/cli/php', '/fallback') === '/cli/php');

    // A fake interpreter records argv without starting any AI generation.
    $binary = $dir . "php 'quoted'";
    $argsPath = $dir . 'args';
    rex_file::put($binary, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($argsPath) . "\nsleep 1\n");
    chmod($binary, 0755);
    $addon->setConfig('picker_php_path', $binary);
    rex_file::put($statusPath, json_encode($activeStatus, JSON_THROW_ON_ERROR));
    rex_file::put(rex_path::addonCache('block_peek', 'picker/run.log'), 'keep existing log');
    assert(!BackgroundRunner::start(7, true), 'an active run must reject another launch');
    assert(!is_file($argsPath) && rex_file::get(rex_path::addonCache('block_peek', 'picker/run.log')) === 'keep existing log');
    rex_file::delete($statusPath);
    $pathProviderProperty = new ReflectionProperty(rex_path::class, 'pathprovider');
    $pathProvider = $pathProviderProperty->getValue();
    // Model standard REDAXO where the console is below redaxo/bin, separate from base/bin.
    $consoleProvider = new class($dir, $pathProvider) extends rex_path_default_provider {
        public function __construct(private string $dir, private rex_path_default_provider $original) {}
        public function base($file) { return $this->original->base($file); }
        public function addonCache($addon, $file) { return $this->original->addonCache($addon, $file); }
        public function bin($file = '') { return $this->dir . 'redaxo/bin/' . $file; }
    };
    rex_file::put($consoleProvider->bin('console'), "<?php\n");
    rex_path::init($consoleProvider);
    try {
        assert(BackgroundRunner::start(7, true));
        $started = BackgroundRunner::status();
        assert($started['running'] && $started['pid'] > 0 && $started['started'] >= time() - 1);
        assert($started['current'] === '' && $started['done'] === 0 && $started['total'] === 0 && $started['log'] === []);
        assert(!BackgroundRunner::start(7, true), 'a second immediate start must be refused');
    } finally {
        rex_path::init($pathProvider);
    }
    for ($attempt = 0; $attempt < 100 && !is_file($argsPath); ++$attempt) {
        usleep(20_000);
    }
    assert(rex_file::get($argsPath) === $consoleProvider->bin('console') . "\nblock_peek:generate\n--module=7\n--force\n", 'arguments must survive shell escaping');
    $_REQUEST = [rex_csrf_token::PARAM => rex_csrf_token::factory('block_peek_picker')->getValue()];
    foreach (['generate', 'force'] as $action) {
        rex_file::delete($statusPath);
        rex_file::delete($argsPath);
        $_POST = ['picker_action' => $action];
        $render->call($addon, 'picker');
        assert(str_contains((string) \BlockPeekTests\rex_response::$redirect, 'picker_message=started'), "$action success must redirect to GET");
        for ($attempt = 0; $attempt < 100 && !is_file($argsPath); ++$attempt) {
            usleep(20_000);
        }
        assert(rex_file::get($argsPath) === rex_path::bin('console') . "\nblock_peek:generate\n" . ($action === 'force' ? "--force\n" : ''), 'POST must launch the requested generation mode');
    }
    usleep(1_100_000);
    $_POST = [];
    $_GET = ['picker_message' => 'started'];
    $html = $render->call($addon, 'picker');
    assert(str_contains($html, 'location.replace('), 'redirected start page must refresh even before status.json appears');
    $_GET = [];

} finally {
    rex_logger::setFactoryClass($oldLogger);
    foreach ($statics as [$property, $value]) {
        $property->setValue(null, $value);
    }
    rex::setProperty('redaxo', $oldBackend);
    rex::setProperty('request', $oldRequest);
    $_POST = $oldPost;
    $_GET = $oldGet;
    $_REQUEST = $oldRequestVars;
    $oldIndex === null ? rex_file::delete($indexPath) : rex_file::put($indexPath, $oldIndex);
    if ($svgPath !== null) {
        $oldSvg === null ? rex_file::delete($svgPath) : rex_file::put($svgPath, $oldSvg);
    }
    rex::setProperty('user', $oldUser);
    rex_be_controller::setCurrentPage($oldPage ?? '');
    foreach ($settings as $key => [$exists, $value]) {
        $exists ? $addon->setConfig($key, $value) : $addon->removeConfig($key);
    }
    $hadPath ? $addon->setConfig('picker_php_path', $oldPath) : $addon->removeConfig('picker_php_path');
    $oldStatus === null ? rex_file::delete($statusPath) : rex_file::put($statusPath, $oldStatus);
    $logPath = rex_path::addonCache('block_peek', 'picker/run.log');
    $oldLog === null ? rex_file::delete($logPath) : rex_file::put($logPath, $oldLog);
    rex_dir::delete($dir);
    rex_config::save();
    assert(rex_file::get($indexPath) === $oldIndex && rex_file::get($statusPath) === $oldStatus && rex_file::get($logPath) === $oldLog, 'test must restore preview and run files');
}
