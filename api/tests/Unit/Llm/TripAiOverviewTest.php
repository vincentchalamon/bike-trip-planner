<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\Dto\TripAiOverview;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TripAiOverviewTest extends TestCase
{
    #[Test]
    public function constructorAssignsAllFields(): void
    {
        $overview = new TripAiOverview(
            narrative: 'Trip narrative.',
            patterns: ['Pattern A', 'Pattern B'],
            recommendations: ['Rec 1', 'Rec 2'],
            crossStageAlerts: ['Attention: zone aride J2-J3'],
            model: 'llama3.1:8b',
            promptVersion: 1,
            generatedAt: '2026-05-06T10:00:00+00:00',
        );

        self::assertSame('Trip narrative.', $overview->narrative);
        self::assertSame(['Pattern A', 'Pattern B'], $overview->patterns);
        self::assertSame(['Rec 1', 'Rec 2'], $overview->recommendations);
        self::assertSame(['Attention: zone aride J2-J3'], $overview->crossStageAlerts);
        self::assertSame('llama3.1:8b', $overview->model);
        self::assertSame(1, $overview->promptVersion);
        self::assertSame('2026-05-06T10:00:00+00:00', $overview->generatedAt);
    }

    #[Test]
    public function toArrayPreservesEveryField(): void
    {
        $overview = new TripAiOverview(
            narrative: 'N',
            patterns: ['P'],
            recommendations: ['R'],
            crossStageAlerts: ['A'],
            model: 'm',
            promptVersion: 3,
            generatedAt: 'g',
        );

        self::assertSame(
            [
                'narrative' => 'N',
                'patterns' => ['P'],
                'recommendations' => ['R'],
                'crossStageAlerts' => ['A'],
                'model' => 'm',
                'promptVersion' => 3,
                'generatedAt' => 'g',
            ],
            $overview->toArray(),
        );
    }

    #[Test]
    public function fromArrayRebuildsTheDto(): void
    {
        $overview = TripAiOverview::fromArray([
            'narrative' => 'N',
            'patterns' => ['P'],
            'recommendations' => ['R'],
            'crossStageAlerts' => ['A'],
            'model' => 'm',
            'promptVersion' => 2,
            'generatedAt' => '2026-05-06T10:00:00+00:00',
        ]);

        self::assertSame('N', $overview->narrative);
        self::assertSame(['P'], $overview->patterns);
        self::assertSame(['R'], $overview->recommendations);
        self::assertSame(['A'], $overview->crossStageAlerts);
        self::assertSame('m', $overview->model);
        self::assertSame(2, $overview->promptVersion);
        self::assertSame('2026-05-06T10:00:00+00:00', $overview->generatedAt);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingFields(): void
    {
        $overview = TripAiOverview::fromArray([]);

        self::assertSame('', $overview->narrative);
        self::assertSame([], $overview->patterns);
        self::assertSame([], $overview->recommendations);
        self::assertSame([], $overview->crossStageAlerts);
        self::assertSame('', $overview->model);
        self::assertSame(1, $overview->promptVersion);
        self::assertSame('', $overview->generatedAt);
    }

    #[Test]
    public function fromArrayRoundTripsThroughToArray(): void
    {
        $original = new TripAiOverview(
            narrative: 'Trip de 3 jours.',
            patterns: ['Zones sans eau dès J2', 'Surface bascule vers le gravel'],
            recommendations: ['Insérer une pause à mi-J2'],
            crossStageAlerts: ['Attention: chaleur 28°C en J2'],
            model: 'llama3.1:8b',
            promptVersion: 1,
            generatedAt: '2026-05-06T12:00:00+00:00',
        );

        $rebuilt = TripAiOverview::fromArray($original->toArray());

        self::assertEquals($original, $rebuilt);
    }
}
