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
        return $configured !== '' ? $configured : (str_contains(basename(PHP_BINARY), 'fpm') ? PHP_BINDIR . '/php' : PHP_BINARY);
    }

    public static function start(?int $moduleId, bool $force): bool
    {
        $php = self::phpBinary();
        $console = rex_path::base('bin/console');
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
        return is_array($status) ? $status : ['running' => false];
    }
}
