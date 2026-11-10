<?php

declare(strict_types=1);

use FriendsOfRedaxo\BlockPeek\Picker\CliProvider;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewGenerator as G;
use FriendsOfRedaxo\BlockPeek\Picker\PreviewStore as S;
use FriendsOfRedaxo\BlockPeek\Picker\Provider;
use FriendsOfRedaxo\BlockPeek\Picker\Providers;
use FriendsOfRedaxo\BlockPeek\Picker\TimeoutException;
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
$environment = ['HOME' => getenv('HOME'), 'PATH' => getenv('PATH')];
$childPid = null;
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

    // Sources: every prompt asks for them, parse() keeps strings only, run() stores fingerprints.
    assert(G::parse('{"description":"d","svg":"<svg/>","sources":["a.php",3]}')['sources'] === ['a.php']);
    assert(G::parse('{"description":"d","svg":"<svg/>","sources":"a.php"}')['sources'] === []);
    assert(G::parse('{"description":"d","svg":"<svg/>"}')['sources'] === []);
    $prompts = [];
    $capture = new class(static function (string $prompt) use (&$prompts, $cli): string {
        $prompts[] = $prompt;
        return $cli->generate('test', null);
    }) implements Provider {
        public function __construct(private Closure $generate) {}
        public function generate(string $prompt, ?string $imagePath): string { return ($this->generate)($prompt); }
        public function label(): string { return 'capture'; }
        public function inlineContext(): bool { return false; }
    };
    (new G($capture, $s))->run($id, true, $logger);
    $addon->setConfig('picker_prompt', 'Custom prompt for {module_name}.');
    (new G($capture, $s))->run($id, true, $logger);
    $settings['picker_prompt'][0] ? $addon->setConfig('picker_prompt', $settings['picker_prompt'][1]) : $addon->removeConfig('picker_prompt');
    assert(str_contains(G::SOURCES_INSTRUCTION, 'not generated build output'), 'hashed build files would flag previews on every build');
    assert(count($prompts) === 2 && str_contains($prompts[0], G::SOURCES_INSTRUCTION) && str_contains($prompts[1], G::SOURCES_INSTRUCTION), 'default and custom prompts must ask for sources');

    $fixture = (string) realpath($fake);
    $relative = substr($fixture, strlen((string) realpath(rex_path::base())) + 1);
    $withSources = new CliProvider($fake, '{bin} sources', 'stdout', 'm', 'e');
    assert((new G($withSources, $s))->run($id, true, $logger)['generated'] === 1);
    assert($s->get($slug)['sources'] === [$relative => sha1_file($fixture)], 'only existing sources inside the project are stored');
    $s->put($slug, ['sources' => [$relative => 'stale']] + $s->get($slug), null);
    assert((new G($withSources, $s))->run($id, false, $logger)['generated'] === 1, 'a changed source must not be skipped');
    $kept = $s->get($slug)['sources'];
    (new G(new CliProvider($fake, '{bin} garbage', 'stdout', 'm', 'e'), $s))->run($id, true, $logger);
    assert($s->get($slug)['sources'] === $kept, 'failed runs keep the previous sources');

    foreach (['ok', 'garbage'] as $mode) {
        $s->setDescription($slug, 'Before run', false);
        $duringCall = new class(static function () use ($s, $slug, $cli, $mode): string {
            $s->setDescription($slug, 'Mid-run', true);
            return $mode === 'ok' ? $cli->generate('test', null) : 'Sorry, no.';
        }) implements Provider {
            public function __construct(private Closure $generate) {}
            public function generate(string $prompt, ?string $imagePath): string { return ($this->generate)(); }
            public function label(): string { return 'mid-run-save'; }
            public function inlineContext(): bool { return false; }
        };
        (new G($duringCall, $s))->run($id, true, $logger);
        assert($s->get($slug)['description'] === 'Mid-run' && $s->get($slug)['locked'] === true, "$mode must preserve descriptions saved during the provider call");
    }

    $s->setDescription($slug, 'Hand', true);
    assert($g->run($id, true, $logger)['generated'] === 1);
    assert($s->get($slug)['description'] === 'Hand' && $s->get($slug)['locked'] === true);
    $svg = $s->svg($slug);

    foreach (['garbage', 'badsvg'] as $mode) {
        $previous = ['generated' => 123, 'model' => 'previous model', 'hash' => 'previous hash'] + $s->get($slug);
        $s->put($slug, $previous, null);
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
        foreach (['generated', 'model', 'hash'] as $key) {
            assert($s->get($slug)[$key] === $previous[$key], "$mode must preserve previous $key");
        }
    }
    assert($g->run($id, false, $logger)['generated'] === 1, 'failed entries must be retried without force');

    $timeout = new class implements Provider {
        public int $calls = 0;
        public function generate(string $prompt, ?string $imagePath): string
        {
            ++$this->calls;
            throw new TimeoutException('CLI timeout');
        }
        public function label(): string { return 'timeout'; }
        public function inlineContext(): bool { return false; }
    };
    $logBefore = count($log);
    assert((new G($timeout, $s))->run($id, true, $logger)['failed'] === 1);
    assert($timeout->calls === 1 && $s->get($slug)['status'] === 'failed');
    assert(!str_contains(implode("\n", array_slice($log, $logBefore)), '(retry)'));

    $statusPath = rex_path::addonCache('block_peek', 'picker/status.json');
    $savedStatus = rex_file::get($statusPath);
    chmod($statusPath, 0444);
    try {
        assert(@$g->run($id, true, $logger)['generated'] === 1, 'advisory status failures must not abort generation');
    } finally {
        chmod($statusPath, 0644);
        rex_file::put($statusPath, $savedStatus);
    }

    $start = microtime(true);
    try {
        (new CliProvider($fake, '{bin} slow', 'stdout', 'm', 'e', 1))->generate('test', null);
        assert(false, 'timeout must throw');
    } catch (TimeoutException $e) {
        assert(str_contains(strtolower($e->getMessage()), 'timeout'));
        assert(microtime(true) - $start < 3, 'timeout must not wait for the child to finish');
    }
    // Disable the actual extension function in a separate PHP runtime, without changing this suite's process.
    $timeoutCode = '(new \\FriendsOfRedaxo\\BlockPeek\\Picker\\CliProvider(PHP_BINARY, ' . var_export('{bin} -r ' . escapeshellarg('sleep(5);'), true) . ', "stdout", "m", "e", 1))->generate("test", null);';
    $lines = [];
    $start = microtime(true);
    exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=posix_kill ' . escapeshellarg(rex_path::bin('console')) . ' massif:exec-php ' . escapeshellarg($timeoutCode) . ' 2>&1', $lines, $exit);
    assert($exit === 1 && str_contains(implode("\n", $lines), 'CLI timeout'), implode("\n", $lines));
    assert(microtime(true) - $start < 5, 'without posix_kill the timeout must kill the direct process before proc_close');

    $pidFile = $dir . 'child.pid';
    try {
        (new CliProvider($fake, '{bin} slowchild ' . escapeshellarg($pidFile), 'stdout', 'm', 'e', 1))->generate('test', null);
        assert(false, 'timeout with descendants must throw');
    } catch (TimeoutException $e) {
        assert(str_contains(strtolower($e->getMessage()), 'timeout'));
        $childPid = (int) rex_file::get($pidFile);
        assert($childPid > 0, 'slowchild must record its child PID');
        assert(posix_kill($childPid, 0) === false, 'timeout must stop descendants');
        $childPid = null;
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
    $hostile = "a' ; echo injected; # \$(printf injected)";
    assert(json_decode((new CliProvider(PHP_BINARY, $argsCommand, 'stdout', $hostile, 'e'))->generate('test', $hostile), true) === [$hostile, 'e', '-i', $hostile]);
    foreach (['bin', 'model', 'effort', 'image_args', 'out', 'tmp'] as $placeholder) {
        foreach (["'", '"'] as $quote) {
            $template = '{bin} --value ' . $quote . '{' . $placeholder . '}' . $quote;
            assert(CliProvider::templateErrors($template) !== [], 'quoted placeholders must be rejected');
            try {
                new CliProvider($fake, $template, 'stdout', 'm', 'e');
                assert(false, 'constructor must reject quoted placeholders');
            } catch (InvalidArgumentException) {
            }
        }
    }
    assert(CliProvider::templateErrors('{bin} {model} {effort} {image_args} {out} {tmp}') === []);
    assert(CliProvider::templateErrors('{bin} --tools "Read Glob Grep" {model}') === []);
    assert(CliProvider::templateErrors('{bin} \"{model}\"') === [], 'escaped quotes must not toggle quote state');
    assert(CliProvider::templateErrors("{bin} 'literal \\' {model}") === [], 'backslash does not escape a single quote inside single quotes');

    assert(CliProvider::detect('missing-block-peek-cli', $fake) === $fake);
    assert(symlink($fake, $dir . 'claude'));
    $detected = CliProvider::detect('claude', $dir . 'claude');
    assert($detected === $dir . 'claude', 'detection must preserve the executable symlink');
    assert((new CliProvider($detected, '{bin} argv0', 'stdout', 'opus', 'medium'))->generate('test', null) === 'claude');
    assert((new CliProvider($detected, '{bin} ok', 'stdout', 'opus', 'medium'))->label() === 'claude opus medium');
    assert(CliProvider::detect('missing-block-peek-cli') === null);
    $cwd = getcwd();
    try {
        chdir(rex_path::frontend());
        assert(CliProvider::detect('missing-block-peek-cli', substr($fake, strlen(rex_path::base()))) === $fake, 'relative CLI paths must resolve from the project root under FPM too');
    } finally {
        chdir($cwd);
    }

    $addon->setConfig('picker_provider', 'none');
    assert(Providers::fromConfig() === null);
    foreach (['codex', 'claude'] as $provider) {
        $addon->setConfig('picker_provider', $provider);
        $addon->setConfig('picker_cli_path', $fake);
        $addon->setConfig('picker_command', '{bin} ok');
        $addon->setConfig('picker_model', 'm');
        $addon->setConfig('picker_effort', 'e');
        assert(Providers::fromConfig()->generate('test', null) === $cli->generate('test', null));
        assert(Providers::fromConfig()->label() === "$provider m e", 'explicit provider labels must use the preset name');
    }

    // Override HOME before the standard search directories so installed CLIs cannot affect these cases.
    $fakeBin = $dir . 'home/.local/bin/';
    rex_dir::create($fakeBin);
    assert(symlink($fake, $fakeBin . 'codex') && symlink($fake, $fakeBin . 'claude'));
    putenv('HOME=' . $dir . 'home');
    putenv('PATH=' . $fakeBin . PATH_SEPARATOR . ($environment['PATH'] ?: ''));
    $addon->setConfig('picker_command', '');
    $addon->setConfig('picker_model', '');
    $addon->setConfig('picker_effort', 'medium');
    foreach (['codex' => ['file', 'gpt-6.1-sol'], 'claude' => ['json', 'opus']] as $provider => [$output, $model]) {
        $addon->setConfig('picker_provider', $provider);
        $addon->setConfig('picker_cli_path', $fakeBin . $provider);
        $preset = Providers::fromConfig();
        assert($preset instanceof CliProvider);
        assert($preset->label() === "$provider $model medium");
        assert(rtrim($preset->generate('test', null)) === rtrim($cli->generate('test', null)), "$provider preset must use $output output decoding");
    }
    $addon->setConfig('picker_provider', 'auto');
    $addon->setConfig('picker_command', '{bin} path');
    foreach ([$fakeBin . 'claude', $fake, ''] as $configured) {
        $addon->setConfig('picker_cli_path', $configured);
        assert(Providers::fromConfig()->label() === 'codex gpt-6.1-sol medium');
        assert(Providers::fromConfig()->generate('test', null) === $fakeBin . 'codex', 'auto must detect codex before using a configured claude or unknown binary');
    }
    $addon->setConfig('picker_cli_path', $dir . 'claude');
    assert(Providers::fromConfig()->generate('test', null) === $fakeBin . 'codex');
    // An auto preset with a matching basename must still use the configured path.
    rex_dir::create($dir . 'configured/');
    assert(symlink($fake, $dir . 'configured/codex'));
    $addon->setConfig('picker_cli_path', $dir . 'configured/codex');
    $addon->setConfig('picker_command', '{bin} path');
    assert(Providers::fromConfig()->generate('test', null) === $dir . 'configured/codex');
    foreach ($environment as $key => $value) {
        putenv($value === false ? $key : "$key=$value");
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
    assert(str_ends_with($inspector->prompt, "|de\n\n" . G::SOURCES_INSTRUCTION) && $inspector->image === realpath($fake));

    $previous = $s->get($slug);
    unlink($moduleDir . 'input.php');
    mkdir($moduleDir . 'input.php');
    try {
        assert(@$g->run($id, true, $logger)['failed'] === 1);
        assert($s->get($slug)['status'] === 'failed', 'module cache write failures must mark the preview failed');
        assert($s->svg($slug) === $svg);
        foreach (['generated', 'model', 'hash'] as $key) {
            assert($s->get($slug)[$key] === $previous[$key], "exception must preserve previous $key");
        }
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
    $s->put($slug, $entry, $svg);
    $writeFailure = new class(new CliProvider($fake, '{bin} different', 'stdout', 'm', 'e'), $dir) implements Provider {
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
    assert($s->svg($slug) === $svg, 'index write failure must restore the previous SVG');
    assert($counts['failed'] === 1 && $counts['generated'] === count($modules) - 1, 'a store write failure must not stop the next module');
    assert($s->get('999999999') === null && $s->svg('999999999') === null, 'full run must remove numeric orphan slugs');
    $status = json_decode(rex_file::get(rex_path::addonCache('block_peek', 'picker/status.json')), true, 512, JSON_THROW_ON_ERROR);
    assert($status['running'] === false && $status['done'] === count($modules) && $status['total'] === count($modules));
    assert($status['pid'] === getmypid(), 'generation status must record the process PID');
    assert(is_int($status['started']) && $status['current'] === $modules[array_key_last($modules)]['name']);
    assert(count($status['log']) <= 50 && $status['log'] === array_slice($log, -count($status['log'])));

    $command = new CommandTester(new GenerateCommand());
    $addon->setConfig('picker', '');
    assert($command->execute([]) === 0 && str_contains($command->getDisplay(), 'Module picker is disabled.'));
    $addon->setConfig('picker', '|1|');
    $addon->setConfig('picker_provider', 'none');
    assert($command->execute([]) === 1 && str_contains($command->getDisplay(), 'No provider available for generation.'));
    assert($command->execute(['--module' => 'invalid']) === 1);

    $missingId = max(array_column($modules, 'id')) + 1;
    rex_config::save();
    $lines = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rex_path::bin('console')) . ' block_peek:generate --module=' . $missingId . ' 2>&1', $lines, $exit);
    $display = implode("\n", $lines);
    assert($exit === 1 && str_contains($display, 'Module ' . $missingId . ' does not exist'), $display);
    assert(!str_contains($display, 'Generated:') && !str_contains($display, 'Provider'), 'missing module must be rejected before resolving a provider');

    // Hold the lock before starting the real command, so this can never invoke a real model.
    $lock = fopen((new ReflectionClass(GenerateCommand::class))->getFileName(), 'r');
    assert($lock !== false && flock($lock, LOCK_EX | LOCK_NB));
    $addon->setConfig('picker', '|1|');
    rex_config::save();
    $lines = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(rex_path::bin('console')) . ' block_peek:generate 2>&1', $lines, $exit);
    assert($exit === 0 && str_contains(implode("\n", $lines), 'Generation is already running.'), implode("\n", $lines));
} finally {
    foreach ($environment as $key => $value) {
        putenv($value === false ? $key : "$key=$value");
    }
    if ($childPid !== null && $childPid > 0) {
        @posix_kill($childPid, 9);
    }
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
