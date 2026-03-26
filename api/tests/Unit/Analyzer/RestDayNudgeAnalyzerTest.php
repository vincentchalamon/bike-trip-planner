<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use Override;
use App\Analyzer\Rules\RestDayNudgeAnalyzer;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RestDayNudgeAnalyzerTest extends TestCase
{
    private TranslatorInterface $translator;

    #[Override]
    protected function setUp(): void
    {
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.': '.json_encode($parameters),
        );
    }

    #[Test]
    public function noAlertWithoutContext(): void
    {
        $analyzer = new RestDayNudgeAnalyzer($this->translator);
        $stage = $this->createStage(3, false);

        $alerts = $analyzer->analyze($stage, []);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noAlertBelowThreshold(): void
    {
        $analyzer = new RestDayNudgeAnalyzer($this->translator, 3);
        $stages = [
            $this->createStage(1, false),
            $this->createStage(2, false),
        ];

        $alerts = $analyzer->analyze($stages[1], ['allStages' => $stages]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function nudgeOnNthConsecutiveDay(): void
    {
        $analyzer = new RestDayNudgeAnalyzer($this->translator, 3);
        $stages = [
            $this->createStage(1, false),
            $this->createStage(2, false),
            $this->createStage(3, false),
        ];

        $alerts = $analyzer->analyze($stages[2], ['allStages' => $stages]);

        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::NUDGE, $alerts[0]->type);
    }

    #[Test]
    public function noNudgeOnDayBeforeThreshold(): void
    {
        $analyzer = new RestDayNudgeAnalyzer($this->translator, 3);
        $stages = [
            $this->createStage(1, false),
            $this->createStage(2, false),
            $this->createStage(3, false),
        ];

        $alerts = $analyzer->analyze($stages[1], ['allStages' => $stages]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function noNudgeAfterRestDay(): void
    {
        $analyzer = new RestDayNudgeAnalyzer($this->translator, 3);
        $stages = [
            $this->createStage(1, false),
            $this->createStage(2, false),
            $this->createStage(3, true),  // rest day
            $this->createStage(4, false),
            $this->createStage(5, false),
            $this->createStage(6, false),
        ];

        // Day 4, 5, 6 are the first 3 consecutive after the rest: nudge on 6
        $alerts = $analyzer->analyze($stages[3], ['allStages' => $stages]);
        $this->assertSame([], $alerts);

        $alerts = $analyzer->analyze($stages[4], ['allStages' => $stages]);
        $this->assertSame([], $alerts);

        $alerts = $analyzer->analyze($stages[5], ['allStages' => $stages]);
        $this->assertCount(1, $alerts);
        $this->assertSame(AlertType::NUDGE, $alerts[0]->type);
    }

    #[Test]
    public function noNudgeOnRestDayItself(): void
    {
        $analyzer = new RestDayNudgeAnalyzer($this->translator, 3);
        $stages = [
            $this->createStage(1, false),
            $this->createStage(2, false),
            $this->createStage(3, false),
            $this->createStage(4, true),  // rest day
        ];

        $alerts = $analyzer->analyze($stages[3], ['allStages' => $stages]);

        $this->assertSame([], $alerts);
    }

    #[Test]
    public function nudgeEmittedEveryNthDay(): void
    {
        $analyzer = new RestDayNudgeAnalyzer($this->translator, 3);
        $stages = array_map(
            fn (int $n): Stage => $this->createStage($n, false),
            range(1, 6),
        );

        $alertsDay3 = $analyzer->analyze($stages[2], ['allStages' => $stages]);
        $alertsDay4 = $analyzer->analyze($stages[3], ['allStages' => $stages]);
        $alertsDay6 = $analyzer->analyze($stages[5], ['allStages' => $stages]);

        $this->assertCount(1, $alertsDay3);
        $this->assertStringContainsString('3', $alertsDay3[0]->message); // consecutive count = 3
        $this->assertSame([], $alertsDay4);
        $this->assertCount(1, $alertsDay6);
        $this->assertStringContainsString('6', $alertsDay6[0]->message); // consecutive count = 6, not threshold 3
    }

    #[Test]
    public function usesLocaleFromContext(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())->method('trans')->with(
            'alert.rest_day.nudge',
            $this->anything(),
            'alerts',
            'fr',
        )->willReturn('Repos suggéré');

        $analyzer = new RestDayNudgeAnalyzer($translator, 3);
        $stages = [
            $this->createStage(1, false),
            $this->createStage(2, false),
            $this->createStage(3, false),
        ];

        $alerts = $analyzer->analyze($stages[2], ['allStages' => $stages, 'locale' => 'fr']);

        $this->assertCount(1, $alerts);
    }

    #[Test]
    public function priority(): void
    {
        $this->assertSame(100, RestDayNudgeAnalyzer::getPriority());
    }

    private function createStage(int $dayNumber, bool $isRestDay): Stage
    {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: $dayNumber,
            distance: $isRestDay ? 0.0 : 80.0,
            elevation: 0.0,
            startPoint: new Coordinate(45.0, 5.0),
            endPoint: new Coordinate(45.5, 5.5),
            isRestDay: $isRestDay,
        );
    }
}
