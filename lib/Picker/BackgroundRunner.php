<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use rex_addon;
use rex_dir;
use rex_file;
use rex_path;

final class BackgroundRunner
{
    public static function phpBinary(): string
    {
        $configured = trim((string) rex_addon::get('block_peek')->getConfig('picker_php_path', ''));
        return $configured !== '' ? $configured : self::phpBinaryFor(PHP_BINARY, PHP_BINDIR);
    }

    private static function phpBinaryFor(string $binary, string $bindir): string
    {
        if (!str_contains(basename($binary), 'fpm')) {
            return $binary;
        }
        $sibling = dirname($binary) . '/' . preg_replace('/-?fpm$/', '', basename($binary));
        return is_executable($sibling) ? $sibling : $bindir . '/php';
    }

    public static function start(?int $moduleId, bool $force): bool
    {
        if (self::status()['running'] ?? false) {
            return false;
        }
        $php = self::phpBinary();
        $console = rex_path::bin('console');
        $dir = rex_path::addonCache('block_peek', 'picker/');
        if (!function_exists('exec') || !is_file($php) || !is_executable($php) || !is_file($console)
            || ($moduleId !== null && $moduleId < 1) || !rex_dir::create($dir)) {
            return false;
        }
        $args = [$php, $console, 'block_peek:generate'];
        if ($moduleId !== null) {
            $args[] = '--module=' . $moduleId;
        }
        if ($force) {
            $args[] = '--force';
        }
        exec('nohup ' . implode(' ', array_map('escapeshellarg', $args)) . ' > ' . escapeshellarg($dir . 'run.log') . ' 2>&1 &', $output, $code);
        return $code === 0;
    }

    public static function status(): array
    {
        $status = json_decode((string) rex_file::get(rex_path::addonCache('block_peek', 'picker/status.json'), ''), true);
        if (!is_array($status)) {
            return ['running' => false];
        }
        if ($status['running'] ?? false) {
            $pid = (int) ($status['pid'] ?? 0);
            $status['running'] = function_exists('posix_kill') && $pid > 0
                ? posix_kill($pid, 0) !== false
                : time() - (int) ($status['started'] ?? 0) <= 3600;
        }
        return $status;
    }
}
