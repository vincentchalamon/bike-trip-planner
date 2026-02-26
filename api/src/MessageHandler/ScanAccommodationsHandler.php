<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Accommodation;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Engine\PricingHeuristicEngine;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanAccommodations;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ScanAccommodationsHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        #[Autowire(service: 'app.engine_registry')]
        private ContainerInterface $engineRegistry,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(ScanAccommodations $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $this->executeWithTracking($tripId, ComputationName::ACCOMMODATIONS, function () use ($tripId, $stages): void {
            foreach ($stages as $i => $stage) {
                $query = $this->queryBuilder->buildAccommodationQuery([$stage->endPoint]);
                $result = $this->scanner->query($query);

                $accommodations = [];
                /** @var list<array{tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
                $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];
                foreach ($elements as $element) {
                    $tags = $element['tags'] ?? [];
                    $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                    $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

                    if (null === $lat || null === $lon) {
                        continue;
                    }

                    $type = $tags['tourism'] ?? 'hotel';
                    $name = $tags['name'] ?? $type;
                    $pricing = $this->engineRegistry
                        ->get(PricingHeuristicEngine::class)
                        ->estimatePrice($type, $tags);

                    $accommodation = new Accommodation(
                        name: $name,
                        type: $type,
                        lat: (float) $lat,
                        lon: (float) $lon,
                        estimatedPriceMin: $pricing['min'],
                        estimatedPriceMax: $pricing['max'],
                        isExactPrice: $pricing['isExact'],
                    );

                    $stage->addAccommodation($accommodation);
                    $accommodations[] = [
                        'name' => $accommodation->name,
                        'type' => $accommodation->type,
                        'priceMin' => $accommodation->estimatedPriceMin,
                        'priceMax' => $accommodation->estimatedPriceMax,
                    ];
                }

                $this->publisher->publish($tripId, MercureEventType::ACCOMMODATIONS_FOUND, [
                    'stageIndex' => $i,
                    'accommodations' => $accommodations,
                ]);
            }

            $this->tripStateManager->storeStages($tripId, $stages);
        });
    }
}
