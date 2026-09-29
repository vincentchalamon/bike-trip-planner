<?php

declare(strict_types=1);

namespace App\Logger;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Runs every record, of every channel, through {@see LogRedactor} before any handler
 * sees it, the framework's own lines included: `Authenticator successful!` (security)
 * carries the token, whose string form prints the user identifier, i.e. the address;
 * `Matched route` (request) carries the full URI and the route parameters. In prod
 * both wait in the fingers_crossed buffer and are written out with the first error.
 *
 * A stringable context object other than an exception is replaced by its redacted
 * string: it is what the formatter would have printed anyway. An exception stays an
 * object, because handlers inspect it (the 404/405 exclusion does); its message is
 * redacted when the line is written, by {@see RedactingJsonFormatter}.
 */
final class RedactionProcessor implements ProcessorInterface
{
    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        foreach ($context as $key => $value) {
            if ($value instanceof \Stringable && !$value instanceof \Throwable) {
                $context[$key] = (string) $value;
            }
        }

        return $record->with(
            message: LogRedactor::text($record->message),
            context: LogRedactor::array($context),
            extra: LogRedactor::array($record->extra),
        );
    }
}
