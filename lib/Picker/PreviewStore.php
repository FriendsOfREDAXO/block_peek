<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use rex_file;
use rex_path;
use RuntimeException;
use Throwable;

final class PreviewStore
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? rex_path::addonData('block_peek', 'previews/'), '/') . '/';
    }

    public static function slug(int $id, ?string $key): string
    {
        return $key !== null && $key !== '' ? 'key-' . $key : (string) $id;
    }

    public static function hash(string $input, string $output): string
    {
        return sha1($input . "\0" . $output);
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $data = json_decode((string) rex_file::get($this->dir . 'previews.json', '{}'), true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    /** @return array<string, mixed>|null */
    public function get(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    public function svg(string $slug): ?string
    {
        $svg = rex_file::get($this->svgPath($slug));
        return is_string($svg) ? $svg : null;
    }

    /** @param array<string, mixed> $entry */
    public function put(string $slug, array $entry, ?string $svg): void
    {
        $all = $this->all();
        $previous = $svg !== null ? $this->svg($slug) : null;
        if ($svg !== null && !rex_file::put($this->svgPath($slug), $svg)) {
            throw new RuntimeException('Unable to write preview SVG: ' . $slug);
        }
        $all[$slug] = $entry;
        try {
            $this->save($all);
        } catch (Throwable $e) {
            if ($svg !== null) {
                $restored = $previous === null ? rex_file::delete($this->svgPath($slug)) : rex_file::put($this->svgPath($slug), $previous);
                if (!$restored) {
                    throw new RuntimeException('Unable to restore previous preview SVG: ' . $slug, 0, $e);
                }
            }
            throw $e;
        }
    }

    public function setDescription(string $slug, string $text, bool $locked): void
    {
        $all = $this->all();
        if (!isset($all[$slug])) {
            return;
        }
        $all[$slug]['description'] = $text;
        $all[$slug]['locked'] = $locked;
        $this->save($all);
    }

    /**
     * @param list<string> $keep
     * @return list<string>
     */
    public function removeOrphans(array $keep): array
    {
        $all = $this->all();
        $removed = array_map('strval', array_values(array_diff(array_keys($all), $keep))); // numeric slugs are int keys in PHP
        foreach ($removed as $slug) {
            if (!rex_file::delete($this->svgPath($slug))) {
                throw new RuntimeException('Unable to delete preview SVG: ' . $slug);
            }
            unset($all[$slug]);
        }
        if ($removed) {
            $this->save($all);
        }
        return $removed;
    }

    /**
     * @param list<array{id:int, key:?string}> $modules
     * @return array<int, array{description:string, svg:?string}>
     */
    public function forJs(array $modules): array
    {
        $all = $this->all();
        foreach ($all as $slug => $entry) {
            if (!is_array($entry) || !is_string($entry['description'] ?? null)) {
                throw new RuntimeException('Invalid preview entry: ' . $slug);
            }
        }
        $out = [];
        foreach ($modules as $m) {
            $slug = self::slug($m['id'], $m['key']);
            if (isset($all[$slug])) {
                $out[$m['id']] = ['description' => (string) $all[$slug]['description'], 'svg' => $this->svg($slug)];
            }
        }
        return $out;
    }

    /** @param array<string, array<string, mixed>> $all */
    private function save(array $all): void
    {
        ksort($all, SORT_STRING);
        $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (!rex_file::put($this->dir . 'previews.json', $json)) {
            throw new RuntimeException('Unable to write preview index');
        }
    }

    private function svgPath(string $slug): string
    {
        return $this->dir . rawurlencode($slug) . '.svg';
    }
}
