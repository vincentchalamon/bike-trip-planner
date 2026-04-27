<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\ApiResource\TripModification;
use App\Message\AnalyzeTerrain;
use App\Message\CheckBikeShops;
use App\Message\CheckCalendar;
use App\Message\CheckCulturalPois;
use App\Message\FetchWeather;
use App\Message\RecalculateStages;
use App\Message\ScanAccommodations;
use App\Message\ScanEvents;
use App\Message\ScanPois;
use App\Service\ComputationDependencyResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComputationDependencyResolverTest extends TestCase
{
    private ComputationDependencyResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ComputationDependencyResolver();
    }

    #[Test]
    public function accommodationModificationTriggersRecalculateAndScanForAffectedStages(): void
    {
        $modification = new TripModification(stageIndex: 1, type: 'accommodation', label: 'Hébergement étape 2');
        $messages = $this->resolver->resolve(
            'trip-1',
            [$modification],
            [0, 1, 2],
            false,
            ['hotel', 'camp_site'],
            generation: 5,
        );

        $classes = $this->classesOf($messages);

        $this->assertContains(RecalculateStages::class, $classes);
        $this->assertContains(ScanAccommodations::class, $classes);

        // Must NOT trigger the full enrichment pipeline
        $this->assertNotContains(AnalyzeTerrain::class, $classes);
        $this->assertNotContains(FetchWeather::class, $classes);
        $this->assertNotContains(ScanPois::class, $classes);
    }

    #[Test]
    public function accommodationModificationIncludesNextStageInRecalculate(): void
    {
        $modification = new TripModification(stageIndex: 0, type: 'accommodation', label: 'test');
        $messages = $this->resolver->resolve('trip-1', [$modification], [0, 1, 2], false, [], generation: null);

        $recalc = $this->firstOf($messages, RecalculateStages::class);
        $this->assertInstanceOf(RecalculateStages::class, $recalc);
        // Stage 0 + next stage 1
        $this->assertContains(0, $recalc->affectedIndices);
        $this->assertContains(1, $recalc->affectedIndices);
    }

    #[Test]
    public function distanceModificationTriggersEnrichmentPipeline(): void
    {
        $modification = new TripModification(stageIndex: 1, type: 'distance', label: 'Distance étape 2');
        $messages = $this->resolver->resolve(
            'trip-1',
            [$modification],
            [0, 1, 2],
            false,
            ['hotel'],
            generation: 3,
        );

        $classes = $this->classesOf($messages);

        $this->assertContains(RecalculateStages::class, $classes);
        $this->assertContains(ScanAccommodations::class, $classes);
        $this->assertContains(ScanPois::class, $classes);
        $this->assertContains(AnalyzeTerrain::class, $classes);
        $this->assertContains(CheckBikeShops::class, $classes);
    }

    #[Test]
    public function distanceModificationWithDatesTriggersWeatherAndCalendar(): void
    {
        $modification = new TripModification(stageIndex: 0, type: 'distance', label: 'test');
        $messages = $this->resolver->resolve('trip-1', [$modification], [0, 1], true, [], generation: null);

        $classes = $this->classesOf($messages);
        $this->assertContains(FetchWeather::class, $classes);
        $this->assertContains(CheckCalendar::class, $classes);
    }

    #[Test]
    public function distanceModificationWithoutDatesDoesNotTriggerWeather(): void
    {
        $modification = new TripModification(stageIndex: 0, type: 'distance', label: 'test');
        $messages = $this->resolver->resolve('trip-1', [$modification], [0, 1], false, [], generation: null);

        $classes = $this->classesOf($messages);
        $this->assertNotContains(FetchWeather::class, $classes);
        $this->assertNotContains(CheckCalendar::class, $classes);
    }

    #[Test]
    public function datesModificationTriggersWeatherCalendarAndEvents(): void
    {
        $modification = new TripModification(stageIndex: null, type: 'dates', label: 'Dates');
        $messages = $this->resolver->resolve('trip-1', [$modification], [0, 1, 2], true, [], generation: null);

        $classes = $this->classesOf($messages);
        $this->assertContains(FetchWeather::class, $classes);
        $this->assertContains(CheckCalendar::class, $classes);
        $this->assertContains(ScanEvents::class, $classes);
        $this->assertContains(CheckCulturalPois::class, $classes);

        // Dates alone do NOT trigger route recalculation
        $this->assertNotContains(RecalculateStages::class, $classes);
    }

    #[Test]
    public function pacingModificationTriggersRecalculateForAllStages(): void
    {
        $modification = new TripModification(stageIndex: null, type: 'pacing', label: 'Pacing');
        $messages = $this->resolver->resolve('trip-1', [$modification], [0, 1, 2], false, [], generation: null);

        $recalc = $this->firstOf($messages, RecalculateStages::class);
        $this->assertInstanceOf(RecalculateStages::class, $recalc);
        $this->assertCount(3, $recalc->affectedIndices);
    }

    #[Test]
    public function pacingModificationWithDatesTriggersWeatherAndCalendar(): void
    {
        $modification = new TripModification(stageIndex: null, type: 'pacing', label: 'Pacing');
        $messages = $this->resolver->resolve('trip-1', [$modification], [0, 1, 2], true, [], generation: null);

        $classes = $this->classesOf($messages);
        $this->assertContains(RecalculateStages::class, $classes);
        $this->assertContains(FetchWeather::class, $classes);
        $this->assertContains(CheckCalendar::class, $classes);
    }

    #[Test]
    public function batchFusesDependenciesAcrossModifications(): void
    {
        $modifications = [
            new TripModification(stageIndex: 0, type: 'accommodation', label: 'acc 0'),
            new TripModification(stageIndex: 2, type: 'distance', label: 'dist 2'),
            new TripModification(stageIndex: null, type: 'dates', label: 'dates'),
        ];

        $messages = $this->resolver->resolve('trip-1', $modifications, [0, 1, 2], true, ['hotel'], generation: 1);

        $classes = $this->classesOf($messages);

        // All three modification types contribute their required handlers
        $this->assertContains(RecalculateStages::class, $classes);
        $this->assertContains(ScanAccommodations::class, $classes);
        $this->assertContains(ScanPois::class, $classes);
        $this->assertContains(AnalyzeTerrain::class, $classes);
        $this->assertContains(FetchWeather::class, $classes);
        $this->assertContains(CheckCalendar::class, $classes);
        $this->assertContains(ScanEvents::class, $classes);

        // Exactly one RecalculateStages message (deduplicated)
        $this->assertCount(1, array_filter($messages, static fn (object $m): bool => $m instanceof RecalculateStages));
    }

    #[Test]
    public function generationIsPropagatedToAllMessages(): void
    {
        $modification = new TripModification(stageIndex: 0, type: 'distance', label: 'test');
        $messages = $this->resolver->resolve('trip-1', [$modification], [0, 1], false, ['hotel'], generation: 7);

        foreach ($messages as $message) {
            if (property_exists($message, 'generation')) {
                /* @var object{generation: ?int} $message */
                $this->assertSame(7, $message->generation, \sprintf('Expected generation 7 for %s', $message::class));
            }
        }
    }

    #[Test]
    public function emptyModificationsListReturnsNoMessages(): void
    {
        $messages = $this->resolver->resolve('trip-1', [], [0, 1, 2], false, [], generation: null);
        $this->assertSame([], $messages);
    }

    // --- Helpers ---

    /**
     * @param list<object> $messages
     *
     * @return list<class-string>
     */
    private function classesOf(array $messages): array
    {
        return array_map(static fn (object $m): string => $m::class, $messages);
    }

    /**
     * @template T of object
     *
     * @param list<object>    $messages
     * @param class-string<T> $class
     */
    private function firstOf(array $messages, string $class): ?object
    {
        foreach ($messages as $message) {
            if ($message instanceof $class) {
                return $message;
            }
        }

        return null;
    }
}
