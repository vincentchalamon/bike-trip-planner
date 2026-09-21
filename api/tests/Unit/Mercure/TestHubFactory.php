<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mercure;

use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\DefaultClaimsTokenFactory;
use Symfony\Component\Mercure\Jwt\FactoryTokenProvider;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\ProtocolVersion;

/**
 * Builds the hub the unit tests hand to {@see \App\Mercure\MercureTokenIssuer},
 * mirroring what MercureBundle wires for a `protocol_version: 1.0` hub in
 * config/packages/mercure.php: an RFC 9068 token factory wrapped so every token
 * carries the registered `iss`/`aud`/`sub`/`client_id` claims.
 */
final class TestHubFactory
{
    public const string SECRET = 'test-mercure-secret-key-that-is-at-least-256-bits-long!';

    public const string ISSUER = 'https://localhost';

    public const string AUDIENCE = 'https://localhost/.well-known/mercure';

    public static function create(string $publicUrl = self::AUDIENCE): HubInterface
    {
        $factory = new DefaultClaimsTokenFactory(
            new LcobucciFactory(self::SECRET, protocolVersion: ProtocolVersion::V1),
            [
                'iss' => self::ISSUER,
                'aud' => self::AUDIENCE,
                'sub' => 'bike-trip-planner-api',
                'client_id' => 'bike-trip-planner-api',
            ],
        );

        return new Hub(
            $publicUrl,
            new FactoryTokenProvider($factory),
            $factory,
            protocolVersion: ProtocolVersion::V1,
        );
    }
}
