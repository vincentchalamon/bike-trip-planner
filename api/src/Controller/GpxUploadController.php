<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Engine\DistanceCalculatorInterface;
use App\Engine\ElevationCalculatorInterface;
use App\Engine\RouteSimplifierInterface;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\GenerateStages;
use App\Message\ScanAllOsmData;
use App\Repository\TripRequestRepositoryInterface;
use App\RouteParser\GpxStreamRouteParser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Handles direct GPX file uploads, bypassing the URL-based route fetching pipeline.
 *
 * The uploaded GPX content is parsed synchronously, then the same downstream
 * async pipeline (stage generation, OSM scan, etc.) is triggered via Messenger.
 */
final readonly class GpxUploadController
{
    private const int MAX_FILE_SIZE = 15 * 1024 * 1024; // 15 MB

    public function __construct(
        private GpxStreamRouteParser $gpxParser,
        private TripRequestRepositoryInterface $tripStateManager,
        private ComputationTrackerInterface $computationTracker,
        private MessageBusInterface $messageBus,
        private DistanceCalculatorInterface $distanceCalculator,
        private ElevationCalculatorInterface $elevationCalculator,
        private RouteSimplifierInterface $routeSimplifier,
        private TripUpdatePublisherInterface $publisher,
    ) {
    }

    #[Route('/trips/gpx-upload', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $file = $request->files->get('gpxFile');

        if (!$file instanceof UploadedFile) {
            return new JsonResponse(
                ['error' => 'Missing required file: gpxFile'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (!$file->isValid()) {
            return new JsonResponse(
                ['error' => 'File upload failed: '.$file->getErrorMessage()],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return new JsonResponse(
                ['error' => 'File exceeds maximum size of 15 MB.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if ('gpx' !== $extension) {
            return new JsonResponse(
                ['error' => 'Only .gpx files are accepted.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $content = file_get_contents($file->getPathname());
        if (false === $content || '' === $content) {
            return new JsonResponse(
                ['error' => 'Failed to read uploaded file.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        try {
            $points = $this->gpxParser->parse($content);
        } catch (\RuntimeException) {
            return new JsonResponse(
                ['error' => 'Invalid GPX file: could not parse XML content.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ([] === $points) {
            return new JsonResponse(
                ['error' => 'GPX file contains no track points.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $title = $this->gpxParser->extractTitle($content);

        // Initialize trip (same as TripCreateProcessor)
        $tripId = Uuid::v7()->toRfc4122();

        $tripRequest = new TripRequest();
        $this->applyOptionalParameters($tripRequest, $request);

        $this->tripStateManager->initializeTrip($tripId, $tripRequest);

        $locale = $request->getPreferredLanguage(['en', 'fr']) ?? 'en';
        $this->tripStateManager->storeLocale($tripId, $locale);

        $computations = ComputationName::pipeline();
        $this->computationTracker->initializeComputations($tripId, $computations);

        // Store route data (same as FetchAndParseRouteHandler)
        $this->tripStateManager->storeRawPoints($tripId, array_map(
            static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
            $points,
        ));

        $this->tripStateManager->storeSourceType($tripId, SourceType::GPX_UPLOAD->value);
        $this->tripStateManager->storeTitle($tripId, $title);

        $decimated = $this->routeSimplifier->simplify($points);
        $this->tripStateManager->storeDecimatedPoints($tripId, array_map(
            static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
            $decimated,
        ));

        $totalDistance = $this->distanceCalculator->calculateTotalDistance($points);
        $totalElevation = $this->elevationCalculator->calculateTotalAscent($points);
        $totalElevationLoss = $this->elevationCalculator->calculateTotalDescent($points);

        // Mark route computation as done (we already parsed the GPX synchronously)
        $this->computationTracker->markRunning($tripId, ComputationName::ROUTE);
        $this->computationTracker->markDone($tripId, ComputationName::ROUTE);

        $this->publisher->publish($tripId, MercureEventType::ROUTE_PARSED, [
            'totalDistance' => round($totalDistance, 1),
            'totalElevation' => (int) $totalElevation,
            'totalElevationLoss' => (int) $totalElevationLoss,
            'sourceType' => SourceType::GPX_UPLOAD->value,
            'title' => $title,
        ]);

        // Dispatch downstream async computations
        $this->messageBus->dispatch(new GenerateStages($tripId));
        $this->messageBus->dispatch(new ScanAllOsmData($tripId));

        // Build initial computation status
        $status = [];
        foreach ($computations as $computation) {
            $status[$computation->value] = ComputationName::ROUTE === $computation ? 'done' : 'pending';
        }

        return new JsonResponse([
            '@context' => '/contexts/Trip',
            '@id' => '/trips/'.$tripId,
            '@type' => 'Trip',
            'id' => $tripId,
            'computationStatus' => $status,
        ], Response::HTTP_ACCEPTED);
    }

    private function applyOptionalParameters(TripRequest $tripRequest, Request $request): void
    {
        $startDate = $request->request->getString('startDate');
        if ('' !== $startDate) {
            try {
                $tripRequest->startDate = new \DateTimeImmutable($startDate);
            } catch (\Exception) {
                // Ignore invalid date, use default
            }
        }

        $endDate = $request->request->getString('endDate');
        if ('' !== $endDate) {
            try {
                $tripRequest->endDate = new \DateTimeImmutable($endDate);
            } catch (\Exception) {
                // Ignore invalid date, use default
            }
        }

        $fatigueFactor = $request->request->get('fatigueFactor');
        if (null !== $fatigueFactor && '' !== $fatigueFactor) {
            $tripRequest->fatigueFactor = (float) $fatigueFactor;
        }

        $elevationPenalty = $request->request->get('elevationPenalty');
        if (null !== $elevationPenalty && '' !== $elevationPenalty) {
            $tripRequest->elevationPenalty = (float) $elevationPenalty;
        }

        $ebikeMode = $request->request->get('ebikeMode');
        if (null !== $ebikeMode) {
            $tripRequest->ebikeMode = filter_var($ebikeMode, \FILTER_VALIDATE_BOOLEAN);
        }
    }
}
