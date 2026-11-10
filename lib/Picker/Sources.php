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
        $root = self::root($base);
        if (!is_array($reported) || $root === null) {
            return [];
        }
        $excluded = self::excluded($root, $exclude);
        $sources = [];
        foreach ($reported as $path) {
            $real = is_string($path) ? self::resolve($path, $root, $excluded) : null;
            if ($real !== null) {
                $sources[substr($real, strlen($root))] ??= (string) sha1_file($real);
            }
        }
        ksort($sources, SORT_STRING);
        return array_slice($sources, 0, self::MAX, true); // ponytail: fixed cap keeps popup fingerprinting cheap
    }

    /**
     * True if any source is gone, changed, or no longer passes the collect() rules (e.g. now a symlink out of the project).
     *
     * @param array<array-key, mixed> $sources project-relative path => sha1
     * @param list<string>|null $exclude see collect()
     */
    public static function changed(array $sources, ?string $base = null, ?array $exclude = null): bool
    {
        if ($sources === []) {
            return false;
        }
        $root = self::root($base);
        if ($root === null) {
            return true;
        }
        $excluded = self::excluded($root, $exclude);
        foreach ($sources as $path => $hash) {
            $real = self::resolve((string) $path, $root, $excluded);
            if ($real === null || sha1_file($real) !== $hash) {
                return true;
            }
        }
        return false;
    }

    private static function root(?string $base): ?string
    {
        $root = realpath($base ?? rex_path::base());
        return $root === false ? null : rtrim($root, '/') . '/';
    }

    /**
     * Excluded dirs that lie inside the root (so a root inside the cache, as in tests, isn't excluded wholesale).
     *
     * @param list<string>|null $exclude
     * @return list<string>
     */
    private static function excluded(string $root, ?array $exclude): array
    {
        $dirs = [];
        foreach ($exclude ?? [rex_path::cache(), rex_path::data(), rex_path::media(), rex_path::core()] as $dir) {
            $real = realpath($dir);
            if ($real !== false && str_starts_with($real . '/', $root) && $real . '/' !== $root) {
                $dirs[] = rtrim($real, '/') . '/';
            }
        }
        return $dirs;
    }

    /** Same visibility rule as ContextInliner: the reported and the resolved path must both be free of dot segments. */
    private static function resolve(string $path, string $root, array $excluded): ?string
    {
        if ($path === '' || str_contains($path, "\0")) {
            return null;
        }
        $absolute = str_starts_with($path, '/');
        $reported = $absolute ? (str_starts_with($path, $root) ? substr($path, strlen($root)) : '') : $path;
        if (preg_match('~(?:^|/)\.(?!\.?(?:/|$))~', $reported)) {
            return null;
        }
        $real = realpath($absolute ? $path : $root . $path);
        if ($real === false || !is_file($real) || !str_starts_with($real, $root) || preg_match('~(?:^|/)\.~', substr($real, strlen($root)))) {
            return null;
        }
        foreach ($excluded as $dir) {
            if (str_starts_with($real, $dir)) {
                return null;
            }
        }
        return $real;
    }
}
