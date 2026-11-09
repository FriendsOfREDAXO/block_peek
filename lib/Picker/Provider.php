<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

interface Provider
{
    public function generate(string $prompt, ?string $imagePath): string;
    public function label(): string;
    public function inlineContext(): bool;
}
