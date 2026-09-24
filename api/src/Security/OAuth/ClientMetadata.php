<?php

declare(strict_types=1);

namespace App\Security\OAuth;

/**
 * A Client ID Metadata Document, after validation.
 *
 * Only the three fields the specification makes mandatory are kept. `logo_uri` is read from
 * nowhere on purpose: rendering it would make the consent screen load an image from an
 * address the client chose.
 */
final readonly class ClientMetadata
{
    /**
     * The narrow types are earned: {@see ClientMetadataResolver} refuses a document that
     * leaves any of them empty, so nothing downstream has to check again.
     *
     * @param non-empty-string       $clientId
     * @param non-empty-string       $clientName
     * @param list<non-empty-string> $redirectUris
     */
    public function __construct(
        public string $clientId,
        public string $clientName,
        public array $redirectUris,
    ) {
    }
}
