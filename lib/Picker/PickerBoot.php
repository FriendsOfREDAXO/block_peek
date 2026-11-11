<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use rex;
use rex_addon;
use rex_logger;
use Throwable;

final class PickerBoot
{
    public static function shouldLoad(?string $configValue, string $page, bool $backendUser): bool
    {
        return $configValue === '|1|' && $page === 'content/edit' && $backendUser;
    }

    /**
     * Previews that need a (re)generation, for the admin nudge; [] when the nudge must not show.
     *
     * @param list<array{id:int, key:?string, name:string, input:string, output:string}>|null $modules default: all modules
     * @return array<int, string> module id => outdated|missing|failed
     */
    public static function staleStates(?array $modules = null): array
    {
        if (rex_addon::get('block_peek')->getConfig('picker') !== '|1|' || !rex::getUser()?->isAdmin()) {
            return [];
        }
        try {
            if (Providers::fromConfig() === null) {
                return [];
            }
        } catch (Throwable) {
            return []; // ponytail: a broken provider is logged and shown on the picker page, not on every backend view
        }
        try {
            return (new PreviewStore())->staleStates($modules ?? PreviewGenerator::modules());
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return [];
        }
    }
}
