<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use rex;
use rex_addon;
use rex_file;
use rex_path;
use rex_sql;
use RuntimeException;
use Throwable;

final class PreviewGenerator
{
    public function __construct(private Provider $provider, private PreviewStore $store) {}

    /** @return list<array{id:int, key:?string, name:string, input:string, output:string}> */
    public static function modules(): array
    {
        $rows = rex_sql::factory()->getArray('SELECT id, `key`, name, input, output FROM ' . rex::getTable('module') . ' ORDER BY name');
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'key' => $row['key'], 'name' => (string) $row['name'],
            'input' => (string) $row['input'], 'output' => (string) $row['output'],
        ], $rows);
    }

    /** @return array{description:string, svg:string}|null */
    public static function parse(string $raw): ?array
    {
        $first = strpos($raw, '{');
        $last = strrpos($raw, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $data = json_decode(substr($raw, $first, $last - $first + 1), true);
            if (is_array($data) && is_string($data['svg'] ?? null) && is_string($data['description'] ?? null)) {
                return ['description' => $data['description'], 'svg' => $data['svg']];
            }
        }
        $svg = SvgValidator::extract($raw);
        return $svg === null ? null : ['description' => '', 'svg' => $svg];
    }

    /** @return array{generated:int, skipped:int, failed:int} */
    public function run(?int $moduleId, bool $force, callable $log): array
    {
        $modules = self::modules();
        $selected = array_values(array_filter($modules, static fn (array $m): bool => $moduleId === null || $m['id'] === $moduleId));
        $counts = ['generated' => 0, 'skipped' => 0, 'failed' => 0];
        $status = ['running' => true, 'started' => time(), 'current' => '', 'done' => 0, 'total' => count($selected), 'log' => []];
        $statusPath = rex_path::addonCache('block_peek', 'picker/status.json');
        $writeStatus = static function () use (&$status, $statusPath): void {
            if (!rex_file::put($statusPath, json_encode($status, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE))) {
                throw new RuntimeException('Unable to write generation status');
            }
        };
        $report = static function (string $line) use (&$status, $log, $writeStatus): void {
            $status['log'][] = $line;
            $status['log'] = array_slice($status['log'], -50);
            $writeStatus();
            $log($line);
        };
        $addon = rex_addon::get('block_peek');
        $writeStatus();
        try {
            foreach ($selected as $module) {
                $status['current'] = $module['name'];
                $writeStatus();
                $slug = PreviewStore::slug($module['id'], $module['key']);
                $old = null;
                $hash = PreviewStore::hash($module['input'], $module['output']);
                try {
                    $old = $this->store->get($slug);
                    if (!$force && ($old['status'] ?? '') === 'ok' && ($old['hash'] ?? '') === $hash && $this->store->svg($slug) !== null) {
                        ++$counts['skipped'];
                        $report($module['name'] . ': skipped');
                        continue;
                    }
                    $dir = rex_path::addonCache('block_peek', 'picker/module-' . $module['id'] . '/');
                    if (!rex_file::put($dir . 'input.php', $module['input']) || !rex_file::put($dir . 'output.php', $module['output'])) {
                        throw new RuntimeException('Unable to write module code');
                    }
                    $reference = trim((string) $addon->getConfig('picker_reference_image', ''));
                    $image = $reference !== '' ? realpath(rex_path::base($reference)) : false;
                    $image = $image !== false && is_file($image) ? $image : null;
                    $prompt = PromptBuilder::build(PromptBuilder::template(), [
                        'module_dir' => rtrim($dir, '/'), 'module_name' => $module['name'],
                        'context_paths' => PromptBuilder::contextPaths((string) $addon->getConfig('picker_context_paths', '')),
                        'style_reference' => $this->store->svg((string) $addon->getConfig('picker_style_module', '')) ?? '',
                        'reference_image' => $image ?? '',
                        'language' => (string) ($addon->getConfig('picker_language', '') ?: rex::getProperty('lang')),
                    ]);
                    $result = null;
                    $error = '';
                    for ($attempt = 0; $attempt < 2; ++$attempt) {
                        try {
                            $result = self::parse($this->provider->generate($prompt, $image));
                            $errors = $result === null ? ['No SVG in provider output'] : SvgValidator::errors($result['svg']);
                            if ($errors === []) {
                                break;
                            }
                            $error = implode(', ', $errors);
                        } catch (Throwable $e) {
                            $error = $e->getMessage();
                        }
                        $result = null;
                        $report($module['name'] . ': ' . $error . ($attempt === 0 ? ' (retry)' : ''));
                    }
                    $entry = [
                        'id' => $module['id'], 'key' => $module['key'], 'name' => $module['name'],
                        'description' => ($old['locked'] ?? false) ? $old['description'] : ($result['description'] ?? $old['description'] ?? ''),
                        'locked' => (bool) ($old['locked'] ?? false),
                        'hash' => $result === null ? ($old['hash'] ?? '') : $hash,
                        'model' => $result === null ? ($old['model'] ?? '') : $this->provider->label(),
                        'generated' => $result === null ? ($old['generated'] ?? 0) : time(),
                        'status' => $result === null ? 'failed' : 'ok',
                    ];
                    $this->store->put($slug, $entry, $result['svg'] ?? null);
                    ++$counts[$result === null ? 'failed' : 'generated'];
                    $report($module['name'] . ': ' . $entry['status']);
                } catch (Throwable $e) {
                    ++$counts['failed'];
                    $message = $module['name'] . ': failed: ' . $e->getMessage();
                    try {
                        $this->store->put($slug, [
                            'id' => $module['id'], 'key' => $module['key'], 'name' => $module['name'],
                            'description' => $old['description'] ?? '', 'locked' => (bool) ($old['locked'] ?? false),
                            'hash' => $old['hash'] ?? '', 'model' => $old['model'] ?? '',
                            'generated' => $old['generated'] ?? 0, 'status' => 'failed',
                        ], null);
                    } catch (Throwable $writeError) {
                        $message .= '; unable to record failure: ' . $writeError->getMessage();
                    }
                    $report($message);
                } finally {
                    ++$status['done'];
                    $writeStatus();
                }
            }
            if ($moduleId === null) {
                try {
                    $this->store->removeOrphans(array_map(static fn (array $m): string => PreviewStore::slug($m['id'], $m['key']), $modules));
                } catch (Throwable $e) {
                    $report('Orphan cleanup failed: ' . $e->getMessage());
                }
            }
            return $counts;
        } finally {
            $status['running'] = false;
            $writeStatus();
        }
    }
}
