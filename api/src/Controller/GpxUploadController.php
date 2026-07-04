<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Service\GpxUploadService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles direct GPX file uploads, bypassing the URL-based route fetching pipeline.
 *
 * Responsible only for HTTP adaptation (validation, request parsing, response formatting).
 * Business logic is delegated to {@see GpxUploadService}.
 */
final readonly class GpxUploadController
{
    private const int MAX_FILE_SIZE = 30 * 1024 * 1024; // 30 MB

    public function __construct(
        private GpxUploadService $gpxUploadService,
        private Security $security,
        #[Autowire(service: 'limiter.gpx_upload')]
        private RateLimiterFactory $gpxUploadLimiter,
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
                ['error' => 'File exceeds maximum size of 30 MB.'],
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

        $mimeType = $file->getMimeType();
        if (null !== $mimeType && !in_array($mimeType, ['application/gpx+xml', 'application/xml', 'text/xml', 'text/plain'], true)) {
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
            $points = $this->gpxUploadService->parseGpx($content);
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

        $title = $this->gpxUploadService->extractTitle($content);

        $tripRequest = new TripRequest();
        $this->applyOptionalParameters($tripRequest, $request);

        $locale = $request->getPreferredLanguage(['en', 'fr']) ?? 'en';

        /** @var User $user */
        $user = $this->security->getUser();

        // GPX upload is a second trip-creation entry point: throttle it per user like
        // POST /trips, just before the expensive createTrip, so it cannot be scripted
        // to exhaust storage/workers (SEC-006). Cheap early validation 4xx are not
        // throttled (and need no authenticated user).
        if (!$this->gpxUploadLimiter->create($user->getId()->toRfc4122())->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }

        $result = $this->gpxUploadService->createTrip($points, $title, $tripRequest, $locale, $user);

        $response = [
            '@context' => '/contexts/Trip',
            '@id' => '/trips/'.$result['tripId'],
            '@type' => 'Trip',
            'id' => $result['tripId'],
            'computationStatus' => $result['computationStatus'],
            'totalDistance' => $result['totalDistance'],
            'totalElevation' => $result['totalElevation'],
            'totalElevationLoss' => $result['totalElevationLoss'],
            // ADR-043: structural data is computed synchronously, so the response
            // already carries the persisted status and the computed stages.
            'status' => $result['status'],
            'stages' => $result['stages'],
        ];

        if (null !== $title) {
            $response['title'] = $title;
        }

        return new JsonResponse($response, Response::HTTP_ACCEPTED);
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

        // Enforce the same bounds as the TripRequest DTO (Assert\Range / Assert\Positive):
        // this custom controller bypasses API Platform validation, and an out-of-range
        // value — notably elevationPenalty=0 — would reach the pacing engine and throw
        // DivisionByZeroError (HTTP 500) after a partial trip was already persisted (BUG-002).
        $fatigueFactor = $request->request->get('fatigueFactor');
        if (null !== $fatigueFactor && '' !== $fatigueFactor && is_numeric($fatigueFactor)) {
            $value = (float) $fatigueFactor;
            if ($value >= 0.5 && $value <= 1.0) {
                $tripRequest->fatigueFactor = $value;
            }
        }

        $elevationPenalty = $request->request->get('elevationPenalty');
        if (null !== $elevationPenalty && '' !== $elevationPenalty && is_numeric($elevationPenalty)) {
            $value = (float) $elevationPenalty;
            if ($value > 0.0) {
                $tripRequest->elevationPenalty = $value;
            }
        }

        $ebikeMode = $request->request->get('ebikeMode');
        if (null !== $ebikeMode) {
            $parsed = filter_var($ebikeMode, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);
            if (null !== $parsed) {
                $tripRequest->ebikeMode = $parsed;
            }
        }

        /** @var list<string> $enabledAccommodationTypes */
        $enabledAccommodationTypes = $request->request->all('enabledAccommodationTypes');
        if ([] !== $enabledAccommodationTypes) {
            $allowed = TripRequest::ALL_ACCOMMODATION_TYPES;
            $filtered = array_values(array_filter(
                $enabledAccommodationTypes,
                static fn (string $type): bool => \in_array($type, $allowed, true),
            ));
            if ([] !== $filtered) {
                $tripRequest->enabledAccommodationTypes = $filtered;
            }
        }
    }
}
