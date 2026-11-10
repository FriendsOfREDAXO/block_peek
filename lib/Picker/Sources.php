<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use rex_path;

/**
 * Fingerprints of the project files an AI reported as the sources of a preview.
 */
final class Sources
{
    public const MAX = 50;

    /**
     * Keeps existing regular files inside the project root, outside excluded dirs and dot paths.
     *
     * @param list<string>|null $exclude absolute directories; default: REDAXO cache, data, media and core
     * @return array<string, string> project-relative path => sha1
     */
    public static function collect(mixed $reported, ?string $base = null, ?array $exclude = null): array
    {
        $root = realpath($base ?? rex_path::base());
        if (!is_array($reported) || $root === false) {
            return [];
        }
        $root = rtrim($root, '/') . '/';
        $excluded = [];
        foreach ($exclude ?? [rex_path::cache(), rex_path::data(), rex_path::media(), rex_path::core()] as $dir) {
            $real = realpath($dir);
            if ($real !== false) {
                $excluded[] = rtrim($real, '/') . '/';
            }
        }
        $sources = [];
        foreach ($reported as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $real = realpath(str_starts_with($path, '/') ? $path : $root . $path);
            if ($real === false || !is_file($real) || !str_starts_with($real, $root)) {
                continue;
            }
            $relative = substr($real, strlen($root));
            if (preg_match('~(?:^|/)\.~', $relative)) {
                continue;
            }
            foreach ($excluded as $dir) {
                if (str_starts_with($real, $dir)) {
                    continue 2;
                }
            }
            $sources[$relative] ??= (string) sha1_file($real);
        }
        ksort($sources, SORT_STRING);
        return array_slice($sources, 0, self::MAX, true); // ponytail: fixed cap keeps popup fingerprinting cheap
    }

    /** @param array<array-key, mixed> $sources project-relative path => sha1 */
    public static function changed(array $sources, ?string $base = null): bool
    {
        $root = rtrim($base ?? rex_path::base(), '/') . '/';
        foreach ($sources as $path => $hash) {
            $file = $root . $path;
            if (!is_file($file) || sha1_file($file) !== $hash) {
                return true;
            }
        }
        return false;
    }
}
