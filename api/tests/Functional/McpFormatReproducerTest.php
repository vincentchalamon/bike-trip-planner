<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripDetail;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Repository\DoctrineTripRequestRepository;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * REPRODUCER — `api_platform.mcp.format` has no effect on a tool's output.
 *
 * Configured state for this test:
 *   api_platform.formats:   { jsonld: [application/ld+json], json: [application/json] }
 *   api_platform.mcp.format: json
 *
 * The three tests below show, in order, that the metadata carries `json`, that the
 * serialized output is nevertheless JSON-LD, and that the only thing which does change
 * the format is the CLIENT's `_format` — i.e. the server-side configuration is ignored
 * and the client decides.
 *
 * Cause: ApiPlatform\Mcp\State\StructuredContentProcessor::process() line 66
 *   $format = $request->getRequestFormat('') ?: 'jsonld';
 * never consults $operation->getOutputFormats(), although $operation is in scope and has
 * been populated by FormatsResourceMetadataCollectionFactory.
 */
#[ResetDatabase]
final class McpFormatReproducerTest extends ApiTestCase
{
    use Factories;
    use JwtAuthTestTrait;

    private const string TRIP_ID = '01936f6e-0000-7000-8000-0000000009b2';

    private Client $client;

    private User $owner;

    private string $ownerToken;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['user' => $this->owner, 'token' => $this->ownerToken] = $this->createTestUserWithJwt('fmt@example.com');
    }

    #[Test]
    public function step1TheOperationMetadataCarriesTheConfiguredFormat(): void
    {
        /** @var ResourceMetadataCollectionFactoryInterface $factory */
        $factory = self::getContainer()->get('api_platform.metadata.resource.metadata_collection_factory');

        $outputFormats = null;
        foreach ($factory->create(TripDetail::class) as $resource) {
            foreach ($resource->getMcp() ?? [] as $name => $operation) {
                if ('get_trip' === $name) {
                    $outputFormats = $operation->getOutputFormats();
                }
            }
        }

        self::assertSame(
            ['json' => ['application/json']],
            $outputFormats,
            'The MCP operation should carry the configured api_platform.mcp.format.',
        );
    }

    #[Test]
    public function step2TheOutputIsNeverthelessJsonLd(): void
    {
        $this->seedTrip();

        $text = $this->callTool();

        self::assertStringNotContainsString(
            '@context',
            $text,
            'BUG: the tool serialized JSON-LD although api_platform.mcp.format is "json".',
        );
    }

    #[Test]
    public function step3NothingElseChangesTheFormatEither(): void
    {
        $this->seedTrip();

        // `getRequestFormat()` reads the `_format` REQUEST ATTRIBUTE, which only routing
        // populates (a `{._format}` suffix or a route default). The bundle's `/mcp` route
        // has neither, so the attribute is never set and the query string cannot help.
        // Line 66 therefore evaluates to a constant `'jsonld'` on every single call.
        self::assertStringContainsString('@context', $this->callTool(), 'No _format.');
        self::assertStringContainsString('@context', $this->callTool('?_format=json'), 'Query _format is inert too.');
    }

    private function callTool(string $query = ''): string
    {
        $response = $this->client->request('POST', '/mcp'.$query, [
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'MCP-Protocol-Version' => '2026-07-28',
                'Mcp-Method' => 'tools/call',
                'Mcp-Name' => 'get_trip',
            ], $this->authHeader($this->ownerToken)),
            'json' => [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_trip',
                    'arguments' => ['id' => self::TRIP_ID],
                    '_meta' => [
                        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                        'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                    ],
                ],
            ],
        ]);

        $payload = $response->toArray(false);
        $text = (string) ($payload['result']['content'][0]['text'] ?? json_encode($payload, \JSON_THROW_ON_ERROR));

        fwrite(\STDERR, "\n[REPRO".$query."] ".substr($text, 0, 220)."\n");

        return $text;
    }

    private function seedTrip(): void
    {
        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123456789';

        /** @var DoctrineTripRequestRepository $repo */
        $repo = self::getContainer()->get(DoctrineTripRequestRepository::class);
        $repo->initializeTrip(self::TRIP_ID, $request);
        $repo->storeTitle(self::TRIP_ID, 'Format reproducer trip');
        $this->associateTripWithUser(self::TRIP_ID, $this->owner);

        $repo->storeStages(self::TRIP_ID, [new StageDto(
            tripId: self::TRIP_ID,
            dayNumber: 1,
            distance: 10.0,
            elevation: 100.0,
            startPoint: new Coordinate(45.0, 6.0, 1000.0),
            endPoint: new Coordinate(45.1, 6.1, 900.0),
            geometry: [new Coordinate(45.0, 6.0, 1000.0)],
        )]);
        $repo->storeStatus(self::TRIP_ID, 'ready');
    }
}
