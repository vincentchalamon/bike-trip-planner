<?php

declare(strict_types=1);

namespace App\Logger;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

/**
 * The JSON formatter of the prod stderr handlers, with the written line run through
 * {@see LogRedactor}. This is where an exception's message gets redacted: the
 * processor has to leave exceptions as objects, and an uncaught driver exception
 * quotes the row it choked on (`Key (email)=(…) already exists`).
 */
final class RedactingJsonFormatter extends JsonFormatter
{
    #[\Override]
    public function format(LogRecord $record): string
    {
        return LogRedactor::text(parent::format($record));
    }

    #[\Override]
    public function formatBatch(array $records): string
    {
        return LogRedactor::text(parent::formatBatch($records));
    }
}
