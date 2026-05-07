<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Llm\Exception\OllamaUnavailableException;
use App\Llm\LlmClientInterface;
use App\Llm\SystemPromptLoader;
use App\Message\AnalyzeTripOverviewWithLlmMessage;
use App\MessageHandler\AnalyzeTripOverviewWithLlmHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class AnalyzeTripOverviewWithLlmHandlerTest extends TestCase
{
    private const string TRIP_ID = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    private string $tmpPromptDir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->tmpPromptDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'trip-overview-prompt-'.bin2hex(random_bytes(4));

        if (!mkdir($this->tmpPromptDir, 0o755, true) && !is_dir($this->tmpPromptDir)) {
            throw new \RuntimeException('Failed to create tmp prompt dir.');
        }

        // Minimal trip-overview prompt with placeholders the handler always provides.
        file_put_contents(
            $this->tmpPromptDir.\DIRECTORY_SEPARATOR.AnalyzeTripOverviewWithLlmHandler::PROMPT_NAME.'.txt',
            "Region: {{region}}\nProfile: {{rider_profile}}\nLanguage: {{language}}\nDate: {{date}}\n",
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (!is_dir($this->tmpPromptDir)) {
            return;
        }

        foreach (glob($this->tmpPromptDir.\DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->tmpPromptDir);
    }

    // -------------------------------------------------------------------------
    // Skip-paths
    // -------------------------------------------------------------------------

    #[Test]
    public function skipsSilentlyWhenLlmIsDisabled(): void
    {
        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(false);

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->expects(self::never())->method('getStages');
        $repo->expects(self::never())->method('updateTripAiOverview');

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));
    }

    #[Test]
    public function skipsWhenStagesAreMissing(): void
    {
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn(null);
        $repo->expects(self::never())->method('updateTripAiOverview');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->expects(self::never())->method('generate');

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));
    }

    #[Test]
    public function skipsWhenStagesArrayIsEmpty(): void
    {
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([]);
        $repo->expects(self::never())->method('updateTripAiOverview');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->expects(self::never())->method('generate');

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));
    }

    #[Test]
    public function skipsWhenNoStageHasPass1Analysis(): void
    {
        // 3 stages but none has aiAnalysis populated.
        $stages = [
            $this->makeStage(dayNumber: 1),
            $this->makeStage(dayNumber: 2),
            $this->makeStage(dayNumber: 3),
        ];

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn($stages);
        $repo->expects(self::never())->method('updateTripAiOverview');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->expects(self::never())->method('generate');

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));
    }

    #[Test]
    public function skipsRestDaysWhenBuildingInputs(): void
    {
        $stage1 = $this->makeStage(dayNumber: 1);
        $stage1->aiAnalysis = $this->makeAiAnalysis('Étape 1');

        $restDay = $this->makeStage(dayNumber: 2, isRestDay: true);
        // Even if a rest day has aiAnalysis (it shouldn't), it must be ignored.
        $restDay->aiAnalysis = $this->makeAiAnalysis('Should not be used');

        $stage3 = $this->makeStage(dayNumber: 3);
        $stage3->aiAnalysis = $this->makeAiAnalysis('Étape 3');

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage1, $restDay, $stage3]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::once())->method('updateTripAiOverview');

        $captured = [];
        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')
            ->willReturnCallback(static function (string $model, string $prompt, ?string $systemPrompt, array $options) use (&$captured): array {
                $captured['prompt'] = $prompt;

                return ['response' => "## Vue d'ensemble\nOK\n", 'done' => true];
            });

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));

        self::assertIsString($captured['prompt']);
        $payload = json_decode($captured['prompt'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('stages', $payload);
        self::assertIsArray($payload['stages']);
        self::assertCount(2, $payload['stages']);
        self::assertIsArray($payload['stages'][0]);
        self::assertSame(1, $payload['stages'][0]['stage_number']);
        self::assertIsArray($payload['stages'][1]);
        self::assertSame(3, $payload['stages'][1]['stage_number']);
    }

    #[Test]
    public function skipsAnalysisWhenOllamaUnreachable(): void
    {
        $stage = $this->makeStage(dayNumber: 1);
        $stage->aiAnalysis = $this->makeAiAnalysis('A');

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::never())->method('updateTripAiOverview');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willThrowException(new OllamaUnavailableException('boom'));

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));
    }

    #[Test]
    public function skipsPersistenceWhenLlmReturnsNull(): void
    {
        $stage = $this->makeStage(dayNumber: 1);
        $stage->aiAnalysis = $this->makeAiAnalysis('A');

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::never())->method('updateTripAiOverview');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willReturn(null);

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));
    }

    #[Test]
    public function skipsPersistenceWhenLlmReturnsEmptyText(): void
    {
        $stage = $this->makeStage(dayNumber: 1);
        $stage->aiAnalysis = $this->makeAiAnalysis('A');

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::never())->method('updateTripAiOverview');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willReturn(['response' => '   ', 'done' => true]);

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));
    }

    // -------------------------------------------------------------------------
    // Happy paths
    // -------------------------------------------------------------------------

    #[Test]
    public function buildsPayloadAndCallsOllamaWithSystemPrompt(): void
    {
        $stage1 = $this->makeStage(dayNumber: 1, distance: 65.0, elevation: 600.0);
        $stage1->aiAnalysis = $this->makeAiAnalysis('Étape d\'approche roulante.');

        $stage2 = $this->makeStage(dayNumber: 2, distance: 92.0, elevation: 1240.0);
        $stage2->aiAnalysis = $this->makeAiAnalysis('Étape exigeante.');

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage1, $stage2]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::once())->method('updateTripAiOverview');

        $captured = [];
        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->expects(self::once())
            ->method('generate')
            ->willReturnCallback(static function (string $model, string $prompt, ?string $systemPrompt, array $options) use (&$captured): array {
                $captured['model'] = $model;
                $captured['prompt'] = $prompt;
                $captured['systemPrompt'] = $systemPrompt;
                $captured['options'] = $options;

                return [
                    'response' => "## Vue d'ensemble\nGlobal narrative.\n\n## Charge et fatigue cumulative\nOK.\n\n## Patterns transversaux\n- Pattern A\n\n## Recommandations globales\n- Reco 1\n",
                    'done' => true,
                ];
            });

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));

        self::assertSame(AnalyzeTripOverviewWithLlmHandler::DEFAULT_MODEL, $captured['model']);

        // The user prompt MUST be a valid JSON dump of {rider_profile, stages}.
        self::assertIsString($captured['prompt']);
        $decoded = json_decode($captured['prompt'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('rider_profile', $decoded);
        self::assertArrayHasKey('stages', $decoded);
        self::assertIsArray($decoded['stages']);
        self::assertCount(2, $decoded['stages']);
        self::assertIsArray($decoded['stages'][0]);
        self::assertSame(1, $decoded['stages'][0]['stage_number']);
        self::assertEqualsWithDelta(65.0, $decoded['stages'][0]['distance_km'], 0.01);
        self::assertSame(600, $decoded['stages'][0]['elevation_gain_m']);
        self::assertStringContainsString('Étape d\'approche', $decoded['stages'][0]['summary']);

        // System prompt must have placeholders substituted (no remaining {{...}}).
        self::assertIsString($captured['systemPrompt']);
        self::assertStringNotContainsString('{{', $captured['systemPrompt']);

        // Format must be disabled (markdown response, not JSON-mode).
        self::assertIsArray($captured['options']);
        self::assertSame('', $captured['options']['format']);
        self::assertSame(AnalyzeTripOverviewWithLlmHandler::OVERVIEW_NUM_CTX, $captured['options']['num_ctx']);
    }

    #[Test]
    public function persistsParsedSectionsAsTripOverview(): void
    {
        $stage = $this->makeStage(dayNumber: 1);
        $stage->aiAnalysis = $this->makeAiAnalysis('A');

        $captured = [];
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::once())
            ->method('updateTripAiOverview')
            ->willReturnCallback(static function (string $tripId, ?array $overview) use (&$captured): void {
                $captured = ['tripId' => $tripId, 'overview' => $overview];
            });

        $markdown = <<<'MD'
            ## Vue d'ensemble
            Trip de 3 jours sur 235 km cumulés.

            ## Charge et fatigue cumulative
            La J2 est clairement la plus dure.

            ## Patterns transversaux
            - Zones sans eau dès J2 et persistant en J3.
            - Surface bascule progressivement vers le gravel.
            - Attention: arrivées tardives en J3 — risque de couchant.

            ## Recommandations globales
            - Insérer une pause à mi-J2.
            - Charger 3 L d'eau au départ de J2 et J3.
            MD;

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willReturn(['response' => $markdown, 'done' => true]);

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));

        self::assertSame(self::TRIP_ID, $captured['tripId']);

        $overview = $captured['overview'];
        self::assertIsArray($overview);
        self::assertStringContainsString('Trip de 3 jours', $overview['narrative']);
        self::assertStringContainsString('La J2 est clairement la plus dure', $overview['narrative']);
        self::assertCount(3, $overview['patterns']);
        self::assertSame('Zones sans eau dès J2 et persistant en J3.', $overview['patterns'][0]);
        self::assertCount(2, $overview['recommendations']);
        self::assertSame('Insérer une pause à mi-J2.', $overview['recommendations'][0]);
        // The third pattern triggers cross-stage alert via "Attention" + "risque" keyword.
        self::assertCount(1, $overview['crossStageAlerts']);
        self::assertStringContainsString('Attention', $overview['crossStageAlerts'][0]);
        self::assertSame(AnalyzeTripOverviewWithLlmHandler::DEFAULT_MODEL, $overview['model']);
        self::assertSame(AnalyzeTripOverviewWithLlmHandler::PROMPT_VERSION, $overview['promptVersion']);
        self::assertNotSame('', $overview['generatedAt']);
    }

    #[Test]
    public function fallsBackToFullTextWhenSectionsAreMissing(): void
    {
        $stage = $this->makeStage(dayNumber: 1);
        $stage->aiAnalysis = $this->makeAiAnalysis('A');

        $captured = null;
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::once())
            ->method('updateTripAiOverview')
            ->willReturnCallback(static function (string $tripId, ?array $overview) use (&$captured): void {
                $captured = $overview;
            });

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willReturn([
            'response' => 'Just a plain text answer with no markdown headings at all.',
            'done' => true,
        ]);

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));

        self::assertIsArray($captured);
        self::assertSame('Just a plain text answer with no markdown headings at all.', $captured['narrative']);
        self::assertSame([], $captured['patterns']);
        self::assertSame([], $captured['recommendations']);
        self::assertSame([], $captured['crossStageAlerts']);
    }

    #[Test]
    public function readsTextFromChatStyleResponse(): void
    {
        $stage = $this->makeStage(dayNumber: 1);
        $stage->aiAnalysis = $this->makeAiAnalysis('A');

        $captured = null;
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::once())
            ->method('updateTripAiOverview')
            ->willReturnCallback(static function (string $tripId, ?array $overview) use (&$captured): void {
                $captured = $overview;
            });

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willReturn([
            'message' => [
                'role' => 'assistant',
                'content' => "## Vue d'ensemble\nText.\n\n## Patterns transversaux\n- A\n\n## Recommandations globales\n- B",
            ],
            'done' => true,
        ]);

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));

        self::assertIsArray($captured);
        self::assertSame('Text.', $captured['narrative']);
        self::assertSame(['A'], $captured['patterns']);
        self::assertSame(['B'], $captured['recommendations']);
    }

    #[Test]
    public function operatesWithSubsetOfStagesWhenSomePass1Failed(): void
    {
        // Three stages, only the middle one has a pass-1 analysis (others failed).
        $stage1 = $this->makeStage(dayNumber: 1);
        $stage2 = $this->makeStage(dayNumber: 2);
        $stage2->aiAnalysis = $this->makeAiAnalysis('Étape exigeante.');

        $stage3 = $this->makeStage(dayNumber: 3);

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage1, $stage2, $stage3]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::once())->method('updateTripAiOverview');

        $captured = [];
        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')
            ->willReturnCallback(static function (string $model, string $prompt, ?string $systemPrompt, array $options) use (&$captured): array {
                $captured['prompt'] = $prompt;

                return ['response' => "## Vue d'ensemble\nOK\n", 'done' => true];
            });

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeTripOverviewWithLlmMessage(self::TRIP_ID));

        self::assertIsString($captured['prompt']);
        $payload = json_decode($captured['prompt'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('stages', $payload);
        self::assertIsArray($payload['stages']);
        self::assertCount(1, $payload['stages']);
        self::assertIsArray($payload['stages'][0]);
        self::assertSame(2, $payload['stages'][0]['stage_number']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param TripRequestRepositoryInterface&MockObject $repo
     * @param LlmClientInterface&MockObject             $llmClient
     */
    private function makeHandler(
        TripRequestRepositoryInterface $repo,
        LlmClientInterface $llmClient,
    ): AnalyzeTripOverviewWithLlmHandler {
        return new AnalyzeTripOverviewWithLlmHandler(
            tripStateManager: $repo,
            llmClient: $llmClient,
            promptLoader: new SystemPromptLoader($this->tmpPromptDir),
            logger: new NullLogger(),
        );
    }

    private function makeStage(int $dayNumber, bool $isRestDay = false, float $distance = 60.0, float $elevation = 500.0): Stage
    {
        return new Stage(
            tripId: self::TRIP_ID,
            dayNumber: $dayNumber,
            distance: $distance,
            elevation: $elevation,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
            isRestDay: $isRestDay,
        );
    }

    /**
     * @return array{narrative: string, insights: list<string>, suggestions: list<string>, model: string, promptVersion: int, generatedAt: string}
     */
    private function makeAiAnalysis(string $narrative): array
    {
        return [
            'narrative' => $narrative,
            'insights' => ['Some insight'],
            'suggestions' => ['Some suggestion'],
            'model' => 'llama3.1:8b',
            'promptVersion' => 1,
            'generatedAt' => '2026-05-06T10:00:00+00:00',
        ];
    }

    private function makeTripRequest(): TripRequest
    {
        $request = new TripRequest();
        $request->locale = 'fr';
        $request->ebikeMode = false;
        $request->startDate = new \DateTimeImmutable('2026-06-01');

        return $request;
    }
}
