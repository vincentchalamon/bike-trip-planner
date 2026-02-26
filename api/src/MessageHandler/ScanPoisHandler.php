<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\PointOfInterest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckResupply;
use App\Message\ScanPois;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ScanPoisHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(ScanPois $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $this->executeWithTracking($tripId, ComputationName::POIS, function () use ($tripId, $stages): void {
            foreach ($stages as $i => $stage) {
                $query = $this->queryBuilder->buildPoiQuery($stage->geometry ?: [$stage->startPoint, $stage->endPoint]);
                $result = $this->scanner->query($query);

                $pois = [];
                /** @var list<array{tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
                $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];
                foreach ($elements as $element) {
                    $tags = $element['tags'] ?? [];
                    $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                    $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

                    if (null === $lat || null === $lon) {
                        continue;
                    }

                    $category = $tags['amenity'] ?? $tags['shop'] ?? $tags['tourism'] ?? 'unknown';
                    $name = $tags['name'] ?? $category;

                    $poi = new PointOfInterest(
                        name: $name,
                        category: $category,
                        lat: (float) $lat,
                        lon: (float) $lon,
                    );

                    $stage->addPoi($poi);
                    $pois[] = ['name' => $poi->name, 'category' => $poi->category];
                }

                $this->publisher->publish($tripId, MercureEventType::POIS_SCANNED, [
                    'stageIndex' => $i,
                    'pois' => $pois,
                ]);
            }

            $this->tripStateManager->storeStages($tripId, $stages);
            $this->messageBus->dispatch(new CheckResupply($tripId));
        });
    }
}
