<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('api_platform', [
        'title' => 'Bike Trip Planner API',
        'version' => '1.0.0',
        'mercure' => [
            'include_type' => true,
        ],
        'formats' => [
            'jsonld' => [
                'application/ld+json',
            ],
        ],
        'docs_formats' => [
            'jsonld' => [
                'application/ld+json',
            ],
            'jsonopenapi' => [
                'application/vnd.openapi+json',
            ],
            'html' => [
                'text/html',
            ],
        ],
        'path_segment_name_generator' => 'api_platform.metadata.path_segment_name_generator.dash',
        'defaults' => [
            'stateless' => true,
            // The published OpenAPI has always advertised `maximum: 30` on itemsPerPage — the
            // bundle feeds PaginationOptions from its own default. The runtime never enforced
            // it: `Pagination` is built from a different parameter, which carried no maximum,
            // so the guard in Pagination::getLimit() was never entered and a client could ask
            // for any page size it liked. This makes the number the contract already publishes
            // true, and every future collection inherits it.
            'pagination_maximum_items_per_page' => 30,
            'cache_headers' => [
                // No `vary` key on purpose. It used to carry Content-Type, Authorization and
                // Origin, and **none of it ever reached the wire**: measured with a probe
                // value, what ships is `Vary: Accept` on every operation — including one that
                // declares its own list. `RespondProcessor` sets that header when it builds
                // the response (state/Util/HttpResponseHeadersTrait.php:66) and it is what
                // survives, while `AddHeadersProcessor`'s own `setVary` does not. Configured
                // values here were decoration; `Accept` is the right answer for the three
                // negotiated formats anyway, and `public => false` below is what keeps the
                // rest of the headers honest.
                //
                // Every response is one user's data. Without this nothing carries a cache
                // directive at all — on responses that do carry an ETag.
                'public' => false,
            ],
            'extra_properties' => [
                'standard_put' => true,
                'rfc_7807_compliant_errors' => true,
            ],
            'collect_denormalization_errors' => true,
        ],
    ]);
};
