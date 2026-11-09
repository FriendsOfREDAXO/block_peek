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
    // Run the real boot file: turning off iframe previews must not turn off the picker.
    rex::setProperty('redaxo', true);
    rex::setProperty('request', \Symfony\Component\HttpFoundation\Request::create('http://localhost/redaxo/index.php'));
    $user = new class extends rex_user {
        public function __construct() {}
        public function isAdmin() { return true; }
    };
    $boot = function (): void { require $this->getPath('boot.php'); };
    foreach ([
        ['', 'content/edit', true, '|1|', false],
        ['|1|', 'structure', true, '', false],
        ['|1|', 'content/edit', false, '', false],
        ['|1|', 'content/edit', true, '|1|', true],
        ['|1|', 'content/edit', true, '', true],
    ] as [$picker, $page, $loggedIn, $inactive, $expected]) {
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
        assert(array_key_exists('block_peek_picker', rex_view::getJsProperties()) === $expected);
        foreach (rex_view::getJsProperties()['block_peek_picker'] ?? [] as $preview) {
            assert(array_keys($preview) === ['description', 'svg'], 'module source code must not reach JS');
        }
    }

    $render = function (string $page): string {
        ob_start();
        try {
            require $this->getPath('pages/' . $page . '.php');
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
    assert($store->get($slug) === null, 'invalid CSRF must not create or edit descriptions');
    $_REQUEST = [rex_csrf_token::PARAM => rex_csrf_token::factory('block_peek_picker')->getValue()];
    $html = $render->call($addon, 'picker');
    assert($store->get($slug)['description'] === '<script>alert(1)</script>' && $store->get($slug)['locked']);
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

    $addon->setConfig('picker_php_path', '');
    assert(is_file(BackgroundRunner::phpBinary()) && is_executable(BackgroundRunner::phpBinary()), 'Herd CLI must resolve to executable PHP');
    $addon->setConfig('picker_php_path', '/custom/php');
    assert(BackgroundRunner::phpBinary() === '/custom/php');
    assert(!BackgroundRunner::start(null, false), 'an invalid PHP binary must not report a launch');

    rex_file::delete($statusPath);
    assert(BackgroundRunner::status() === ['running' => false]);
    rex_file::put($statusPath, '{broken');
    assert(BackgroundRunner::status() === ['running' => false]);
    rex_file::put($statusPath, '{"running":true,"done":2,"total":19,"log":["Hero: ok"]}');
    assert(BackgroundRunner::status() === ['running' => true, 'done' => 2, 'total' => 19, 'log' => ['Hero: ok']]);

    // A fake interpreter records argv without starting any AI generation.
    $binary = $dir . "php 'quoted'";
    $argsPath = $dir . 'args';
    rex_file::put($binary, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($argsPath) . "\n");
    chmod($binary, 0755);
    $addon->setConfig('picker_php_path', $binary);
    assert(BackgroundRunner::start(7, true));
    for ($attempt = 0; $attempt < 100 && !is_file($argsPath); ++$attempt) {
        usleep(20_000);
    }
    assert(rex_file::get($argsPath) === rex_path::base('bin/console') . "\nblock_peek:generate\n--module=7\n--force\n", 'arguments must survive shell escaping');
} finally {
    foreach ($statics as [$property, $value]) {
        $property->setValue(null, $value);
    }
    rex::setProperty('redaxo', $oldBackend);
    rex::setProperty('request', $oldRequest);
    $_POST = $oldPost;
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
