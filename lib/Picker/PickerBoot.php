<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

final class PickerBoot
{
    public static function shouldLoad(?string $configValue, string $page, bool $backendUser): bool
    {
        return $configValue === '|1|' && $page === 'content/edit' && $backendUser;
    }
}
