<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSource;

use App\EventSource\EventSourceInterface;
use App\EventSource\EventSourceRegistry;
use App\Geo\HaversineDistance;
use App\Geo\NearbyNameDeduplicator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventSourceRegistryTest extends TestCase
{
    /**
     * Fixtures state only what a case is about; the rest of the source-row shape
     * defaults to a nearby, relevant, linked, unnamed event so a case can assert one
     * axis without spelling the others out.
     *
     * @param list<array<string, mixed>> $events
     */
    private function source(array $events): EventSourceInterface
    {
        $events = array_map(
            static fn (array $event): array => $event + [
                'name' => null,
                'category' => 'festival',
                'lat' => 48.0,
                'lon' => 2.0,
                'startDate' => '2026-07-10',
                'endDate' => '2026-07-14',
                'url' => 'https://event.example.com',
                'description' => null,
                'priceMin' => null,
                'source' => 'datatourisme',
            ],
            $events,
        );

        return new readonly class ($events) implements EventSourceInterface {
            /** @param list<array<string, mixed>> $events */
            public function __construct(private array $events)
            {
            }

            public function findActiveNear(float $lat, float $lon, int $radiusMeters, string $date): array
            {
                /** @var list<array{name: ?string, category: string, lat: float, lon: float, startDate: string, endDate: string, url: string, description: ?string, priceMin: ?float, source: string}> $events */
                $events = $this->events;

                return $events;
            }
        };
    }

    /**
     * @param list<EventSourceInterface> $sources
     */
    private function registry(array $sources): EventSourceRegistry
    {
        return new EventSourceRegistry($sources, new NearbyNameDeduplicator(new HaversineDistance()), new HaversineDistance());
    }

    #[Test]
    public function mergesEventsFromEverySource(): void
    {
        $a = $this->source([['name' => 'Festival A', 'lat' => 48.10, 'lon' => 2.10]]);
        $b = $this->source([['name' => 'Concert B', 'category' => 'concert', 'lat' => 48.20, 'lon' => 2.20, 'source' => 'openagenda']]);

        $result = $this->registry([$a, $b])->findAllActiveNear(48.0, 2.0, 20_000, '2026-07-10');

        self::assertCount(2, $result);
    }

    #[Test]
    public function collapsesSameNamedNearbyEventsPreferringDataTourisme(): void
    {
        $openagenda = $this->source([['name' => 'Fete de la Musique', 'lat' => 48.1000, 'lon' => 2.1000, 'source' => 'openagenda']]);
        $datatourisme = $this->source([['name' => 'Fete de la Musique', 'lat' => 48.1001, 'lon' => 2.1001, 'source' => 'datatourisme']]);

        $result = $this->registry([$openagenda, $datatourisme])->findAllActiveNear(48.0, 2.0, 20_000, '2026-07-10');

        self::assertCount(1, $result);
        self::assertSame('datatourisme', $result[0]['source']);
    }

    #[Test]
    public function dropsIrrelevantCategories(): void
    {
        // The generic `event` fallback and a young-audience category are noise; only
        // the whitelisted categories a rider plans around survive.
        $source = $this->source([
            ['name' => 'Grand Festival', 'category' => 'festival'],
            ['name' => 'Atelier enfants', 'category' => 'children'],
            ['name' => 'Evenement divers', 'category' => 'event'],
            ['name' => 'Expo', 'category' => 'exhibition'],
        ]);

        $result = $this->registry([$source])->findAllActiveNear(48.0, 2.0, 20_000, '2026-07-10');

        $names = array_map(static fn (array $e): ?string => $e['name'], $result);
        self::assertSame(['Grand Festival', 'Expo'], $names);
    }

    #[Test]
    public function excludesEventsWithoutLink(): void
    {
        $source = $this->source([
            ['name' => 'With Link', 'url' => 'https://event.example.com'],
            ['name' => 'No Link', 'url' => ''],
        ]);

        $result = $this->registry([$source])->findAllActiveNear(48.0, 2.0, 20_000, '2026-07-10');

        self::assertCount(1, $result);
        self::assertSame('With Link', $result[0]['name']);
    }

    #[Test]
    public function ranksByDistanceToTheEndPointAndExposesIt(): void
    {
        $source = $this->source([
            ['name' => 'Far', 'lat' => 48.30, 'lon' => 2.30],
            ['name' => 'Near', 'lat' => 48.001, 'lon' => 2.001],
            ['name' => 'Mid', 'lat' => 48.10, 'lon' => 2.10],
        ]);

        $result = $this->registry([$source])->findAllActiveNear(48.0, 2.0, 40_000, '2026-07-10');

        self::assertSame(['Near', 'Mid', 'Far'], array_map(static fn (array $e): ?string => $e['name'], $result));
        self::assertGreaterThan(0.0, $result[0]['distanceToEndPoint']);
        self::assertLessThan($result[1]['distanceToEndPoint'], $result[0]['distanceToEndPoint']);
    }

    #[Test]
    public function capsTheListToTwenty(): void
    {
        $events = [];
        for ($i = 0; $i < 30; ++$i) {
            $events[] = ['name' => 'Festival '.$i, 'lat' => 48.0 + $i / 1000, 'lon' => 2.0];
        }

        $result = $this->registry([$this->source($events)])->findAllActiveNear(48.0, 2.0, 40_000, '2026-07-10');

        self::assertCount(20, $result);
        // The nearest 20 are kept, so the farthest (index 29) is dropped.
        self::assertSame('Festival 0', $result[0]['name']);
        self::assertSame('Festival 19', $result[19]['name']);
    }

    #[Test]
    public function emptySourcesReturnEmptyArray(): void
    {
        self::assertSame([], $this->registry([])->findAllActiveNear(48.0, 2.0, 20_000, '2026-07-10'));
    }
}
