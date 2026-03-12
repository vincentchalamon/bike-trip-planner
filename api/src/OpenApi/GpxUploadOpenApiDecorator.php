<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Adds the /trips/gpx-upload POST endpoint to the OpenAPI specification.
 *
 * Since GpxUploadController is a plain Symfony controller (not an API Platform resource),
 * its response shape would be invisible to typegen without this decorator.
 * This ensures the frontend type contract (OpenAPI -> TypeScript) covers file uploads.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class GpxUploadOpenApiDecorator implements OpenApiFactoryInterface
{
    public function __construct(
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $pathItem = new Model\PathItem(
            post: new Model\Operation(
                operationId: 'gpxUpload',
                tags: ['Trip'],
                responses: [
                    202 => new Model\Response(
                        description: 'Trip created from GPX upload',
                        content: new \ArrayObject([
                            'application/json' => new Model\MediaType(
                                schema: new \ArrayObject([
                                    'type' => 'object',
                                    'properties' => [
                                        '@context' => ['type' => 'string', 'example' => '/contexts/Trip'],
                                        '@id' => ['type' => 'string', 'example' => '/trips/01234567-89ab-cdef-0123-456789abcdef'],
                                        '@type' => ['type' => 'string', 'example' => 'Trip'],
                                        'id' => ['type' => 'string', 'format' => 'uuid'],
                                        'computationStatus' => [
                                            'type' => 'object',
                                            'additionalProperties' => ['type' => 'string'],
                                        ],
                                    ],
                                    'required' => ['@context', '@id', '@type', 'id', 'computationStatus'],
                                ]),
                            ),
                        ]),
                    ),
                    400 => new Model\Response(
                        description: 'Bad request (missing file, invalid extension, empty file)',
                        content: new \ArrayObject([
                            'application/json' => new Model\MediaType(
                                schema: new \ArrayObject([
                                    'type' => 'object',
                                    'properties' => [
                                        'error' => ['type' => 'string'],
                                    ],
                                ]),
                            ),
                        ]),
                    ),
                    422 => new Model\Response(
                        description: 'Unprocessable entity (invalid GPX, no track points)',
                        content: new \ArrayObject([
                            'application/json' => new Model\MediaType(
                                schema: new \ArrayObject([
                                    'type' => 'object',
                                    'properties' => [
                                        'error' => ['type' => 'string'],
                                    ],
                                ]),
                            ),
                        ]),
                    ),
                ],
                summary: 'Upload a GPX file to create a trip',
                description: 'Parses the GPX file synchronously, creates a trip, and dispatches async computations (stage generation, OSM scan).',
                requestBody: new Model\RequestBody(
                    description: 'GPX file upload with optional trip parameters',
                    content: new \ArrayObject([
                        'multipart/form-data' => new Model\MediaType(
                            schema: new \ArrayObject([
                                'type' => 'object',
                                'properties' => [
                                    'gpxFile' => ['type' => 'string', 'format' => 'binary', 'description' => 'GPX file (max 15 MB)'],
                                    'startDate' => ['type' => 'string', 'format' => 'date', 'description' => 'Trip start date'],
                                    'endDate' => ['type' => 'string', 'format' => 'date', 'description' => 'Trip end date'],
                                    'fatigueFactor' => ['type' => 'number', 'description' => 'Fatigue factor (0-1)'],
                                    'elevationPenalty' => ['type' => 'number', 'description' => 'Elevation penalty factor'],
                                    'ebikeMode' => ['type' => 'boolean', 'description' => 'E-bike mode'],
                                ],
                                'required' => ['gpxFile'],
                            ]),
                        ),
                    ]),
                    required: true,
                ),
            ),
        );

        $openApi->getPaths()->addPath('/trips/gpx-upload', $pathItem);

        return $openApi;
    }
}
