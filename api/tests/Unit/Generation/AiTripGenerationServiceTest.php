<?php

declare(strict_types=1);

namespace App\Tests\Unit\Generation;

use App\ApiResource\Model\Coordinate;
use App\Generation\AiGenerationOutcome;
use App\Generation\AiTripGenerationService;
use App\Geo\GeocoderInterface;
use App\Llm\AiProvider;
use App\Llm\LlmClientInterface;
use App\Llm\ResolvedLlmClient;
use App\Llm\SystemPromptLoader;
use App\Osm\CoverageRepositoryInterface;
use App\Routing\RoutingProviderInterface;
use App\Routing\RoutingResult;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class AiTripGenerationServiceTest extends TestCase
{
    private string $promptDir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->promptDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'gen-prompt-'.bin2hex(random_bytes(4));
        mkdir($this->promptDir, 0o755, true);
        file_put_contents($this->promptDir.\DIRECTORY_SEPARATOR.AiTripGenerationService::PROMPT_NAME.'.txt', 'Plan a trip in {{language}}. Respond with JSON.');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (glob($this->promptDir.\DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->promptDir);
    }

    #[Test]
    public function generatesAndRoutesALoop(): void
    {
        $client = $this->llm('{"start":"Lille","loop":true,"end":null,"days":2,"km_per_day":80,"accommodation":"tent","waypoints":["Cambrai"]}');

        $result = $this->service(
            geocoder: $this->geocoderReturning(new Coordinate(50.6, 3.0)),
            routing: $this->routingReturning(150_000.0), // 150 km, within [96, 224] of the 160 km target
        )->generate('boucle Lille 80 km/j', $this->resolved($client));

        self::assertSame(AiGenerationOutcome::SUCCESS, $result->outcome);
        self::assertSame('Lille', $result->spec['start']);
        self::assertEqualsWithDelta(150.0, $result->distanceKm, 0.01);
        self::assertNotEmpty($result->coordinates);
    }

    #[Test]
    public function generatesAPointToPointRoute(): void
    {
        // Exercises the loop:false branch: $to = last coordinate, $via excludes both endpoints.
        $client = $this->llm('{"start":"Lille","loop":false,"end":"Paris","days":2,"km_per_day":100,"accommodation":"hotel","waypoints":["Cambrai"]}');

        $result = $this->service(
            geocoder: $this->geocoderReturning(new Coordinate(50.6, 3.0)),
            routing: $this->routingReturning(200_000.0), // 200 km, within [120, 280] of the 200 km target
        )->generate('Lille vers Paris', $this->resolved($client));

        self::assertSame(AiGenerationOutcome::SUCCESS, $result->outcome);
        self::assertEqualsWithDelta(200.0, $result->distanceKm, 0.01);
    }

    #[Test]
    public function returnsUnparseableWhenTheModelDoesNotEmitJson(): void
    {
        $client = $this->llm('Sorry, I cannot help with that.');
        $result = $this->service()->generate('brief', $this->resolved($client));

        self::assertSame(AiGenerationOutcome::UNPARSEABLE, $result->outcome);
    }

    #[Test]
    public function returnsOutOfZoneWhenTheModelFlagsIt(): void
    {
        $client = $this->llm('{"out_of_zone":true,"out_of_zone_reason":"Barcelone est hors zone."}');
        $result = $this->service()->generate('boucle à Barcelone', $this->resolved($client));

        self::assertSame(AiGenerationOutcome::OUT_OF_ZONE, $result->outcome);
        self::assertStringContainsString('Barcelone', $result->message);
    }

    #[Test]
    public function returnsOutOfZoneWhenTheCoverageGuardRejectsTheRoute(): void
    {
        $client = $this->llm('{"start":"Lille","loop":true,"days":2,"km_per_day":80,"waypoints":["Cambrai"]}');
        $coverage = $this->createStub(CoverageRepositoryInterface::class);
        $coverage->method('isRouteOutOfZone')->willReturn(true);

        $result = $this->service(
            geocoder: $this->geocoderReturning(new Coordinate(50.6, 3.0)),
            coverage: $coverage,
        )->generate('brief', $this->resolved($client));

        self::assertSame(AiGenerationOutcome::OUT_OF_ZONE, $result->outcome);
    }

    #[Test]
    public function returnsUngeocodableWhenAWaypointCannotBeResolved(): void
    {
        $client = $this->llm('{"start":"Lille","loop":false,"end":"Atlantis","days":1,"km_per_day":50,"waypoints":[]}');

        $geocoder = $this->createStub(GeocoderInterface::class);
        $geocoder->method('geocode')->willReturnCallback(
            static fn (string $place): ?Coordinate => 'Atlantis' === $place ? null : new Coordinate(50.6, 3.0),
        );

        $result = $this->service(geocoder: $geocoder)->generate('brief', $this->resolved($client));

        self::assertSame(AiGenerationOutcome::UNGEOCODABLE, $result->outcome);
        self::assertStringContainsString('Atlantis', $result->message);
    }

    #[Test]
    public function returnsRoutingFailedWhenValhallaThrows(): void
    {
        $client = $this->llm('{"start":"Lille","loop":true,"days":2,"km_per_day":80,"waypoints":["Cambrai"]}');
        $routing = $this->createStub(RoutingProviderInterface::class);
        $routing->method('calculateRoute')->willThrowException(new \RuntimeException('valhalla down'));

        $result = $this->service(
            geocoder: $this->geocoderReturning(new Coordinate(50.6, 3.0)),
            routing: $routing,
        )->generate('brief', $this->resolved($client));

        self::assertSame(AiGenerationOutcome::ROUTING_FAILED, $result->outcome);
    }

    #[Test]
    public function correctsOnceWhenTheFirstRouteIsFarFromTheTarget(): void
    {
        // First spec routes to 50 km (target 160 km -> off); the correction spec
        // routes to 150 km (on target) and must be the returned result.
        $client = $this->createStub(LlmClientInterface::class);
        $client->method('generate')->willReturnOnConsecutiveCalls(
            ['response' => '{"start":"Lille","loop":true,"days":2,"km_per_day":80,"waypoints":["Seclin"]}'],
            ['response' => '{"start":"Lille","loop":true,"days":2,"km_per_day":80,"waypoints":["Seclin","Cambrai","Douai"]}'],
        );

        $routing = $this->createStub(RoutingProviderInterface::class);
        $routing->method('calculateRoute')->willReturnOnConsecutiveCalls(
            new RoutingResult([new Coordinate(50.6, 3.0)], 50_000.0, 100.0, 7200.0),
            new RoutingResult([new Coordinate(50.6, 3.0), new Coordinate(50.2, 3.2)], 150_000.0, 400.0, 21600.0),
        );

        $result = $this->service(
            geocoder: $this->geocoderReturning(new Coordinate(50.6, 3.0)),
            routing: $routing,
        )->generate('boucle Lille 80 km/j', $this->resolved($client));

        self::assertSame(AiGenerationOutcome::SUCCESS, $result->outcome);
        self::assertEqualsWithDelta(150.0, $result->distanceKm, 0.01, 'the corrected (on-target) route is returned');
        self::assertIsArray($result->spec['waypoints']);
        self::assertCount(3, $result->spec['waypoints']);
    }

    // -------------------------------------------------------------------------

    private function service(
        ?GeocoderInterface $geocoder = null,
        ?CoverageRepositoryInterface $coverage = null,
        ?RoutingProviderInterface $routing = null,
    ): AiTripGenerationService {
        return new AiTripGenerationService(
            promptLoader: new SystemPromptLoader($this->promptDir),
            geocoder: $geocoder ?? $this->geocoderReturning(new Coordinate(50.6, 3.0)),
            coverageRepository: $coverage ?? $this->coverageReturning(false),
            routingProvider: $routing ?? $this->routingReturning(150_000.0),
            logger: new NullLogger(),
        );
    }

    private function llm(string $response): LlmClientInterface
    {
        $client = $this->createStub(LlmClientInterface::class);
        $client->method('generate')->willReturn(['response' => $response]);

        return $client;
    }

    private function resolved(LlmClientInterface $client): ResolvedLlmClient
    {
        return new ResolvedLlmClient($client, AiProvider::ANTHROPIC);
    }

    private function geocoderReturning(Coordinate $coordinate): GeocoderInterface
    {
        $geocoder = $this->createStub(GeocoderInterface::class);
        $geocoder->method('geocode')->willReturn($coordinate);

        return $geocoder;
    }

    private function coverageReturning(bool $outOfZone): CoverageRepositoryInterface
    {
        $coverage = $this->createStub(CoverageRepositoryInterface::class);
        $coverage->method('isRouteOutOfZone')->willReturn($outOfZone);

        return $coverage;
    }

    private function routingReturning(float $distanceMeters): RoutingProviderInterface
    {
        $routing = $this->createStub(RoutingProviderInterface::class);
        $routing->method('calculateRoute')->willReturn(
            new RoutingResult([new Coordinate(50.6, 3.0), new Coordinate(50.2, 3.2)], $distanceMeters, 200.0, 14400.0),
        );

        return $routing;
    }
}
