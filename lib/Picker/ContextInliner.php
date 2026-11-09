<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use FilesystemIterator;
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
        $base = rtrim($base ?? rex_path::base(), '/') . '/';
        $moduleDir = rtrim($moduleDir, '/') . '/';
        $matches = [];
        foreach ($globs as $pattern) {
            if (str_ends_with($pattern, '/**')) {
                $dir = $base . substr($pattern, 0, -3);
                if (!is_dir($dir)) {
                    continue;
                }
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile()) {
                        $matches[] = $file->getPathname();
                    }
                }
            } else {
                array_push($matches, ...(glob($base . $pattern) ?: []));
            }
        }
        sort($matches, SORT_STRING);
        return array_values(array_unique(array_filter([$moduleDir . 'input.php', $moduleDir . 'output.php', ...$matches], 'is_file')));
    }

    /** @param list<string> $files */
    public static function inline(array $files, int $maxBytes = 200_000): string
    {
        $out = '';
        $remaining = $maxBytes;
        $truncated = "=== (weitere Dateien ausgelassen: Grössenlimit) ===";
        foreach ($files as $file) {
            if ($remaining <= 0) {
                return $out . $truncated;
            }
            $content = file_get_contents($file);
            if ($content === false) {
                throw new RuntimeException('Unable to read context file: ' . $file);
            }
            $path = str_starts_with($file, rex_path::base()) ? substr($file, strlen(rex_path::base())) : $file;
            $out .= '=== ' . $path . " ===\n" . mb_strcut($content, 0, $remaining, 'UTF-8') . "\n";
            $remaining -= strlen($content);
            if ($remaining < 0) {
                return $out . $truncated;
            }
        }
        return $out;
    }
}
