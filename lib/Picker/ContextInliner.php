<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use rex_path;
use RuntimeException;

final class ContextInliner
{
    /** @param list<string> $globs
     *  @return list<string>
     */
    public static function files(string $moduleDir, array $globs, ?string $base = null): array
    {
        $base = realpath($base ?? rex_path::base());
        if ($base === false) {
            return [];
        }
        $base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $contained = static function (string $path) use ($base): bool {
            $real = realpath($path);
            return $real !== false && ($real === rtrim($base, DIRECTORY_SEPARATOR) || str_starts_with($real, $base));
        };
        $visible = static function (string $path) use ($base): bool {
            foreach ([$path, realpath($path)] as $candidate) {
                if ($candidate !== false && preg_match('~(?:^|/)\.(?!\.?(?:/|$))~', substr($candidate, strlen($base)))) {
                    return false;
                }
            }
            return true;
        };
        $moduleDir = rtrim($moduleDir, '/') . '/';
        $matches = [];
        foreach ($globs as $pattern) {
            if (str_ends_with($pattern, '/**')) {
                $dir = $base . substr($pattern, 0, -3);
                if (!is_dir($dir) || !$contained($dir) || !$visible($dir)) {
                    continue;
                }
                $iterator = new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    static fn (\SplFileInfo $file): bool => $contained($file->getPathname()) && $visible($file->getPathname()),
                );
                foreach (new RecursiveIteratorIterator($iterator) as $file) {
                    if ($file->isFile()) {
                        $matches[] = $file->getPathname();
                    }
                }
            } else {
                array_push($matches, ...(glob($base . $pattern) ?: []));
            }
        }
        $matches = array_filter($matches, static function (string $file) use ($visible): bool {
            if (!$visible($file)) {
                return false;
            }
            foreach ([$file, realpath($file)] as $path) {
                if ($path === false || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['css', 'scss', 'sass', 'less', 'js', 'mjs', 'cjs', 'ts', 'php', 'html', 'htm', 'twig', 'md'], true)) {
                    return false;
                }
            }
            return true;
        });
        sort($matches, SORT_STRING);
        return array_values(array_unique(array_filter(
            [$moduleDir . 'input.php', $moduleDir . 'output.php', ...$matches],
            static fn (string $file): bool => is_file($file) && $contained($file),
        )));
    }

    /** @param list<string> $files */
    public static function inline(array $files, int $maxBytes = 200_000): string
    {
        $out = '';
        $remaining = $maxBytes;
        $truncated = "=== (further files omitted: size limit) ===";
        foreach ($files as $file) {
            if ($remaining <= 0) {
                return $out . $truncated;
            }
            $content = file_get_contents($file, false, null, 0, $remaining + 4);
            if ($content === false) {
                throw new RuntimeException('Unable to read context file: ' . $file);
            }
            $cut = mb_strcut($content, 0, $remaining, 'UTF-8');
            if (str_contains($content, "\0") || !mb_check_encoding($cut, 'UTF-8')) {
                continue;
            }
            $path = $file;
            foreach ([rex_path::base(), realpath(rex_path::base())] as $base) {
                if ($base !== false && str_starts_with($file, rtrim($base, '/') . '/')) {
                    $path = substr($file, strlen(rtrim($base, '/')) + 1);
                    break;
                }
            }
            $out .= '=== ' . $path . " ===\n" . $cut . "\n";
            $remaining -= strlen($content);
            if ($remaining < 0) {
                return $out . $truncated;
            }
        }
        return $out;
    }
}
