<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use InvalidArgumentException;
use rex_dir;
use rex_path;
use RuntimeException;

final class CliProvider implements Provider
{
    public function __construct(
        private string $bin,
        private string $template,
        private string $output,
        private string $model,
        private string $effort,
        private int $timeout = 300,
        private string $preset = '',
    ) {
        $errors = self::templateErrors($template);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
    }

    /** @return list<string> */
    public static function templateErrors(string $template): array
    {
        $errors = [];
        $quote = null;
        $escaped = false;
        for ($i = 0, $length = strlen($template); $i < $length; ++$i) {
            $char = $template[$i];
            if ($quote !== null && $char === '{' && preg_match('/\\G\\{(?:bin|model|effort|image_args|out|tmp)\\}/', $template, $match, 0, $i)) {
                $errors[] = 'Placeholder ' . $match[0] . ' must not be inside shell quotes.';
            }
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\' && $quote !== "'") {
                $escaped = true;
                continue;
            }
            if ($char === "'" || $char === '"') {
                if ($quote === null) {
                    $quote = $char;
                } elseif ($quote === $char) {
                    $quote = null;
                }
            }
        }
        return $errors;
    }

    public static function detect(string $name, string $configuredPath = ''): ?string
    {
        $home = self::environment()['HOME'] ?? '';
        $paths = [$configuredPath];
        foreach ([$home . '/.local/bin', $home . '/.local/shims', '/opt/homebrew/bin', '/usr/local/bin', ...explode(PATH_SEPARATOR, getenv('PATH') ?: '')] as $dir) {
            $paths[] = $dir . '/' . $name;
        }
        foreach ($paths as $path) {
            $absolute = str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
            if ($path !== '' && is_file($absolute) && is_executable($absolute)) {
                return $absolute;
            }
        }
        return null;
    }

    public function label(): string
    {
        return ($this->preset ?: basename($this->bin)) . ' ' . $this->model . ' ' . $this->effort;
    }

    public function inlineContext(): bool
    {
        return false;
    }

    public function generate(string $prompt, ?string $imagePath): string
    {
        $dir = rex_path::addonCache('block_peek', 'picker/cli-' . bin2hex(random_bytes(8)) . '/');
        if (!rex_dir::create($dir)) {
            throw new RuntimeException('Unable to create CLI temporary directory');
        }
        $out = $dir . 'result';
        $values = ['bin' => $this->bin, 'model' => $this->model, 'effort' => $this->effort, 'out' => $out, 'tmp' => $dir];
        $replace = [];
        foreach ($values as $key => $value) {
            $replace['{' . $key . '}'] = escapeshellarg($value);
        }
        $replace['{image_args}'] = $imagePath ? '-i ' . escapeshellarg($imagePath) : '';
        $process = null;
        $pipes = [];
        try {
            // exec lets timeout termination reach the CLI instead of an intermediate shell.
            $process = proc_open(['/bin/sh', '-c', 'exec ' . strtr($this->template, $replace)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, rex_path::base(), self::environment());
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start CLI');
            }
            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }
            $start = microtime(true);
            $offset = 0;
            $stdout = $stderr = '';
            do {
                if (is_resource($pipes[0])) {
                    if ($offset < strlen($prompt)) {
                        $written = @fwrite($pipes[0], substr($prompt, $offset, 8192));
                        if ($written === false) {
                            fclose($pipes[0]);
                        } else {
                            $offset += $written;
                        }
                    }
                    if (is_resource($pipes[0]) && $offset === strlen($prompt)) {
                        fclose($pipes[0]);
                    }
                }
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (microtime(true) - $start >= $this->timeout) {
                    $pids = [(int) $status['pid']];
                    for ($i = 0; $i < count($pids); ++$i) {
                        $children = [];
                        exec('pgrep -P ' . $pids[$i], $children);
                        foreach ($children as $child) {
                            $pids[] = (int) $child;
                        }
                    }
                    $pids = array_reverse($pids);
                    foreach ($pids as $pid) {
                        @posix_kill($pid, 15);
                    }
                    $deadline = microtime(true) + 1;
                    do {
                        proc_get_status($process);
                        $pids = array_values(array_filter($pids, static fn (int $pid): bool => @posix_kill($pid, 0)));
                        if ($pids === []) {
                            break;
                        }
                        usleep(10000);
                    } while (microtime(true) < $deadline);
                    foreach ($pids as $pid) {
                        @posix_kill($pid, 9);
                    }
                    throw new RuntimeException('CLI timeout after ' . $this->timeout . ' seconds');
                }
                usleep(10000);
            } while (true);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException('CLI exited with ' . $status['exitcode'] . ': ' . substr($stderr, -500));
            }
            if ($this->output === 'file') {
                $result = is_file($out) ? file_get_contents($out) : false;
                if ($result === false) {
                    throw new RuntimeException('CLI did not write an output file');
                }
                return $result;
            }
            if ($this->output === 'json') {
                $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !is_string($data['result'] ?? null)) {
                    throw new RuntimeException('CLI JSON has no result');
                }
                return $data['result'];
            }
            return $stdout;
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
            rex_dir::delete($dir);
        }
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $env = getenv();
        if (empty($env['HOME']) && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if ($user !== false) {
                $env['HOME'] = $user['dir'];
            }
        }
        return $env;
    }
}
