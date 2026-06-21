<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckBorderCrossing;
use App\MessageHandler\CheckBorderCrossingHandler;
use App\Osm\AdminBoundaryRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\TripCompletionGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckBorderCrossingHandlerTest extends TestCase
{
    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        AdminBoundaryRepositoryInterface $adminBoundaryRepository,
    ): CheckBorderCrossingHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => match ($id) {
                'alert.border_crossing.nudge' => \sprintf('You are entering %s.', $params['%country%']),
                default => $id,
            },
        );

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $messageBus = $this->createStub(MessageBusInterface::class);

        $handler = new CheckBorderCrossingHandler(
            $computationTracker,
            $publisher,
            $generationTracker,
            new NullLogger(),
            $tripStateManager,
            $adminBoundaryRepository,
            $translator,
            $messageBus,
        );
        $handler->setCompletionGate(new TripCompletionGate($computationTracker, $publisher, $messageBus));

        return $handler;
    }

    /**
     * Resolves the checkpoint countries in call order: start of stage 0, then the
     * end of each stage. A null entry models a point outside every stored boundary.
     *
     * @param list<string|null> $countriesInOrder
     */
    private function adminBoundaryRepository(array $countriesInOrder): AdminBoundaryRepositoryInterface
    {
        $repository = $this->createStub(AdminBoundaryRepositoryInterface::class);
        $index = 0;
        $repository->method('findCountryAt')->willReturnCallback(
            static function () use ($countriesInOrder, &$index): ?string {
                return $countriesInOrder[$index++] ?? null;
            },
        );

        return $repository;
    }

    /** @param list<Stage>|null $stages */
    private function createTripStateManager(?array $stages, string $locale = 'en'): TripRequestRepositoryInterface
    {
        $manager = $this->createStub(TripRequestRepositoryInterface::class);
        $manager->method('getStages')->willReturn($stages);
        $manager->method('getLocale')->willReturn($locale);

        return $manager;
    }

    #[Test]
    public function borderCrossingDetectedBetweenFranceAndBelgium(): void
    {
        // Stage 1: starts in France (Lille), ends in Belgium (Courtrai)
        $stages = [
            new Stage(
                tripId: 'trip-1',
                dayNumber: 1,
                distance: 80.0,
                elevation: 200.0,
                startPoint: new Coordinate(50.6292, 3.0573),  // Lille, France
                endPoint: new Coordinate(50.8279, 3.2646),    // Courtrai, Belgium
            ),
            new Stage(
                tripId: 'trip-1',
                dayNumber: 2,
                distance: 60.0,
                elevation: 150.0,
                startPoint: new Coordinate(50.8279, 3.2646),  // Courtrai, Belgium
                endPoint: new Coordinate(50.7500, 3.8833),    // Renaix, Belgium
            ),
        ];

        $tripStateManager = $this->createTripStateManager($stages);

        // Checkpoints: Lille (France), Courtrai (Belgium), Renaix (Belgium)
        $adminBoundaryRepository = $this->adminBoundaryRepository(['France', 'Belgium', 'Belgium']);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::BORDER_CROSSING_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 1 === \count($alerts)
                        && 'nudge' === $alerts[0]['type']
                        && str_contains((string) $alerts[0]['message'], 'Belgium')
                        && 'navigate' === $alerts[0]['action']
                        && 0 === $alerts[0]['stageIndex'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $adminBoundaryRepository);
        $handler(new CheckBorderCrossing('trip-1'));
    }

    #[Test]
    public function noBorderCrossingOnDomesticRoute(): void
    {
        // Both stages are within France
        $stages = [
            new Stage(
                tripId: 'trip-1',
                dayNumber: 1,
                distance: 80.0,
                elevation: 200.0,
                startPoint: new Coordinate(48.8566, 2.3522),  // Paris
                endPoint: new Coordinate(48.1120, 1.6803),    // Chartres
            ),
            new Stage(
                tripId: 'trip-1',
                dayNumber: 2,
                distance: 70.0,
                elevation: 180.0,
                startPoint: new Coordinate(48.1120, 1.6803),  // Chartres
                endPoint: new Coordinate(47.3900, 0.6890),    // Tours
            ),
        ];

        $tripStateManager = $this->createTripStateManager($stages);

        // All checkpoints resolve to France
        $adminBoundaryRepository = $this->adminBoundaryRepository(['France', 'France', 'France']);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::BORDER_CROSSING_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $adminBoundaryRepository);
        $handler(new CheckBorderCrossing('trip-1'));
    }

    #[Test]
    public function duplicateBorderCrossingIsDeduplicatedForSameDirection(): void
    {
        // Route: France → Belgium → France → Belgium
        // The France→Belgium crossing should appear once,
        // and the Belgium→France crossing should also appear once
        $stages = [
            new Stage(
                tripId: 'trip-1',
                dayNumber: 1,
                distance: 80.0,
                elevation: 200.0,
                startPoint: new Coordinate(50.6292, 3.0573),
                endPoint: new Coordinate(50.8279, 3.2646),
            ),
            new Stage(
                tripId: 'trip-1',
                dayNumber: 2,
                distance: 60.0,
                elevation: 150.0,
                startPoint: new Coordinate(50.8279, 3.2646),
                endPoint: new Coordinate(50.6000, 3.4000),
            ),
            new Stage(
                tripId: 'trip-1',
                dayNumber: 3,
                distance: 50.0,
                elevation: 100.0,
                startPoint: new Coordinate(50.6000, 3.4000),
                endPoint: new Coordinate(50.8500, 3.5000),
            ),
        ];

        $tripStateManager = $this->createTripStateManager($stages);

        // Checkpoints: France → Belgium → France → Belgium
        $adminBoundaryRepository = $this->adminBoundaryRepository(['France', 'Belgium', 'France', 'Belgium']);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::BORDER_CROSSING_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    // France→Belgium (once) + Belgium→France (once) = 2 alerts
                    return 2 === \count($alerts)
                        && str_contains((string) $alerts[0]['message'], 'Belgium')
                        && str_contains((string) $alerts[1]['message'], 'France');
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $adminBoundaryRepository);
        $handler(new CheckBorderCrossing('trip-1'));
    }

    #[Test]
    public function nullStagesYieldsNoPublish(): void
    {
        $tripStateManager = $this->createTripStateManager(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $adminBoundaryRepository = $this->createStub(AdminBoundaryRepositoryInterface::class);

        $handler = $this->createHandler($tripStateManager, $publisher, $adminBoundaryRepository);
        $handler(new CheckBorderCrossing('trip-1'));
    }

    #[Test]
    public function nullCountryFromBoundaryLookupIsIgnored(): void
    {
        $stages = [
            new Stage(
                tripId: 'trip-1',
                dayNumber: 1,
                distance: 80.0,
                elevation: 200.0,
                startPoint: new Coordinate(50.6292, 3.0573),
                endPoint: new Coordinate(50.8279, 3.2646),
            ),
        ];

        $tripStateManager = $this->createTripStateManager($stages);

        // One point resolves to France, the other lies outside every stored boundary
        $adminBoundaryRepository = $this->adminBoundaryRepository(['France', null]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::BORDER_CROSSING_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $adminBoundaryRepository);
        $handler(new CheckBorderCrossing('trip-1'));
    }

    #[Test]
    public function alertIncludesNavigateActionAndCoordinates(): void
    {
        $stages = [
            new Stage(
                tripId: 'trip-1',
                dayNumber: 1,
                distance: 80.0,
                elevation: 200.0,
                startPoint: new Coordinate(50.6292, 3.0573),
                endPoint: new Coordinate(50.8279, 3.2646),
            ),
        ];

        $tripStateManager = $this->createTripStateManager($stages);

        $adminBoundaryRepository = $this->adminBoundaryRepository(['France', 'Belgium']);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::BORDER_CROSSING_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alert = $data['alerts'][0] ?? null;
                    if (null === $alert) {
                        return false;
                    }

                    // The crossing point should be the end point of stage 1 (entry into Belgium)
                    return 'navigate' === $alert['action']
                        && abs($alert['lat'] - 50.8279) < 0.001
                        && abs($alert['lon'] - 3.2646) < 0.001;
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $adminBoundaryRepository);
        $handler(new CheckBorderCrossing('trip-1'));
    }
}
