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

    public static function showNudge(bool $admin, bool $hasProvider, int $count): bool
    {
        return $admin && $hasProvider && $count > 0;
    }

    public static function loginNoticeDue(mixed $sessionStart, mixed $notedFor): bool
    {
        return is_scalar($sessionStart) && (string) $sessionStart !== '' && (string) $notedFor !== (string) $sessionStart;
    }

    /**
     * Previews that need a (re)generation, for the admin nudge; [] when the nudge must not show.
     *
     * @return array<int, string> module id => outdated|missing|failed
     */
    public static function staleStates(): array
    {
        if (rex_addon::get('block_peek')->getConfig('picker') !== '|1|' || !rex::getUser()?->isAdmin()) {
            return [];
        }
        try {
            if (Providers::fromConfig() === null) {
                return [];
            }
            $states = (new PreviewStore())->staleStates(PreviewGenerator::modules());
        } catch (Throwable $e) {
            rex_logger::logException($e);
            return [];
        }
        return self::showNudge(true, true, count($states)) ? $states : [];
    }
}
