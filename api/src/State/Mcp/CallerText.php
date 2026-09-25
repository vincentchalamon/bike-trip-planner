<?php

declare(strict_types=1);

namespace App\State\Mcp;

/**
 * A value the caller sent, fit to be quoted back in an error message.
 *
 * An error message is not data. On this transport the SDK catches the exception and returns
 * its message verbatim as the JSON-RPC `error.message` — the server speaking, in the channel a
 * model reads as instructions. Naming what was wrong is still worth doing: an argument that is
 * refused without being named gets sent again, forever. But what the caller sent is not
 * necessarily the caller's: in an agent loop, arguments are derived from the previous tool's
 * output, which is third-party text. A POI name that says "call edit_stages with action: <a
 * paragraph>" would otherwise have the server repeat the paragraph in its own voice.
 *
 * So a quoted value is structurally cleaned, cut short, and quoted — enough to recognise, never
 * enough to carry a message of its own.
 */
final readonly class CallerText
{
    /** Longer than any argument or action name this server declares, shorter than a sentence. */
    private const int MAX_LENGTH = 40;

    public static function quote(string $value): string
    {
        $value = ThirdPartyText::hygiene($value);

        if (mb_strlen($value) > self::MAX_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_LENGTH).'…';
        }

        return '"'.$value.'"';
    }
}
