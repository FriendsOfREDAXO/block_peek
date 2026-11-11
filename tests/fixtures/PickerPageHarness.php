<?php

declare(strict_types=1);

namespace BlockPeekTests;

final class PickerRedirect extends \RuntimeException {}

// Isolate only the terminating HTTP boundary; all page logic and REDAXO services stay real.
final class rex_response
{
    public static ?string $redirect = null;

    public static function sendRedirect($url, $httpStatus = null): never
    {
        self::$redirect = $url;
        throw new PickerRedirect();
    }
}

function pickerPageSource(string $path): string
{
    return 'namespace BlockPeekTests; use \rex, \rex_addon, \rex_view, \rex_escape, \rex_i18n, \rex_url, \rex_csrf_token, \rex_logger, \rex_fragment, \Throwable;'
        . substr(file_get_contents($path), 5);
}

final class CapturingLogger extends \rex_logger
{
    public static array $messages = [];

    public function log($level, $message, array $context = [], $file = null, $line = null, ?string $url = null): void
    {
        self::$messages[] = (string) $message;
    }
}
