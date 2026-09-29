<?php

declare(strict_types=1);

namespace App\Logger;

/**
 * What a log line carries instead of an email address.
 *
 * Logs travel further than the database (aggregators, error trackers, support
 * copies) and are kept for longer, so an address never goes into one. Where a
 * User exists, log its id; where none does (an access request, an unknown
 * address), log this fingerprint, which still lets two lines about the same
 * address be matched without revealing it.
 */
final class EmailFingerprint
{
    public static function of(string $email): string
    {
        return substr(hash('sha256', mb_strtolower(trim($email))), 0, 12);
    }
}
