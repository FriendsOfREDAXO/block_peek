<?php

declare(strict_types=1);

namespace FriendsOfRedaxo\BlockPeek\Picker;

use Closure;
use rex_addon;
use RuntimeException;

final class AiPlatformProvider implements Provider
{
    private Closure $invoke;

    public function __construct(private int $profileId, ?Closure $invoke = null, private ?string $profileName = null)
    {
        $this->invoke = $invoke ?? static function (string $prompt, ?string $imagePath) use ($profileId): string {
            if (!rex_addon::get('ai_platform')->isAvailable()) {
                throw new RuntimeException('ai_platform is not available');
            }
            $service = \FriendsOfRedaxo\AiPlatform\Service::getInstance();
            return $imagePath !== null
                ? $service->understandImage($prompt, $imagePath, $profileId)
                : $service->generateText($prompt, null, $profileId);
        };
    }

    public function generate(string $prompt, ?string $imagePath): string
    {
        return ($this->invoke)($prompt, $imagePath);
    }

    public function label(): string
    {
        $name = $this->profileName;
        if ($name === null && rex_addon::get('ai_platform')->isAvailable()) {
            $name = \FriendsOfRedaxo\AiPlatform\Service::getInstance()->getProfile($this->profileId)['name'] ?? null;
        }
        return 'ai_platform:' . ($name ?? (string) $this->profileId);
    }

    public function inlineContext(): bool
    {
        return true;
    }
}
