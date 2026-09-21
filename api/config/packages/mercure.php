<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('mercure', [
        'hubs' => [
            'default' => [
                'url' => '%env(MERCURE_URL)%',
                'public_url' => '%env(MERCURE_PUBLIC_URL)%',
                // Mercure protocol 1.0: RFC 9068 access tokens (`typ: at+jwt`,
                // `authorization_details`) instead of the legacy `mercure` claim.
                // The hub is the FrankenPHP-embedded module, rebuilt at v1.0.0 —
                // see .docker/php/Dockerfile and the ADR-037 addendum.
                'protocol_version' => '1.0',
                'jwt' => [
                    'secret' => '%env(MERCURE_JWT_SECRET)%',
                    'publish' => '*',
                    // `iss` must equal the Caddyfile `issuer` identifier, and `aud`
                    // the hub's `resource_identifier`. `aud` is pinned rather than
                    // defaulted because publishing goes through the internal
                    // MERCURE_URL while subscribers use the public one, and the
                    // bundle would otherwise derive a different audience for each.
                    // `sub`/`client_id` are required by RFC 9068; the bundle fails
                    // at container compile time if any of the three is missing.
                    'claims' => [
                        'iss' => '%env(MERCURE_ISSUER)%',
                        'aud' => '%env(MERCURE_PUBLIC_URL)%',
                        'sub' => 'bike-trip-planner-api',
                        'client_id' => 'bike-trip-planner-api',
                    ],
                ],
            ],
        ],
    ]);
};
