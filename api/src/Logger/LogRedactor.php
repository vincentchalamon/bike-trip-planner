<?php

declare(strict_types=1);

namespace App\Logger;

/**
 * Takes what must never be written down out of a string bound for a log line or an
 * error report: email addresses, and the parts of a URL that are a credential.
 *
 * The call sites already avoid logging these (NoEmailInLogsTest), but the framework
 * writes lines of its own: the security channel prints the authenticated token, whose
 * user identifier is the address; the request channel prints every matched URI with
 * its route parameters; an uncaught driver exception quotes the offending row. This
 * is the net under those.
 *
 * The patterns are deliberately conservative: a false positive costs a redacted word
 * in a log line, a false negative costs an address or a credential in a log store.
 */
final class LogRedactor
{
    public const string REDACTED = '[redacted]';

    private const string EMAIL = '/[A-Za-z0-9._%+-]+(?:@|%40)[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/i';

    /**
     * Path segments that are a credential: the verify tokens of the emailed links (their
     * old path form, and the fragment of the current one), share codes, FCM tokens. A
     * route template placeholder (`/s/{shortCode}`) is left alone.
     */
    private const string SECRET_PATH = '#(/auth/verify[/\#]|/account/email-change/verify[/\#]|/access-requests/verify\#|/s/|/users/me/device-tokens/(?!unregister\b))[^/?\#\s"\'<>.{]+#';

    /** Query parameters whose value is a credential or personal data (an address, a position). */
    private const string SECRET_QUERY = '/([?&#](?:email|signature|token|share_token|access_token|refresh_token|id_token|code|state|apikey|api_key|key|password|lat|lon|lng|latitude|longitude)=)[^&#\s"\'<>]*/i';

    /** Route parameters and context keys whose value is a credential. */
    public const array SECRET_KEYS = ['token', 'plainToken', 'shortCode', 'signature', 'refresh_token', 'password', 'email', 'newEmail'];

    public static function text(string $text): string
    {
        return preg_replace([self::EMAIL, self::SECRET_PATH, self::SECRET_QUERY], ['[email]', '$1'.self::REDACTED, '$1'.self::REDACTED], $text) ?? self::REDACTED;
    }

    /**
     * A URL without its query string and fragment, its secret path segments redacted.
     */
    public static function url(string $url): string
    {
        return self::text((string) preg_replace('/[?#].*$/s', '', $url));
    }

    /**
     * Redacts every string in a nested array, and the whole value of any secret key.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function array(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && \in_array($key, self::SECRET_KEYS, true) && (\is_string($value) || \is_array($value))) {
                $data[$key] = self::REDACTED;
            } elseif (\is_string($value)) {
                $data[$key] = self::text($value);
            } elseif (\is_array($value)) {
                $data[$key] = self::array($value);
            }
        }

        return $data;
    }
}
