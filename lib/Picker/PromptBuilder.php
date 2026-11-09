<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use rex_addon;

final class PromptBuilder
{
    public static function defaultPrompt(): string
    {
        return (string) file_get_contents(rex_addon::get('block_peek')->getPath('templates/picker-prompt.md'));
    }

    public static function template(): string
    {
        $custom = trim((string) rex_addon::get('block_peek')->getConfig('picker_prompt'));
        return $custom !== '' ? $custom : self::defaultPrompt();
    }

    /**
     * @param array{module_dir:string, module_name:string, context_paths:list<string>, style_reference:string, reference_image:string, language:string} $vars
     */
    public static function build(string $template, array $vars): string
    {
        $style = $vars['style_reference'] === ''
            ? 'STYLE REFERENCE: none, the vocabulary above applies.'
            : 'STYLE REFERENCE (another module of this site; match its vocabulary, colours and level of detail, not its layout): ' . $vars['style_reference'];
        $image = $vars['reference_image'] === ''
            ? ''
            : 'The image at ' . $vars['reference_image'] . ' is a screenshot of another page of the same website, for brand feel.';

        return strtr($template, [
            '{module_dir}' => $vars['module_dir'],
            '{module_name}' => $vars['module_name'],
            '{context_paths}' => implode(', ', $vars['context_paths']),
            '{style_reference_block}' => $style,
            '{reference_image_sentence}' => $image,
            '{language}' => $vars['language'],
        ]);
    }

    /** @return list<string> */
    public static function contextPaths(string $setting): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $setting)), static fn (string $l): bool => $l !== ''));
    }
}
