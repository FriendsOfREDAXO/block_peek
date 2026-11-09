<?php

declare(strict_types=1);

use FriendsOfRedaxo\BlockPeek\Picker\CliProvider;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewGenerator as G;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewStore as S;
use FriendsOfRedaxo\BlockPeek\Picker\Provider;
use FriendsOfRedaxo\BlockPeek\Picker\Providers;
use FriendsOfRedaxo\BlockPeek\Command\GenerateCommand;
use Symfony\Component\Console\Tester\CommandTester;

// Run: php -d zend.assertions=1 bin/console massif:exec-php "require 'src/addons/block_peek/tests/GeneratorTest.php';"
if (ini_get('zend.assertions') !== '1') {
    exit("Run with -d zend.assertions=1\n");
}

$dir = rex_path::addonCache('block_peek', 'test-generator/');
$fake = __DIR__ . '/fixtures/fake-cli.sh';
$addon = rex_addon::get('block_peek');
$settings = [];
foreach (['picker', 'picker_provider', 'picker_cli_path', 'picker_command', 'picker_model', 'picker_effort', 'picker_prompt', 'picker_context_paths', 'picker_style_module', 'picker_reference_image', 'picker_language'] as $key) {
    $settings[$key] = [$addon->hasConfig($key), $addon->getConfig($key)];
}
$lock = null;
rex_dir::delete($dir);

try {
    $modules = G::modules();
    assert(count($modules) >= 2, 'requires two real site modules');
    $m = $modules[0];
    $id = $m['id'];
    $slug = S::slug($id, $m['key']);
    $s = new S($dir);
    $log = [];
    $logger = static function (string $line) use (&$log): void { $log[] = $line; };
    $cli = new CliProvider($fake, '{bin} ok', 'stdout', 'm', 'e');
    $g = new G($cli, $s);

    assert($g->run($id, false, $logger) === ['generated' => 1, 'skipped' => 0, 'failed' => 0]);
    $entry = $s->get($slug);
    assert($entry['status'] === 'ok' && $entry['description'] === 'Test');
    assert($entry['hash'] === S::hash($m['input'], $m['output']));
    assert($entry['model'] === $cli->label() && $cli->inlineContext() === false);
    assert(str_starts_with($s->svg($slug), '<svg'));
    $moduleDir = rex_path::addonCache('block_peek', "picker/module-$id/");
    assert(rex_file::get($moduleDir . 'input.php') === $m['input']);
    assert(rex_file::get($moduleDir . 'output.php') === $m['output']);
    assert($g->run($id, false, $logger)['skipped'] === 1);
    assert($g->run($id, true, $logger)['generated'] === 1);

    $s->setDescription($slug, 'Hand', true);
    assert($g->run($id, true, $logger)['generated'] === 1);
    assert($s->get($slug)['description'] === 'Hand' && $s->get($slug)['locked'] === true);
    $svg = $s->svg($slug);

    foreach (['garbage', 'badsvg'] as $mode) {
        $counted = new class(new CliProvider($fake, '{bin} ' . $mode, 'stdout', 'm', 'e')) implements Provider {
            public int $calls = 0;
            public function __construct(private Provider $inner) {}
            public function generate(string $prompt, ?string $imagePath): string
            {
                ++$this->calls;
                return $this->inner->generate($prompt, $imagePath);
            }
            public function label(): string { return $this->inner->label(); }
            public function inlineContext(): bool { return false; }
        };
        assert((new G($counted, $s))->run($id, true, $logger)['failed'] === 1);
        assert($counted->calls === 2, "$mode must retry exactly once");
        assert($s->get($slug)['status'] === 'failed' && $s->svg($slug) === $svg);
        assert($s->get($slug)['description'] === 'Hand');
    }
    assert($g->run($id, false, $logger)['generated'] === 1, 'failed entries must be retried without force');

    $start = microtime(true);
    try {
        (new CliProvider($fake, '{bin} slow', 'stdout', 'm', 'e', 1))->generate('test', null);
        assert(false, 'timeout must throw');
    } catch (RuntimeException $e) {
        assert(str_contains(strtolower($e->getMessage()), 'timeout'));
        assert(microtime(true) - $start < 3, 'timeout must not wait for the child to finish');
    }
    assert(G::parse('x {"description":"d","svg":"<svg/>"} y')['svg'] === '<svg/>');
    assert(G::parse('<svg a></svg>')['description'] === '');
    assert(G::parse('Sorry, no.') === null);
    assert(G::parse('{"description":[],"svg":false}') === null);

    assert((new CliProvider($fake, '{bin} ok > {out}', 'file', 'm', 'e'))->generate('test', null) === $cli->generate('test', null));
    $jsonCommand = '{bin} -r ' . escapeshellarg('stream_get_contents(STDIN); echo json_encode(["result" => "result text"]);');
    assert((new CliProvider(PHP_BINARY, $jsonCommand, 'json', 'm', 'e'))->generate('test', null) === 'result text');
    $errorCommand = '{bin} -r ' . escapeshellarg('fwrite(STDERR, "test failure"); exit(7);');
    try {
        (new CliProvider(PHP_BINARY, $errorCommand, 'stdout', 'm', 'e'))->generate('test', null);
        assert(false, 'nonzero exit must throw');
    } catch (RuntimeException $e) {
        assert(str_contains($e->getMessage(), 'test failure'));
    }
    $argsCommand = '{bin} -r ' . escapeshellarg('stream_get_contents(STDIN); echo json_encode(array_slice($argv, 1));') . ' -- {model} {effort} {image_args}';
    $hostile = "a' ; echo injected; #";
    assert(json_decode((new CliProvider(PHP_BINARY, $argsCommand, 'stdout', $hostile, 'e'))->generate('test', $hostile), true) === [$hostile, 'e', '-i', $hostile]);
    assert(CliProvider::detect('missing-block-peek-cli', $fake) === realpath($fake));
    assert(CliProvider::detect('missing-block-peek-cli') === null);

    $addon->setConfig('picker_provider', 'none');
    assert(Providers::fromConfig() === null);
    foreach (['codex', 'claude', 'auto'] as $provider) {
        $addon->setConfig('picker_provider', $provider);
        $addon->setConfig('picker_cli_path', $fake);
        $addon->setConfig('picker_command', '{bin} ok');
        $addon->setConfig('picker_model', 'm');
        $addon->setConfig('picker_effort', 'e');
        assert(Providers::fromConfig()->generate('test', null) === $cli->generate('test', null));
    }

    $addon->setConfig('picker_prompt', '{module_dir}|{module_name}|{context_paths}|{style_reference_block}|{reference_image_sentence}|{language}');
    $addon->setConfig('picker_context_paths', "a/**\nb.css");
    $addon->setConfig('picker_style_module', $slug);
    $addon->setConfig('picker_reference_image', 'src/addons/block_peek/tests/fixtures/fake-cli.sh');
    $addon->setConfig('picker_language', 'de');
    $inspector = new class($cli) implements Provider {
        public string $prompt = '';
        public ?string $image = null;
        public function __construct(private Provider $inner) {}
        public function generate(string $prompt, ?string $imagePath): string
        {
            $this->prompt = $prompt;
            $this->image = $imagePath;
            return $this->inner->generate($prompt, $imagePath);
        }
        public function label(): string { return 'inspected'; }
        public function inlineContext(): bool { return false; }
    };
    assert((new G($inspector, $s))->run($id, true, $logger)['generated'] === 1);
    assert(str_contains($inspector->prompt, rtrim($moduleDir, '/')) && str_contains($inspector->prompt, $m['name']));
    assert(str_contains($inspector->prompt, 'a/**, b.css') && str_contains($inspector->prompt, $svg));
    assert(str_ends_with($inspector->prompt, '|de') && $inspector->image === realpath($fake));

    unlink($moduleDir . 'input.php');
    mkdir($moduleDir . 'input.php');
    try {
        assert(@$g->run($id, true, $logger)['failed'] === 1);
        assert($s->get($slug)['status'] === 'failed', 'module cache write failures must mark the preview failed');
        assert($s->svg($slug) === $svg);
    } finally {
        @rmdir($moduleDir . 'input.php');
    }

    // A broken index must fail each module, never abort the batch or erase the index.
    rex_file::put($dir . 'previews.json', 'broken');
    assert($g->run(null, true, $logger)['failed'] === count($modules));
    assert(rex_file::get($dir . 'previews.json') === 'broken');
    rex_file::delete($dir . 'previews.json');
    $addon->setConfig('picker_style_module', '');
    $s->put('999999999', $entry, $svg);
    $writeFailure = new class($cli, $dir) implements Provider {
        private int $calls = 0;
        public function __construct(private Provider $inner, private string $dir) {}
        public function generate(string $prompt, ?string $imagePath): string
        {
            chmod($this->dir . 'previews.json', ++$this->calls === 1 ? 0444 : 0644);
            return $this->inner->generate($prompt, $imagePath);
        }
        public function label(): string { return 'write-failure'; }
        public function inlineContext(): bool { return false; }
    };
    $counts = (new G($writeFailure, $s))->run(null, true, $logger);
    assert($counts['failed'] === 1 && $counts['generated'] === count($modules) - 1, 'a store write failure must not stop the next module');
    assert($s->get('999999999') === null && $s->svg('999999999') === null, 'full run must remove numeric orphan slugs');
    $status = json_decode(rex_file::get(rex_path::addonCache('block_peek', 'picker/status.json')), true, 512, JSON_THROW_ON_ERROR);
    assert($status['running'] === false && $status['done'] === count($modules) && $status['total'] === count($modules));
    assert(is_int($status['started']) && $status['current'] === $modules[array_key_last($modules)]['name']);
    assert(count($status['log']) <= 50 && $status['log'] === array_slice($log, -count($status['log'])));

    $command = new CommandTester(new GenerateCommand());
    $addon->setConfig('picker', '');
    assert($command->execute([]) === 0 && str_contains($command->getDisplay(), 'deaktiviert'));
    $addon->setConfig('picker', '|1|');
    $addon->setConfig('picker_provider', 'none');
    assert($command->execute([]) === 1 && str_contains($command->getDisplay(), 'Provider'));
    assert($command->execute(['--module' => 'invalid']) === 1);

    // Hold the lock before starting the real command, so this can never invoke a real model.
    rex_dir::create(rex_path::addonCache('block_peek', 'picker/'));
    $lock = fopen(rex_path::addonCache('block_peek', 'picker/lock'), 'c');
    assert($lock !== false && flock($lock, LOCK_EX | LOCK_NB));
    $addon->setConfig('picker', '|1|');
    rex_config::save();
    $lines = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rex_path::bin('console')) . ' block_peek:generate 2>&1', $lines, $exit);
    assert($exit === 0 && str_contains(implode("\n", $lines), 'läuft bereits'), implode("\n", $lines));
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    foreach ($settings as $key => [$exists, $value]) {
        $exists ? $addon->setConfig($key, $value) : $addon->removeConfig($key);
    }
    rex_config::save();
    if (is_file($dir . 'previews.json')) {
        chmod($dir . 'previews.json', 0644);
    }
    rex_dir::delete($dir);
}
