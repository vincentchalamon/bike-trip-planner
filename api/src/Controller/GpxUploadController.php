<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Service\GpxUploadServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        private GpxUploadServiceInterface $gpxUploadService,
        private Security $security,
        #[Autowire(service: 'limiter.gpx_upload')]
        private RateLimiterFactory $gpxUploadLimiter,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/trips/gpx-upload', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $file = $request->files->get('gpxFile');

        if (!$file instanceof UploadedFile) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Missing required file: gpxFile');
        }

        if (!$file->isValid()) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'File upload failed: '.$file->getErrorMessage());
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'File exceeds maximum size of 30 MB.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if ('gpx' !== $extension) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Only .gpx files are accepted.');
        }

        $mimeType = $file->getMimeType();
        if (null !== $mimeType && !in_array($mimeType, ['application/gpx+xml', 'application/xml', 'text/xml', 'text/plain'], true)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Only .gpx files are accepted.');
        }

        $content = file_get_contents($file->getPathname());
        if (false === $content || '' === $content) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Failed to read uploaded file.');
        }

        try {
            $points = $this->gpxUploadService->parseGpx($content);
        } catch (\RuntimeException $throwable) {
            // Swallowed without a trace until now: a malformed upload was indistinguishable
            // from a parser regression in the logs, because there were no logs.
            $this->logger->warning('GPX upload could not be parsed.', ['exception' => $throwable]);

            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid GPX file: could not parse XML content.');
        }

        if ([] === $points) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'GPX file contains no track points.');
        }

        $title = $this->gpxUploadService->extractTitle($content);

        $tripRequest = new TripRequest();
        $this->applyOptionalParameters($tripRequest, $request);

        /** @var User $user */
        $user = $this->security->getUser();

        // GPX upload is a second trip-creation entry point: throttle it per user like
        // POST /trips, just before the expensive createTrip, so it cannot be scripted
        // to exhaust storage/workers (SEC-006). Cheap early validation 4xx are not
        // throttled (and need no authenticated user).
        if (!$this->gpxUploadLimiter->create($user->getId()->toRfc4122())->consume()->isAccepted()) {
            return $this->problem(Response::HTTP_TOO_MANY_REQUESTS, 'Too many GPX uploads. Try again later.');
        }

        $result = $this->gpxUploadService->createTrip($points, $title, $tripRequest, $user->getLocale(), $user);

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
            'isLocked' => $result['isLocked'],
            'stages' => $result['stages'],
        ];

        if (null !== $title) {
            $response['title'] = $title;
        }

        return new JsonResponse($response, Response::HTTP_ACCEPTED);
    }

    /**
     * The error shape every other operation already answers with.
     *
     * `rfc_7807_compliant_errors` is on globally, but API Platform's ErrorListener only
     * covers its own operations: this is a plain Symfony route with no `_api_operation`,
     * and a multipart POST without an `Accept` header negotiates `html`, so the listener
     * bows out entirely. Hence the hand-built body — matching the eight keys of
     * tests/Functional/error-schema.json exactly, which is `additionalProperties: false`.
     */
    private function problem(int $status, string $detail): JsonResponse
    {
        return new JsonResponse([
            '@context' => '/contexts/Error',
            '@id' => '/errors/'.$status,
            '@type' => 'Error',
            'type' => '/errors/'.$status,
            'title' => 'An error occurred',
            'status' => $status,
            'detail' => $detail,
            'description' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json; charset=utf-8']);
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
