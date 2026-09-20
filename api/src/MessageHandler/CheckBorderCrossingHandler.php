<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertRenderer;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertCode;
use App\Enum\AlertParameterFormat;
use App\Enum\AlertGroup;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckBorderCrossing;
use App\Osm\AdminBoundaryRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Detects international border crossings along the route.
 *
 * For each stage, resolves the country at start and end points via a ST_Covers
 * lookup against the local admin_level=2 boundaries (ADR-040). When consecutive
 * points belong to different countries, a nudge is emitted indicating the border
 * crossing. Deduplicates: each unique country pair (A→B) produces at most one nudge.
 */
#[AsMessageHandler]
final readonly class CheckBorderCrossingHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private AdminBoundaryRepositoryInterface $adminBoundaryRepository,
        MessageBusInterface $messageBus,
        AlertRenderer $alertRenderer,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus, $alertRenderer);
    }

    public function __invoke(CheckBorderCrossing $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $this->executeWithTracking($tripId, ComputationName::BORDER_CROSSING, function () use ($tripId, $stages): void {
            // Collect unique points to query: start of first stage + end of each stage
            $checkPoints = $this->buildCheckPoints($stages);

            if (\count($checkPoints) < 2) {
                // Nothing found is a result, not an absence of one: the group is cleared so a
                // previous run's alerts do not survive as stale.
                $this->tripStateManager->updateTripAlertsForGroup($tripId, AlertGroup::BORDER_CROSSING, []);
                $this->publisher->publish($tripId, MercureEventType::BORDER_CROSSING_ALERTS, [
                    'alerts' => [],
                ]);

                return;
            }

            // Resolve country for each checkpoint via ST_Covers against the local boundaries
            $countries = $this->resolveCountries($checkPoints);

            // Detect border crossings: when consecutive countries differ
            $alerts = [];
            /** @var list<string> $seenCrossings */
            $seenCrossings = [];

            for ($i = 1, $count = \count($countries); $i < $count; ++$i) {
                $prevCountry = $countries[$i - 1];
                $currentCountry = $countries[$i];

                if (null === $prevCountry || null === $currentCountry) {
                    continue;
                }

                if ($prevCountry === $currentCountry) {
                    continue;
                }

                // Deduplicate: same crossing (A→B) only once
                $crossingKey = $prevCountry.'->'.$currentCountry;
                if (\in_array($crossingKey, $seenCrossings, true)) {
                    continue;
                }

                $seenCrossings[] = $crossingKey;

                $crossingPoint = $checkPoints[$i];

                // Find which stage this crossing belongs to
                $stageIndex = min($i - 1, \count($stages) - 1);
                $stage = $stages[$stageIndex];

                $alerts[] = [
                    'stageId' => $stage->id,
                    'dayNumber' => $stage->dayNumber,
                    'code' => AlertCode::BORDER_CROSSING->value,
                    'type' => AlertType::NUDGE->value,
                    'messageKey' => 'alert.border_crossing.nudge',
                    'parameters' => ['%country%' => $currentCountry],
                    'parameterFormats' => ['%country%' => AlertParameterFormat::COUNTRY->value],
                    'action' => [
                        'kind' => AlertActionKind::NAVIGATE->value,
                        'labelKey' => 'alert.border_crossing.action',
                        'payload' => ['lat' => $crossingPoint->lat, 'lon' => $crossingPoint->lon],
                    ],
                    'lat' => $crossingPoint->lat,
                    'lon' => $crossingPoint->lon,
                ];
            }

            // Same array to the database and to the wire (ADR-068): grouped by the stage
            // it addresses, without `stageId`/`dayNumber` — the first is the key, the
            // second is renumbered by every structural edit and is derived on read.
            $this->tripStateManager->updateTripAlertsForGroup($tripId, AlertGroup::BORDER_CROSSING, $this->groupByStage($alerts));

            $this->publisher->publish($tripId, MercureEventType::BORDER_CROSSING_ALERTS, [
                'alerts' => $this->renderForWire($tripId, $alerts),
            ]);
        }, $generation);
    }

    /**
     * Builds the list of coordinates to check for country boundaries.
     *
     * Uses the start point of the first stage, then the end point of each stage.
     * This produces N+1 checkpoints for N stages, covering every stage transition.
     *
     * @param list<Stage> $stages
     *
     * @return list<Coordinate>
     */
    private function buildCheckPoints(array $stages): array
    {
        if ([] === $stages) {
            return [];
        }

        $points = [$stages[0]->startPoint];

        foreach ($stages as $stage) {
            $points[] = $stage->endPoint;
        }

        return $points;
    }

    /**
     * Resolves the country name for each coordinate via a ST_Covers lookup against
     * the local admin_level=2 boundaries.
     *
     * @param list<Coordinate> $points
     *
     * @return list<string|null>
     */
    private function resolveCountries(array $points): array
    {
        $countries = [];
        foreach ($points as $point) {
            // The ISO code, not the localised name: the name is what the reader's language
            // decides (ADR-069), and comparing codes is what detects a crossing anyway.
            $countries[] = $this->adminBoundaryRepository->findCountryCodeAt($point->lat, $point->lon);
        }

        return $countries;
    }
}
