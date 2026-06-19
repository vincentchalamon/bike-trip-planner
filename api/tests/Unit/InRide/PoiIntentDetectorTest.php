<?php

declare(strict_types=1);

namespace App\Tests\Unit\InRide;

use App\InRide\PoiIntentDetector;
use App\InRide\PoiSuggestion;
use App\Llm\AiProvider;
use App\Llm\Exception\AiUnavailableException;
use App\Llm\LlmClientInterface;
use App\Llm\ResolvedLlmClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Covers the parse path of {@see PoiIntentDetector} — the only barrier between
 * a prompt-injected LLM response and a malformed {@see PoiIntent} flowing into
 * the in-ride pipeline. Each case is exercised through {@see detect()} with a
 * mocked LLM that returns the raw envelope the LLM would produce.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(PoiIntentDetector::class)]
final class PoiIntentDetectorTest extends TestCase
{
    #[Test]
    public function emptyMessageShortCircuitsToUnknownWithoutCallingTheLlm(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->expects(self::never())->method('generate');

        $intent = new PoiIntentDetector(new NullLogger())->detect('   ', $this->resolved($llm));

        self::assertTrue($intent->isUnknown());
    }

    #[Test]
    public function logsAtCriticalWhenProviderUnreachable(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('generate')->willThrowException(new AiUnavailableException('boom'));

        // AI configured but unreachable must be logged at `critical` for ops alerting (#304).
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical')->with(self::stringContains('AI provider unavailable'));

        $intent = new PoiIntentDetector($logger)->detect('Un café ?', $this->resolved($llm));

        self::assertTrue($intent->isUnknown());
    }

    #[Test]
    #[DataProvider('payloadProvider')]
    public function parsesEnvelopeFromMockedLlm(string $llmResponse, string $expectedCategory, int $expectedRadius, ?int $expectedOpenForMinutes): void
    {
        $intent = new PoiIntentDetector(new NullLogger())->detect('Tu connais un endroit ?', $this->resolvedFor($llmResponse));

        self::assertSame($expectedCategory, $intent->category);
        self::assertSame($expectedRadius, $intent->maxDistanceMeters);
        self::assertSame($expectedOpenForMinutes, $intent->openForMinutes);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: ?int}>
     */
    public static function payloadProvider(): iterable
    {
        // Happy path.
        yield 'pure JSON envelope' => [
            '{"category":"food","max_distance_m":3000}',
            PoiSuggestion::CATEGORY_FOOD,
            3000,
            null,
        ];

        // Code-fence stripping (Markdown triple-backtick wrappers).
        yield 'fenced JSON envelope' => [
            "```json\n{\"category\":\"water\",\"max_distance_m\":2500}\n```",
            PoiSuggestion::CATEGORY_WATER,
            2500,
            null,
        ];

        // JSON embedded in prose — the parser must extract the {...} substring.
        yield 'JSON embedded in prose' => [
            'Sure, here you go: {"category":"shelter","max_distance_m":4000} hope that helps!',
            PoiSuggestion::CATEGORY_SHELTER,
            4000,
            null,
        ];

        // Category whitelist — `cafe` is not in SUPPORTED_CATEGORIES so we fall back to unknown.
        yield 'unsupported category becomes unknown' => [
            '{"category":"cafe","max_distance_m":2000}',
            PoiSuggestion::CATEGORY_UNKNOWN,
            PoiIntentDetector::DEFAULT_RADIUS_METERS,
            null,
        ];

        // Radius clamping — below MIN clamps up.
        yield 'radius below MIN clamps to MIN' => [
            '{"category":"food","max_distance_m":500}',
            PoiSuggestion::CATEGORY_FOOD,
            PoiIntentDetector::MIN_RADIUS_METERS,
            null,
        ];

        // Radius clamping — above MAX clamps down.
        yield 'radius above MAX clamps to MAX' => [
            '{"category":"food","max_distance_m":50000}',
            PoiSuggestion::CATEGORY_FOOD,
            PoiIntentDetector::MAX_RADIUS_METERS,
            null,
        ];

        // Numeric coercion: float radius rounds.
        yield 'float radius rounds' => [
            '{"category":"food","max_distance_m":2750.6}',
            PoiSuggestion::CATEGORY_FOOD,
            2751,
            null,
        ];

        // Numeric coercion: digit-string radius is accepted.
        yield 'string-digit radius is parsed' => [
            '{"category":"food","max_distance_m":"3500"}',
            PoiSuggestion::CATEGORY_FOOD,
            3500,
            null,
        ];

        // opening_filter — int.
        yield 'opening_filter int forwards open_for_minutes' => [
            '{"category":"food","max_distance_m":3000,"opening_filter":{"open_for_minutes":45}}',
            PoiSuggestion::CATEGORY_FOOD,
            3000,
            45,
        ];

        // opening_filter — float (rounded).
        yield 'opening_filter float rounds open_for_minutes' => [
            '{"category":"food","max_distance_m":3000,"opening_filter":{"open_for_minutes":29.6}}',
            PoiSuggestion::CATEGORY_FOOD,
            3000,
            30,
        ];

        // opening_filter — string-digit.
        yield 'opening_filter string-digit parses open_for_minutes' => [
            '{"category":"food","max_distance_m":3000,"opening_filter":{"open_for_minutes":"60"}}',
            PoiSuggestion::CATEGORY_FOOD,
            3000,
            60,
        ];

        // opening_filter — non-positive value ignored.
        yield 'opening_filter zero is ignored' => [
            '{"category":"food","max_distance_m":3000,"opening_filter":{"open_for_minutes":0}}',
            PoiSuggestion::CATEGORY_FOOD,
            3000,
            null,
        ];
    }

    #[Test]
    public function malformedJsonFallsBackToUnknown(): void
    {
        $intent = new PoiIntentDetector(new NullLogger())->detect('Eau ?', $this->resolvedFor('{this is not json'));

        self::assertTrue($intent->isUnknown());
    }

    #[Test]
    public function payloadWithoutBracesFallsBackToUnknown(): void
    {
        $intent = new PoiIntentDetector(new NullLogger())->detect('Eau ?', $this->resolvedFor('No JSON here at all, just words.'));

        self::assertTrue($intent->isUnknown());
    }

    #[Test]
    public function jsonArrayInsteadOfObjectFallsBackToUnknown(): void
    {
        // The parser extracts the substring between the outer braces, so an
        // array payload still goes through json_decode but yields a non-array
        // (well, an array but not an object map) and is rejected at the category check.
        $intent = new PoiIntentDetector(new NullLogger())->detect('Eau ?', $this->resolvedFor('["food", 3000]'));

        self::assertTrue($intent->isUnknown());
    }

    private function resolved(LlmClientInterface $client): ResolvedLlmClient
    {
        return new ResolvedLlmClient($client, AiProvider::ANTHROPIC);
    }

    private function resolvedFor(string $llmResponse): ResolvedLlmClient
    {
        $llm = $this->createStub(LlmClientInterface::class);
        $llm->method('generate')->willReturn(['response' => $llmResponse]);

        return $this->resolved($llm);
    }
}
