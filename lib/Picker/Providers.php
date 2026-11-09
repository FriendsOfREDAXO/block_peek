<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use rex_addon;

final class Providers
{
    public static function fromConfig(): ?Provider
    {
        $addon = rex_addon::get('block_peek');
        $provider = (string) $addon->getConfig('picker_provider', 'auto');
        $presets = [
            'codex' => ['{bin} exec -m {model} -c model_reasoning_effort={effort} -s read-only --skip-git-repo-check {image_args} -o {out} -', 'file', 'gpt-6.1-sol'],
            'claude' => ['{bin} -p --model {model} --allowedTools "Read Glob Grep" --add-dir {tmp} --output-format json', 'json', 'opus'],
        ];
        $names = $provider === 'auto' ? array_keys($presets) : [$provider];
        foreach ($names as $name) {
            if (!isset($presets[$name])) {
                continue;
            }
            $bin = CliProvider::detect($name, (string) $addon->getConfig('picker_cli_path', ''));
            if ($bin === null) {
                continue;
            }
            [$template, $output, $model] = $presets[$name];
            $custom = trim((string) $addon->getConfig('picker_command', ''));
            return new CliProvider($bin, $custom !== '' ? $custom : $template, $custom !== '' ? 'stdout' : $output,
                trim((string) $addon->getConfig('picker_model', '')) ?: $model,
                (string) $addon->getConfig('picker_effort', 'medium'));
        }
        return null;
    }
}
