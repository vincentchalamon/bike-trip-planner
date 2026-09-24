<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;

/**
 * An OAuth client, identified by a URL rather than by an opaque 32-character string.
 *
 * The bundle's own Client entity declares `identifier` as VARCHAR(32)
 * ({@see \League\Bundle\OAuth2ServerBundle\Persistence\Mapping\Driver::buildClientMetadata}),
 * which fits a generated client id and nothing else. A Client ID Metadata Document names a
 * client by the HTTPS URL its metadata is served from — `https://app.example.com/oauth/
 * client-metadata.json` is already 46 characters — so the default column cannot hold one.
 *
 * Keeping the client out of the database instead is not an option: `oauth2_access_token`,
 * `oauth2_authorization_code` and `oauth2_refresh_token` each carry a foreign key to
 * `oauth2_client.identifier` with ON DELETE CASCADE, so a persisted token requires a
 * persisted client. Hashing the URL into 32 characters is not one either: league looks the
 * client up by the identifier the request sent, in the authorization controller, in the
 * event factory and in the token endpoint, and a single missed translation point would not
 * fail — it would match the wrong row.
 *
 * So the column is widened, and the resolver refuses a client_id longer than it.
 *
 * Everything else — name, secret, redirect URIs, grants, scopes, active, plaintext-PKCE
 * flag — comes from AbstractClient, which the bundle maps as a mapped superclass.
 */
#[ORM\Entity]
#[ORM\Table(name: 'oauth2_client')]
class OAuthClient extends AbstractClient
{
    /**
     * Long enough for any client metadata URL worth honouring, and far below the ~2704-byte
     * ceiling a Postgres btree index puts on a primary key.
     */
    public const int IDENTIFIER_MAX_LENGTH = 512;

    /** @var non-empty-string */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: self::IDENTIFIER_MAX_LENGTH)]
    #[\Override]
    protected string $identifier;
}
