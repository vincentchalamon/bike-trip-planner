<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiResource\TripRequest;
use App\Service\GpxUploadService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Handles direct GPX file uploads, bypassing the URL-based route fetching pipeline.
 *
 * Responsible only for HTTP adaptation (validation, request parsing, response formatting).
 * Business logic is delegated to {@see GpxUploadService}.
 */
final readonly class GpxUploadController
{
    private const int MAX_FILE_SIZE = 15 * 1024 * 1024; // 15 MB

    public function __construct(
        private GpxUploadService $gpxUploadService,
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

        $result = $this->gpxUploadService->createTrip($points, $title, $tripRequest, $locale);

        $response = [
            '@context' => '/contexts/Trip',
            '@id' => '/trips/'.$result['tripId'],
            '@type' => 'Trip',
            'id' => $result['tripId'],
            'computationStatus' => $result['computationStatus'],
            'totalDistance' => $result['totalDistance'],
            'totalElevation' => $result['totalElevation'],
            'totalElevationLoss' => $result['totalElevationLoss'],
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

        $fatigueFactor = $request->request->get('fatigueFactor');
        if (null !== $fatigueFactor && '' !== $fatigueFactor && is_numeric($fatigueFactor)) {
            $tripRequest->fatigueFactor = (float) $fatigueFactor;
        }

        $elevationPenalty = $request->request->get('elevationPenalty');
        if (null !== $elevationPenalty && '' !== $elevationPenalty && is_numeric($elevationPenalty)) {
            $tripRequest->elevationPenalty = (float) $elevationPenalty;
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
