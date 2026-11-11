<?php

declare(strict_types=1);

use FriendsOfRedaxo\BlockPeek\Picker\AiPlatformProvider;
use FriendsOfRedaxo\BlockPeek\Picker\CliProvider;
use FriendsOfRedaxo\BlockPeek\Picker\ContextInliner;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewGenerator;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewStore;
use FriendsOfRedaxo\BlockPeek\Picker\Provider;
use FriendsOfRedaxo\BlockPeek\Picker\Providers;

// Run: php -d zend.assertions=1 bin/console massif:exec-php "require 'src/addons/block_peek/tests/AiPlatformTest.php';"
if (ini_get('zend.assertions') !== '1') {
    exit("Run with -d zend.assertions=1\n");
}

$dir = rex_path::addonCache('block_peek', 'test-ai-platform/');
$addon = rex_addon::get('block_peek');
$settings = [];
foreach (['picker_provider', 'picker_ai_profile', 'picker_prompt', 'picker_context_paths', 'picker_style_module', 'picker_reference_image', 'picker_language'] as $key) {
    $settings[$key] = [$addon->hasConfig($key), $addon->getConfig($key)];
}
rex_dir::delete($dir);

try {
    $moduleDir = $dir . 'module/';
    foreach (['module/input.php' => 'first', 'module/output.php' => 'second', 't/a.css' => 'a {}', 't/sub/b.css' => 'b {}'] as $path => $content) {
        assert(rex_file::put($dir . $path, $content));
    }
    $expected = [$moduleDir . 'input.php', $moduleDir . 'output.php', $dir . 't/a.css', $dir . 't/sub/b.css'];
    assert(ContextInliner::files($moduleDir, ['t/**'], $dir) === $expected);
    assert(ContextInliner::files($moduleDir, ['t/sub/*.css', 't/**', 'module/*.php', 'missing/**'], $dir) === $expected, 'glob matches must be sorted and deduplicated after module files');
    $relative = substr($moduleDir, strlen(rex_path::base()));
    $full = ContextInliner::inline($expected);
    assert(str_starts_with($full, "=== {$relative}input.php ===\nfirst\n=== {$relative}output.php ===\nsecond\n"));
    assert(!str_contains($full, 'size limit'));
    $pathProvider = (new ReflectionProperty(rex_path::class, 'pathprovider'))->getValue();
    assert(symlink(rex_path::base(), $dir . 'root-link'));
    $linkedBase = new class($dir . 'root-link/') extends rex_path_default_provider {
        public function __construct(private string $root) {}
        public function base($file) { return $this->root . $file; }
    };
    rex_path::init($linkedBase);
    try {
        assert(ContextInliner::inline([realpath($moduleDir . 'input.php')]) === "=== {$relative}input.php ===\nfirst\n", 'canonical paths must have relative headers under a symlinked project root');
    } finally {
        rex_path::init($pathProvider);
        unlink($dir . 'root-link');
    }
    $limited = ContextInliner::inline($expected, 10);
    assert(str_contains($limited, "=== {$relative}input.php ===\nfirst\n"));
    assert(str_contains($limited, '=== (further files omitted: size limit) ==='));
    assert(!str_contains($limited, 't/a.css'), 'stop after reaching the cap');
    assert(rex_file::put($dir . 'utf8.txt', 'ä'));
    assert(json_encode(ContextInliner::inline([$dir . 'utf8.txt'], 1)) !== false, 'truncation must preserve UTF-8 for API JSON encoding');

    $base = $dir . 'contained/';
    assert(rex_file::put($base . 'allowed/a.css', 'a {}'));
    assert(rex_file::put($dir . 'outside/secret.css', 'outside {}'));
    assert(symlink($dir . 'outside/secret.css', $base . 'allowed/link.css'));
    assert(symlink($dir . 'outside', $base . 'linked-dir'));
    assert(symlink($base . 'allowed', $base . 'allowed-alias'));
    assert(ContextInliner::files($moduleDir, ['../outside/*', '../outside/**', 'allowed/**', 'allowed/*', 'linked-dir/**'], $base) === [$base . 'allowed/a.css'], 'glob traversal and symlinks must not escape the canonical base');
    assert(ContextInliner::files($moduleDir, ['*.css'], $base . 'allowed-alias') === [$base . 'allowed/a.css'], 'the base itself must be canonicalized');

    foreach (['.env', 'secret.pem', 'config.yml', '.hidden/x.css', 'sub/.env', '.secret.css'] as $path) {
        assert(rex_file::put($base . 'allowed/' . $path, 'secret'));
    }
    assert(rex_file::put($base . 'allowed/b.CSS', 'b {}'));
    $sourceFiles = [$base . 'allowed/a.css', $base . 'allowed/b.CSS'];
    assert(ContextInliner::files($moduleDir, ['allowed/**'], $base) === $sourceFiles, 'recursive context must exclude secrets, unsupported extensions and hidden paths');
    assert(ContextInliner::files($moduleDir, ['allowed/*', 'allowed/.*', 'allowed/.hidden/*.css', 'allowed/sub/.*'], $base) === $sourceFiles, 'plain globs must enforce the same source-file policy');

    foreach (['reference.css' => 'secret.pem', 'hidden.css' => '.hidden/x.css', 'dotfile.css' => '.secret.css', 'hidden.pem' => 'a.css', '.alias.css' => 'a.css'] as $alias => $target) {
        assert(symlink($base . 'allowed/' . $target, $base . 'allowed/' . $alias));
    }
    foreach (['allowed/**', 'allowed/*', 'allowed/.*'] as $pattern) {
        assert(ContextInliner::files($moduleDir, [$pattern], $base) === ($pattern === 'allowed/.*' ? [] : $sourceFiles), 'both symlink name and target must satisfy extension and dotfile filters: ' . $pattern);
    }

    $largeFile = $dir . 'large.css';
    assert(rex_file::put($largeFile, str_repeat('a', 2_000_000)));
    memory_reset_peak_usage();
    $memoryBefore = memory_get_usage();
    $largeInline = ContextInliner::inline([$largeFile], 100);
    assert(memory_get_peak_usage() - $memoryBefore < 262_144, 'a tiny context budget must not allocate the entire 2 MB file');
    $largePath = substr($largeFile, strlen(rex_path::base()));
    assert($largeInline === "=== {$largePath} ===\n" . str_repeat('a', 100) . "\n=== (further files omitted: size limit) ===");

    assert(rex_file::put($dir . 'binary.css', "\x00\xFF"));
    assert(rex_file::put($dir . 'nul.css', "a\x00b"));
    assert(rex_file::put($dir . 'latin1.css', "caf\xE9"));
    $textInline = ContextInliner::inline([$dir . 'binary.css', $dir . 'nul.css', $dir . 'latin1.css', $dir . 't/a.css'], 4);
    assert(!str_contains($textInline, 'binary.css') && !str_contains($textInline, 'nul.css') && !str_contains($textInline, 'latin1.css'), 'binary and invalid UTF-8 files must be skipped');
    assert(str_contains($textInline, 'a {}') && json_encode($textInline) !== false, 'skipped files must leave valid JSON context and budget for text files');

    $captured = [];
    $response = '{"description":"Inline test","svg":"<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 1440 900\"><rect width=\"10\" height=\"10\"/></svg>"}';
    $invoke = static function (string $prompt, ?string $image) use (&$captured, $response): string {
        $captured[] = [$prompt, $image];
        return $response;
    };
    $provider = new AiPlatformProvider(1, $invoke, 'Test profile');
    assert($provider->label() === 'ai_platform:Test profile' && $provider->inlineContext());
    assert($provider->generate('image prompt', '/tmp/reference.png') === $response);
    assert($captured === [['image prompt', '/tmp/reference.png']]);
    $captured = [];
    assert(!rex_addon::get('ai_platform')->isAvailable(), 'this integration test requires ai_platform to be absent');
    assert((new AiPlatformProvider(7, $invoke))->label() === 'ai_platform:7');
    try {
        (new AiPlatformProvider(1))->generate('test', null);
        assert(false, 'default invocation must reject an unavailable addon');
    } catch (RuntimeException $e) {
        assert(str_contains($e->getMessage(), 'ai_platform'));
    }

    $addon->setConfig('picker_prompt', 'Module: {module_name}');
    $contextFile = rex_path::relative(rex_path::addon('block_peek', 'boot.php')); // exists in every install, unlike project fragments
    $addon->setConfig('picker_context_paths', $contextFile);
    $addon->setConfig('picker_style_module', '');
    $addon->setConfig('picker_reference_image', '');
    $addon->setConfig('picker_language', 'de');
    $module = PreviewGenerator::modules()[0];
    $store = new PreviewStore($dir . 'previews/');
    $log = static function (string $line): void {};
    assert((new PreviewGenerator($provider, $store))->run($module['id'], true, $log) === ['generated' => 1, 'skipped' => 0, 'failed' => 0]);
    $entry = $store->get(PreviewStore::slug($module['id'], $module['key']));
    assert($entry['status'] === 'ok' && $entry['model'] === 'ai_platform:Test profile');
    assert(count($captured) === 1 && $captured[0][1] === null);
    assert(str_contains($captured[0][0], 'The files below are included inline and replace the file reads described above. Do not try to read files.'));
    assert(str_contains($captured[0][0], 'output.php ==='));
    assert(str_contains($captured[0][0], "=== $contextFile ==="), 'default context files must be included, not just module code');

    $cli = new class(new CliProvider(__DIR__ . '/fixtures/fake-cli.sh', '{bin} ok', 'stdout', 'm', 'e')) implements Provider {
        public string $prompt = '';
        public function __construct(private CliProvider $inner) {}
        public function generate(string $prompt, ?string $imagePath): string
        {
            $this->prompt = $prompt;
            return $this->inner->generate($prompt, $imagePath);
        }
        public function label(): string { return $this->inner->label(); }
        public function inlineContext(): bool { return $this->inner->inlineContext(); }
    };
    assert((new PreviewGenerator($cli, $store))->run($module['id'], true, $log)['generated'] === 1);
    assert(!str_contains($cli->prompt, 'files below are included inline') && !str_contains($cli->prompt, 'output.php ==='));

    rex_config::set('block_peek', 'picker_provider', 'ai_platform');
    rex_config::set('block_peek', 'picker_ai_profile', 1);
    assert(Providers::fromConfig() === null);
} finally {
    foreach (['contained/allowed/link.css', 'contained/linked-dir', 'contained/allowed-alias', 'contained/allowed/reference.css', 'contained/allowed/hidden.css', 'contained/allowed/dotfile.css', 'contained/allowed/hidden.pem', 'contained/allowed/.alias.css'] as $link) {
        if (is_link($dir . $link)) {
            unlink($dir . $link);
        }
    }
    foreach ($settings as $key => [$exists, $value]) {
        $exists ? $addon->setConfig($key, $value) : $addon->removeConfig($key);
    }
    rex_config::save();
    rex_dir::delete($dir);
}
