<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\ApiResource\Model\Coordinate;
use App\Generation\AiGenerationOutcome;
use App\Generation\AiTripGenerationService;
use App\Geo\GeocoderInterface;
use App\Llm\AiProvider;
use App\Llm\LlmClientInterface;
use App\Llm\ResolvedLlmClient;
use App\Llm\SystemPromptLoader;
use App\Osm\CoverageRepositoryInterface;
use App\Routing\ValhallaRoutingProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Integration coverage for the AI-generation routing leg (B0 condition 2): the
 * real {@see ValhallaRoutingProvider} — including its polyline6 decoder and the
 * km->meters conversion — wired into {@see AiTripGenerationService} against a
 * Valhalla-shaped HTTP response. The B0 spike could not reach the Valhalla
 * container from the CLI, so this closes that gap without provisioned tiles.
 */
final class AiGenerationRoutingTest extends TestCase
{
    private string $promptDir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->promptDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'ai-gen-routing-'.bin2hex(random_bytes(4));
        mkdir($this->promptDir, 0o755, true);
        file_put_contents(
            $this->promptDir.\DIRECTORY_SEPARATOR.AiTripGenerationService::PROMPT_NAME.'.txt',
            'Plan a trip in {{language}}. Respond with JSON.',
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->promptDir.\DIRECTORY_SEPARATOR.AiTripGenerationService::PROMPT_NAME.'.txt');
        @rmdir($this->promptDir);
    }

    #[Test]
    public function decodesAValhallaLoopIntoARoutedItinerary(): void
    {
        // Loop brief: start + one waypoint (>= 2 geocoded points). Target is
        // 2 x 80 = 160 km; the Valhalla summary reports 150 km — in range, so no
        // corrective re-prompt fires.
        $llm = $this->createStub(LlmClientInterface::class);
        $llm->method('generate')->willReturn([
            'response' => '{"start":"Lille","loop":true,"days":2,"km_per_day":80,"waypoints":["Cambrai"]}',
        ]);

        $service = new AiTripGenerationService(
            promptLoader: new SystemPromptLoader($this->promptDir),
            geocoder: $this->geocoder(),
            coverageRepository: $this->coverage(),
            routingProvider: $this->valhalla('_izlhA_yrwGAA', 150.0),
            logger: new NullLogger(),
        );

        $result = $service->generate('boucle Lille 80 km/j', new ResolvedLlmClient($llm, AiProvider::GEMINI));

        self::assertSame(AiGenerationOutcome::SUCCESS, $result->outcome);
        self::assertNotEmpty($result->coordinates, 'the decoded Valhalla polyline must yield geometry');
        self::assertContainsOnlyInstancesOf(Coordinate::class, $result->coordinates);
        self::assertEqualsWithDelta(150.0, $result->distanceKm, 0.01, 'distance comes from the Valhalla summary (km)');
    }

    private function geocoder(): GeocoderInterface
    {
        // Every named place resolves to an in-zone French coordinate.
        $geocoder = $this->createStub(GeocoderInterface::class);
        $geocoder->method('geocode')->willReturn(new Coordinate(50.63, 3.06));

        return $geocoder;
    }

    private function coverage(): CoverageRepositoryInterface
    {
        $coverage = $this->createStub(CoverageRepositoryInterface::class);
        $coverage->method('isRouteOutOfZone')->willReturn(false);

        return $coverage;
    }

    private function valhalla(string $shape, float $lengthKm): ValhallaRoutingProvider
    {
        $body = json_encode([
            'trip' => [
                'legs' => [['shape' => $shape]],
                'summary' => ['length' => $lengthKm, 'elevation_gain' => 0.0, 'time' => 18000.0],
            ],
        ], \JSON_THROW_ON_ERROR);

        // Callable client: the same payload answers every routing call (the route
        // plus any corrective re-prompt), so the test never trips on an exhausted
        // response queue.
        $httpClient = new MockHttpClient(
            static fn (): MockResponse => new MockResponse($body, ['http_code' => 200]),
            'http://valhalla:8002',
        );

        return new ValhallaRoutingProvider($httpClient, $this->passthroughCache());
    }

    private function passthroughCache(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            fn (string $key, callable $callback): mixed => $callback($this->createStub(ItemInterface::class)),
        );

        return $cache;
    }
}
