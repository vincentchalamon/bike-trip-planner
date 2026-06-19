<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\ApiResource\Model\GeoPosition;
use App\Geo\HaversineDistance;
use App\InRide\DeeplinkBuilder;
use App\InRide\DetourCalculator;
use App\InRide\InRideAssistant;
use App\InRide\InRidePoiRepositoryInterface;
use App\InRide\OpeningHoursParser;
use App\InRide\PoiIntentDetector;
use App\InRide\PoiSuggestion;
use App\Llm\AiProvider;
use App\Llm\Exception\AiUnavailableException;
use App\Llm\LlmClientInterface;
use App\Llm\ResolvedLlmClient;
use App\Llm\SystemPromptLoader;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Integration tests for {@see InRideAssistant} — exercises the full pipeline
 * (intent detection → local-first POI read → opening-hours filter → ranking →
 * narrative generation) with in-memory mocks for the LLM client and the POI
 * repository.
 */
#[AllowMockObjectsWithoutExpectations]
final class InRideAssistantTest extends TestCase
{
    private string $promptDir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->promptDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'in-ride-prompts-'.bin2hex(random_bytes(4));
        mkdir($this->promptDir, 0o755, true);
        file_put_contents($this->promptDir.'/in-ride.txt', 'Markdown response from POIs.');
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ('' === $this->promptDir) {
            return;
        }

        foreach (glob($this->promptDir.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->promptDir);
    }

    #[Test]
    public function unknownIntentReturnsExplanatoryNarrativeWithNoPois(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('generate')->willReturn([
            'response' => '{"category":"unknown","max_distance_m":3000}',
        ]);

        $poiRepository = $this->createMock(InRidePoiRepositoryInterface::class);
        $poiRepository->expects($this->never())->method('findNearby');

        $assistant = $this->buildAssistant($poiRepository);

        $response = $assistant->assist(
            message: 'Quel temps fait-il ?',
            position: new GeoPosition(50.8503, 4.3517),
            resolved: $this->resolved($llm),
        );

        $this->assertSame(PoiSuggestion::CATEGORY_UNKNOWN, $response->category);
        $this->assertSame([], $response->pois);
        $this->assertStringContainsString('point d\'intérêt', $response->narrative);
    }

    #[Test]
    public function waterIntentReadsIndexAndReturnsTopPois(): void
    {
        // Intent classification call → water, 3 km radius. Narrative call → markdown.
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('generate')->willReturnCallback(
            static function (string $model, string $prompt, ?string $systemPrompt = null, array $options = []): array {
                // The intent detector sends the user message as the prompt;
                // the narrative generator sends a JSON payload.
                if (str_starts_with(trim($prompt), '{')) {
                    return ['response' => "Voici l'eau la plus proche."];
                }

                return ['response' => '{"category":"water","max_distance_m":3000}'];
            },
        );

        $poiRepository = $this->poiRepository([
            ['lat' => 50.8504, 'lon' => 4.3520, 'tags' => ['name' => 'Fontaine du Sablon']],
            ['lat' => 50.8550, 'lon' => 4.3600, 'tags' => ['name' => 'Fontaine Royale']],
            // Anonymous water POIs are still surfaced under a generic label
            // because the coordinates alone are actionable (the deeplink only
            // needs lat/lon) — only food/mechanic queries drop unnamed rows.
            ['lat' => 50.8505, 'lon' => 4.3521, 'tags' => []],
            ['lat' => 50.8509, 'lon' => 4.3527, 'tags' => ['name' => 'Fontaine Centrale']],
        ]);

        $assistant = $this->buildAssistant($poiRepository);

        $response = $assistant->assist(
            message: "Je cherche un point d'eau",
            position: new GeoPosition(50.8503, 4.3517),
            resolved: $this->resolved($llm),
        );

        $this->assertSame(PoiSuggestion::CATEGORY_WATER, $response->category);
        $this->assertCount(3, $response->pois);
        $names = array_map(static fn (PoiSuggestion $p): string => $p->name, $response->pois);
        $this->assertContains('Fontaine du Sablon', $names);
        $this->assertContains('Fontaine Centrale', $names);
        $this->assertContains("Point d'eau", $names);
        $this->assertStringStartsWith('https://www.google.com/maps/dir/', $response->pois[0]->deeplink);
        $this->assertSame("Voici l'eau la plus proche.", $response->narrative);
    }

    #[Test]
    public function closedPoisAreFilteredOut(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('generate')->willReturnCallback(
            static function (string $model, string $prompt, ?string $systemPrompt = null, array $options = []): array {
                if (str_starts_with(trim($prompt), '{')) {
                    return ['response' => 'Le restaurant ouvert.'];
                }

                return ['response' => '{"category":"food","max_distance_m":3000}'];
            },
        );

        $poiRepository = $this->poiRepository([
            ['lat' => 50.8504, 'lon' => 4.3520, 'tags' => ['name' => 'Restaurant Fermé', 'opening_hours' => 'Mo-Fr 09:00-12:00']],
            ['lat' => 50.8510, 'lon' => 4.3525, 'tags' => ['name' => 'Frites 24/7', 'opening_hours' => '24/7']],
        ]);

        $assistant = $this->buildAssistant($poiRepository);

        // Sunday 14:00 UTC — outside the Mo-Fr 09:00-12:00 window of the first POI.
        $response = $assistant->assist(
            message: 'Je veux des frites',
            position: new GeoPosition(50.8503, 4.3517),
            resolved: $this->resolved($llm),
            now: new \DateTimeImmutable('2024-06-09 14:00:00', new \DateTimeZone('UTC')),
        );

        $this->assertCount(1, $response->pois);
        $this->assertSame('Frites 24/7', $response->pois[0]->name);
    }

    #[Test]
    public function topThreePoisAreReturned(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('generate')->willReturnCallback(
            static function (string $model, string $prompt, ?string $systemPrompt = null, array $options = []): array {
                if (str_starts_with(trim($prompt), '{')) {
                    return ['response' => 'Top trois.'];
                }

                return ['response' => '{"category":"food","max_distance_m":3000}'];
            },
        );

        $features = [];
        // 5 POIs at increasing distances along latitude.
        $offsets = [0.0002, 0.0005, 0.0010, 0.0015, 0.0020];
        foreach ($offsets as $i => $offset) {
            $features[] = [
                'lat' => 50.8503 + $offset,
                'lon' => 4.3517,
                'tags' => ['name' => 'POI '.($i + 1)],
            ];
        }

        $assistant = $this->buildAssistant($this->poiRepository($features));

        $response = $assistant->assist(
            message: 'Restaurant',
            position: new GeoPosition(50.8503, 4.3517),
            resolved: $this->resolved($llm),
        );

        $this->assertCount(3, $response->pois);
        $this->assertSame('POI 1', $response->pois[0]->name);
        $this->assertSame('POI 2', $response->pois[1]->name);
        $this->assertSame('POI 3', $response->pois[2]->name);
    }

    #[Test]
    public function emptyIndexResultsProduceFallbackNarrative(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('generate')->willReturnCallback(
            static fn (string $model, string $prompt, ?string $systemPrompt = null, array $options = []): array => [
                'response' => '{"category":"shelter","max_distance_m":3000}',
            ],
        );

        $assistant = $this->buildAssistant($this->poiRepository([]));

        $response = $assistant->assist(
            message: 'Un abri pour la pluie',
            position: new GeoPosition(50.8503, 4.3517),
            resolved: $this->resolved($llm),
        );

        $this->assertSame(PoiSuggestion::CATEGORY_SHELTER, $response->category);
        $this->assertSame([], $response->pois);
        $this->assertStringContainsString('rien trouvé', $response->narrative);
    }

    #[Test]
    public function logsAtCriticalWhenProviderUnreachableForNarrative(): void
    {
        // Intent classification succeeds (user message) but the narrative call
        // (JSON payload) hits an unreachable provider — it must log `critical` and
        // still serve the fallback narrative (#304).
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('generate')->willReturnCallback(
            static function (string $model, string $prompt): array {
                if (str_starts_with(trim($prompt), '{')) {
                    throw new AiUnavailableException('boom');
                }

                return ['response' => '{"category":"water","max_distance_m":3000}'];
            },
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical')
            ->with(self::stringContains('AI provider unavailable for narrative'));

        $poiRepository = $this->poiRepository([
            ['lat' => 50.8504, 'lon' => 4.3520, 'tags' => ['name' => 'Fontaine']],
        ]);

        $assistant = $this->buildAssistant($poiRepository, $logger);

        $response = $assistant->assist(
            message: "Je cherche un point d'eau",
            position: new GeoPosition(50.8503, 4.3517),
            resolved: $this->resolved($llm),
        );

        // Graceful degradation: POIs are still returned with a fallback narrative.
        self::assertSame(PoiSuggestion::CATEGORY_WATER, $response->category);
        self::assertNotEmpty($response->pois);
    }

    /**
     * @param list<array{lat: float, lon: float, tags: array<string, string>}> $features
     */
    private function poiRepository(array $features): InRidePoiRepositoryInterface
    {
        $repository = $this->createStub(InRidePoiRepositoryInterface::class);
        $repository->method('findNearby')->willReturn($features);

        return $repository;
    }

    private function buildAssistant(
        InRidePoiRepositoryInterface $poiRepository,
        ?LoggerInterface $logger = null,
    ): InRideAssistant {
        $distance = new HaversineDistance();

        return new InRideAssistant(
            intentDetector: new PoiIntentDetector($logger ?? new NullLogger()),
            poiRepository: $poiRepository,
            openingHoursParser: new OpeningHoursParser(),
            detourCalculator: new DetourCalculator($distance),
            deeplinkBuilder: new DeeplinkBuilder(),
            distance: $distance,
            promptLoader: new SystemPromptLoader($this->promptDir),
            logger: $logger ?? new NullLogger(),
        );
    }

    private function resolved(LlmClientInterface $llm): ResolvedLlmClient
    {
        return new ResolvedLlmClient($llm, AiProvider::ANTHROPIC);
    }
}
