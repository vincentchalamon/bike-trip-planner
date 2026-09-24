<?php

declare(strict_types=1);

namespace App\Security\OAuth;

/**
 * A client id that names no metadata document we are willing to act on.
 *
 * Carries a reason for the log and nothing for the wire: the caller answers `invalid_client`
 * whatever happened. Telling a refused client whether its host resolved to a private address,
 * timed out, or returned the wrong JSON turns this endpoint into a network probe.
 */
final class ClientMetadataRejected extends \RuntimeException
{
}
