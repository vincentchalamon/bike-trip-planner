<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\Llm\Exception\OllamaUnavailableException;
use App\Llm\LlmClientInterface;
use App\Llm\StageAnalysisSummaryBuilder;
use App\Llm\SystemPromptLoader;
use App\Message\AnalyzeStageWithLlmMessage;
use App\MessageHandler\AnalyzeStageWithLlmHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AnalyzeStageWithLlmHandlerTest extends TestCase
{
    private const string TRIP_ID = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    private string $tmpPromptDir = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->tmpPromptDir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'stage-analysis-prompt-'.bin2hex(random_bytes(4));

        if (!mkdir($this->tmpPromptDir, 0o755, true) && !is_dir($this->tmpPromptDir)) {
            throw new \RuntimeException('Failed to create tmp prompt dir.');
        }

        // Minimal stage-analysis prompt with placeholders the handler always provides.
        file_put_contents(
            $this->tmpPromptDir.\DIRECTORY_SEPARATOR.AnalyzeStageWithLlmHandler::PROMPT_NAME.'.txt',
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
        $repo->expects(self::never())->method('updateStageAiAnalysis');

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeStageWithLlmMessage(self::TRIP_ID, 1));
    }

    #[Test]
    public function skipsWhenStagesAreMissing(): void
    {
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn(null);
        $repo->expects(self::never())->method('updateStageAiAnalysis');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        // The Ollama client must NOT be called when there are no stages.
        $llmClient->expects(self::never())->method('generate');

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeStageWithLlmMessage(self::TRIP_ID, 1));
    }

    #[Test]
    public function skipsWhenStageDayNumberDoesNotMatch(): void
    {
        $stage = $this->makeStage(dayNumber: 1);
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->expects(self::never())->method('updateStageAiAnalysis');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        // Day number 99 — no match.
        $handler(new AnalyzeStageWithLlmMessage(self::TRIP_ID, 99));
    }

    #[Test]
    public function skipsRestDayStages(): void
    {
        $restStage = $this->makeStage(dayNumber: 2, isRestDay: true);

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$restStage]);
        $repo->expects(self::never())->method('updateStageAiAnalysis');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->expects(self::never())->method('generate');

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeStageWithLlmMessage(self::TRIP_ID, 2));
    }

    #[Test]
    public function skipsAnalysisWhenOllamaUnreachable(): void
    {
        $stage = $this->makeStage(dayNumber: 1);

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->expects(self::never())->method('updateStageAiAnalysis');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willThrowException(new OllamaUnavailableException('boom'));

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeStageWithLlmMessage(self::TRIP_ID, 1));
    }

    #[Test]
    public function skipsPersistenceWhenLlmReturnsNull(): void
    {
        $stage = $this->makeStage(dayNumber: 1);

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->expects(self::never())->method('updateStageAiAnalysis');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willReturn(null);

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeStageWithLlmMessage(self::TRIP_ID, 1));
    }

    #[Test]
    public function skipsPersistenceWhenLlmReturnsEmptyText(): void
    {
        $stage = $this->makeStage(dayNumber: 1);

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->expects(self::never())->method('updateStageAiAnalysis');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willReturn(['response' => '   ', 'done' => true]);

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeStageWithLlmMessage(self::TRIP_ID, 1));
    }

    // -------------------------------------------------------------------------
    // Happy paths
    // -------------------------------------------------------------------------

    #[Test]
    public function buildsCompactJsonSummaryAndCallsOllamaWithSystemPrompt(): void
    {
        $stage = $this->makeStage(dayNumber: 3);

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::once())->method('updateStageAiAnalysis');

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);

        $captured = [];
        $llmClient->expects(self::once())
            ->method('generate')
            ->willReturnCallback(static function (string $model, string $prompt, ?string $systemPrompt, array $options) use (&$captured): array {
                $captured['model'] = $model;
                $captured['prompt'] = $prompt;
                $captured['systemPrompt'] = $systemPrompt;
                $captured['options'] = $options;

                return [
                    'response' => "## Synthèse\nA short narrative.\n\n## Insights\n- First insight\n\n## Recommandations\n- Drink more water\n",
                    'done' => true,
                ];
            });

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeStageWithLlmMessage(self::TRIP_ID, 3));

        self::assertSame(AnalyzeStageWithLlmHandler::DEFAULT_MODEL, $captured['model']);

        // The user prompt MUST be a valid compact JSON dump of the stage summary.
        self::assertIsString($captured['prompt']);
        $decoded = json_decode($captured['prompt'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(3, $decoded['stage_number']);

        // System prompt must have placeholders substituted (no remaining {{...}}).
        self::assertIsString($captured['systemPrompt']);
        self::assertStringNotContainsString('{{', $captured['systemPrompt']);

        // Format must be disabled (markdown response, not JSON-mode).
        self::assertIsArray($captured['options']);
        self::assertSame('', $captured['options']['format']);
    }

    #[Test]
    public function persistsParsedNarrativeInsightsAndSuggestions(): void
    {
        $stage = $this->makeStage(dayNumber: 2);

        $captured = [];

        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::once())
            ->method('updateStageAiAnalysis')
            ->willReturnCallback(static function (string $tripId, int $dayNumber, ?array $analysis) use (&$captured): void {
                $captured = ['tripId' => $tripId, 'dayNumber' => $dayNumber, 'analysis' => $analysis];
            });

        $markdown = <<<'MD'
            ## Synthèse
            Étape exigeante de 92 km.

            ## Insights
            - Long segment sans eau de 45 km.
            - Vent de face après le col.

            ## Recommandations
            - Faire le plein d'eau à Florac.
            - Partir avant 7h30.
            MD;

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willReturn(['response' => $markdown, 'done' => true]);

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeStageWithLlmMessage(self::TRIP_ID, 2));

        self::assertSame(self::TRIP_ID, $captured['tripId']);
        self::assertSame(2, $captured['dayNumber']);

        $analysis = $captured['analysis'];
        self::assertIsArray($analysis);
        self::assertStringContainsString('Étape exigeante de 92 km', $analysis['narrative']);
        self::assertCount(2, $analysis['insights']);
        self::assertSame('Long segment sans eau de 45 km.', $analysis['insights'][0]);
        self::assertCount(2, $analysis['suggestions']);
        self::assertSame("Faire le plein d'eau à Florac.", $analysis['suggestions'][0]);
        self::assertSame(AnalyzeStageWithLlmHandler::DEFAULT_MODEL, $analysis['model']);
        self::assertSame(AnalyzeStageWithLlmHandler::PROMPT_VERSION, $analysis['promptVersion']);
        self::assertNotSame('', $analysis['generatedAt']);
    }

    #[Test]
    public function fallsBackToFullTextWhenSectionsAreMissing(): void
    {
        $stage = $this->makeStage(dayNumber: 1);

        $captured = [];
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::once())
            ->method('updateStageAiAnalysis')
            ->willReturnCallback(static function (string $tripId, int $dayNumber, ?array $analysis) use (&$captured): void {
                $captured = $analysis;
            });

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willReturn([
            'response' => 'Just a plain text answer with no markdown headings at all.',
            'done' => true,
        ]);

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeStageWithLlmMessage(self::TRIP_ID, 1));

        self::assertIsArray($captured);
        self::assertSame('Just a plain text answer with no markdown headings at all.', $captured['narrative']);
        self::assertSame([], $captured['insights']);
        self::assertSame([], $captured['suggestions']);
    }

    #[Test]
    public function readsTextFromChatStyleResponse(): void
    {
        $stage = $this->makeStage(dayNumber: 4);

        $captured = null;
        $repo = $this->createMock(TripRequestRepositoryInterface::class);
        $repo->method('getStages')->willReturn([$stage]);
        $repo->method('getRequest')->willReturn($this->makeTripRequest());
        $repo->expects(self::once())
            ->method('updateStageAiAnalysis')
            ->willReturnCallback(static function (string $tripId, int $dayNumber, ?array $analysis) use (&$captured): void {
                $captured = $analysis;
            });

        $llmClient = $this->createMock(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('generate')->willReturn([
            'message' => ['role' => 'assistant', 'content' => "## Synthèse\nText.\n\n## Insights\n- A\n\n## Recommandations\n- B"],
            'done' => true,
        ]);

        $handler = $this->makeHandler(repo: $repo, llmClient: $llmClient);
        $handler(new AnalyzeStageWithLlmMessage(self::TRIP_ID, 4));

        self::assertIsArray($captured);
        self::assertSame('Text.', $captured['narrative']);
        self::assertSame(['A'], $captured['insights']);
        self::assertSame(['B'], $captured['suggestions']);
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
    ): AnalyzeStageWithLlmHandler {
        return new AnalyzeStageWithLlmHandler(
            tripStateManager: $repo,
            llmClient: $llmClient,
            promptLoader: new SystemPromptLoader($this->tmpPromptDir),
            summaryBuilder: new StageAnalysisSummaryBuilder(),
            logger: new NullLogger(),
        );
    }

    private function makeStage(int $dayNumber, bool $isRestDay = false): Stage
    {
        return new Stage(
            tripId: self::TRIP_ID,
            dayNumber: $dayNumber,
            distance: 60.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
            isRestDay: $isRestDay,
        );
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
