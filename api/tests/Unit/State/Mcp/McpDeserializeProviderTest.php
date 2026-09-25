<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Mcp;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Property\PropertyNameCollection;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mcp\AnalyzeTripInput;
use App\ApiResource\TripRequest;
use App\State\Mcp\McpDeserializeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * What reaches the denormalizer, and what never does.
 *
 * The published input schema is the contract: a tool accepts the properties it publishes, minus
 * the ones that steer the call rather than describe the record. Everything else is refused by
 * name — silence would leave a model resending an argument that never lands.
 */
#[CoversClass(McpDeserializeProvider::class)]
final class McpDeserializeProviderTest extends TestCase
{
    /** @var array{data: mixed, type: string, context: array<string, mixed>}|null */
    private ?array $denormalized = null;

    #[Test]
    public function passesAnHttpRequestStraightThrough(): void
    {
        $loaded = new TripRequest();

        $provider = $this->provider($loaded, ['sourceUrl', 'maxDistancePerDay']);

        // No `mcp_data`: this is the HTTP chain, where DeserializeProvider has already run.
        $result = $provider->provide($this->tool(), ['id' => 'trip-1'], ['request' => null]);

        self::assertSame($loaded, $result);
        self::assertNull($this->denormalized, 'The MCP merge ran on an HTTP request.');
    }

    #[Test]
    public function passesThroughAToolThatDeclaresNoRecord(): void
    {
        $loaded = new TripRequest();

        $provider = $this->provider($loaded, ['sourceUrl']);

        $result = $provider->provide(
            new McpTool(name: 'get_trip', input: ['class' => AnalyzeTripInput::class]),
            [],
            ['mcp_data' => ['id' => 'trip-1']],
        );

        self::assertSame($loaded, $result);
        self::assertNull($this->denormalized, 'A tool with no `mcp_input` had its arguments denormalized anyway.');
    }

    /**
     * Three kinds of argument share one bag, and only one of them describes the record.
     *
     * `version`, `idempotencyKey` and `confirmationToken` steer the call; `id` addresses it and
     * the handler has already copied it into the URI variables; `action` is this tool's own
     * discriminator. None is a field of the trip, and `version` in particular would be an
     * optimistic-concurrency counter set by the caller it is meant to constrain.
     */
    #[Test]
    public function keepsSteeringArgumentsOutOfTheRecord(): void
    {
        $provider = $this->provider(null, ['sourceUrl', 'maxDistancePerDay', 'id', 'version', 'action']);

        $provider->provide($this->tool(), ['id' => 'trip-1'], ['mcp_data' => [
            'id' => 'trip-1',
            'version' => 7,
            'idempotencyKey' => 'k',
            'confirmationToken' => 'deadbeefdeadbeefdeadbeefdeadbeef',
            'action' => 'move',
            'sourceUrl' => 'https://www.komoot.com/tour/1',
            'maxDistancePerDay' => 95.0,
        ]]);

        self::assertNotNull($this->denormalized);
        self::assertSame(
            ['sourceUrl' => 'https://www.komoot.com/tour/1', 'maxDistancePerDay' => 95.0],
            $this->denormalized['data'],
        );
        self::assertSame(TripRequest::class, $this->denormalized['type']);
    }

    #[Test]
    public function refusesAnArgumentItDoesNotPublishAndSaysWhich(): void
    {
        $provider = $this->provider(null, ['sourceUrl']);

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/Unknown argument\(s\): "dailyBudget"/');

        $provider->provide($this->tool(), ['id' => 'trip-1'], ['mcp_data' => [
            'sourceUrl' => 'https://www.komoot.com/tour/1',
            'dailyBudget' => 42,
        ]]);
    }

    /**
     * The edit is applied to a copy, never to the row.
     *
     * Leaving the loaded record clean is what lets a guard refuse the call — a stale version, a
     * started trip, a missing confirmation token — without the refused edit sitting in the
     * identity map waiting for someone else's flush. `storeRequest()` is built to take a
     * detached source.
     */
    #[Test]
    public function mergesIntoACopyAndLeavesTheLoadedRecordAlone(): void
    {
        $loaded = new TripRequest();
        $loaded->maxDistancePerDay = 80.0;

        $provider = $this->provider($loaded, ['maxDistancePerDay']);

        $provider->provide($this->tool(), ['id' => 'trip-1'], ['mcp_data' => ['maxDistancePerDay' => 95.0]]);

        self::assertNotNull($this->denormalized);

        $populate = $this->denormalized['context'][AbstractNormalizer::OBJECT_TO_POPULATE] ?? null;
        self::assertInstanceOf(TripRequest::class, $populate);
        self::assertNotSame($loaded, $populate, 'The loaded record itself was handed to the denormalizer.');
        self::assertSame(80.0, $populate->maxDistancePerDay);
        self::assertTrue($this->denormalized['context'][AbstractObjectNormalizer::DEEP_OBJECT_TO_POPULATE] ?? false);
    }

    #[Test]
    public function buildsAFreshRecordWhenNothingWasLoaded(): void
    {
        $provider = $this->provider(null, ['sourceUrl']);

        $provider->provide($this->tool(), [], ['mcp_data' => ['sourceUrl' => 'https://www.komoot.com/tour/1']]);

        self::assertNotNull($this->denormalized);
        self::assertArrayNotHasKey(AbstractNormalizer::OBJECT_TO_POPULATE, $this->denormalized['context']);
    }

    /** A creation is not the only non-tool caller: an HTTP operation must be left alone too. */
    #[Test]
    public function ignoresAnOperationThatIsNotATool(): void
    {
        $loaded = new TripRequest();

        $provider = $this->provider($loaded, ['sourceUrl']);

        $result = $provider->provide(
            new Get(extraProperties: [McpDeserializeProvider::INPUT => TripRequest::class]),
            [],
            ['request' => null],
        );

        self::assertSame($loaded, $result);
        self::assertNull($this->denormalized);
    }

    private function tool(): McpTool
    {
        return new McpTool(
            name: 'update_trip_settings',
            input: ['class' => AnalyzeTripInput::class],
            extraProperties: [
                McpDeserializeProvider::INPUT => TripRequest::class,
                McpDeserializeProvider::CONTROL => ['action'],
            ],
        );
    }

    /**
     * @param list<string> $published
     */
    private function provider(?object $loaded, array $published): McpDeserializeProvider
    {
        $decorated = $this->createStub(ProviderInterface::class);
        $decorated->method('provide')->willReturn($loaded);

        $names = $this->createStub(PropertyNameCollectionFactoryInterface::class);
        $names->method('create')->willReturn(new PropertyNameCollection($published));

        $denormalizer = $this->createStub(DenormalizerInterface::class);
        $denormalizer->method('denormalize')->willReturnCallback(
            function (mixed $data, string $type, ?string $format = null, array $context = []): object {
                $this->denormalized = ['data' => $data, 'type' => $type, 'context' => $context];

                return $context[AbstractNormalizer::OBJECT_TO_POPULATE] ?? new TripRequest();
            },
        );

        return new McpDeserializeProvider($decorated, $denormalizer, $names);
    }
}
