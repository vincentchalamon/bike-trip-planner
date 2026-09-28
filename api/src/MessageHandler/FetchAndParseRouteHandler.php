<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertRenderer;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\FetchAndParseRoute;
use App\Message\GenerateStages;
use App\Repository\TransientTripPointsStoreInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use App\RouteFetcher\RouteFetcherRegistryInterface;
use App\Service\TripBootstrapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class FetchAndParseRouteHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        TripRequestRepositoryInterface $tripRequestRepository,
        TripStageStoreInterface $stageStore,
        private TransientTripPointsStoreInterface $points,
        private RouteFetcherRegistryInterface $routeFetcherRegistry,
        private TripBootstrapper $bootstrapper,
        MessageBusInterface $messageBus,
        AlertRenderer $alertRenderer,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripRequestRepository, $stageStore, $messageBus, $alertRenderer);
    }

    public function __invoke(FetchAndParseRoute $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $request = $this->tripRequestRepository->getRequest($tripId);

        if (!$request instanceof TripRequest || null === $request->sourceUrl) {
            return;
        }

        $sourceUrl = $request->sourceUrl;

        $this->executeWithTracking($tripId, ComputationName::ROUTE, function () use ($tripId, $sourceUrl, $generation): void {
            // The route source is a live, on-demand third party (Tier 3). HTTP-level
            // transient failures are already retried with back-off by the scoped
            // client; a RuntimeException here is therefore a clear, terminal fetch
            // failure (private/removed tour, unreachable source, unparseable
            // response). Surface it to the user as a validation error and stop —
            // re-throwing would only trigger pointless Messenger retries.
            try {
                $fetcher = $this->routeFetcherRegistry->get($sourceUrl);
                $result = $fetcher->fetch($sourceUrl);
            } catch (\RuntimeException $runtimeException) {
                // Keep the technical detail (incl. raw cURL/transport messages) in
                // the logs; show the user a stable, friendly message.
                $this->logger->warning('Route fetch failed.', ['url' => $sourceUrl, 'error' => $runtimeException->getMessage()]);
                $this->publisher->publishValidationError($tripId, 'ROUTE_FETCH_FAILED', 'The route could not be fetched. Please check the URL and try again.');

                return;
            }

            if ([] === $result->tracks) {
                $this->publisher->publishValidationError($tripId, 'EMPTY_ROUTE', 'Empty route.');

                return;
            }

            $allPoints = array_merge(...$result->tracks);

            if ([] === $allPoints) {
                $this->publisher->publishValidationError($tripId, 'EMPTY_ROUTE', 'Empty route.');

                return;
            }

            $this->bootstrapper->storeRoute($tripId, $allPoints, $result->sourceType, $result->title);

            // Store raw tracks for collection source type (multiple tracks = 1 stage per track)
            if (\count($result->tracks) > 1) {
                $tracksData = [];
                foreach ($result->tracks as $track) {
                    $tracksData[] = array_map(
                        static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                        $track,
                    );
                }

                $this->points->storeTracksData($tripId, $tracksData);
            }

            $this->messageBus->dispatch(new GenerateStages($tripId, $generation));
        });
    }
}
