<?php

declare(strict_types=1);

namespace App\Controller;

use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Mercure\TripSubscription;
use App\Service\GpxUploadServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Psr\Clock\ClockInterface;
use App\RateLimiter\RetryAfter;
use App\RouteParser\RouteTooLargeException;
use Symfony\Component\DependencyInjection\Attribute\Target;

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
        #[Target('gpx_upload')]
        private RateLimiterFactoryInterface $gpxUploadLimiter,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/trips/gpx-upload', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $file = $request->files->get('gpxFile');

        if (!$file instanceof UploadedFile) {
            return ProblemResponse::create(Response::HTTP_BAD_REQUEST, 'Missing required file: gpxFile');
        }

        if (!$file->isValid()) {
            // The PHP message quotes the server's ini limits; it goes to the log, not the client.
            $this->logger->info('GPX upload failed before reaching the controller.', ['error' => $file->getErrorMessage()]);

            return ProblemResponse::create(Response::HTTP_BAD_REQUEST, 'File upload failed.');
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return ProblemResponse::create(Response::HTTP_BAD_REQUEST, 'File exceeds maximum size of 30 MB.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if ('gpx' !== $extension) {
            return ProblemResponse::create(Response::HTTP_BAD_REQUEST, 'Only .gpx files are accepted.');
        }

        $mimeType = $file->getMimeType();
        if (null !== $mimeType && !in_array($mimeType, ['application/gpx+xml', 'application/xml', 'text/xml', 'text/plain'], true)) {
            return ProblemResponse::create(Response::HTTP_BAD_REQUEST, 'Only .gpx files are accepted.');
        }

        $content = file_get_contents($file->getPathname());
        if (false === $content || '' === $content) {
            return ProblemResponse::create(Response::HTTP_BAD_REQUEST, 'Failed to read uploaded file.');
        }

        /** @var User $user */
        $user = $this->security->getUser();

        // GPX upload is a second trip-creation entry point: throttle it per user like
        // POST /trips (SEC-006). Consumed before the parse, which is the expensive part: a
        // throttled client must not get a full parse of a 30 MB body for every request.
        // The cheap checks above stay unthrottled.
        $limit = $this->gpxUploadLimiter->create($user->getId()->toRfc4122())->consume();
        if (!$limit->isAccepted()) {
            return ProblemResponse::create(
                Response::HTTP_TOO_MANY_REQUESTS,
                'Too many GPX uploads. Try again later.',
                ['Retry-After' => (string) RetryAfter::seconds($limit, $this->clock)],
            );
        }

        $tripRequest = new TripRequest();
        $this->applyOptionalParameters($tripRequest, $request);

        // The DTO's own bound; this controller bypasses API Platform validation.
        if ($tripRequest->exceedsMaxDuration()) {
            return ProblemResponse::create(Response::HTTP_UNPROCESSABLE_ENTITY, \sprintf('A trip cannot last more than %d days.', TripRequest::MAX_TRIP_DAYS));
        }

        try {
            $points = $this->gpxUploadService->parseGpx($content);
        } catch (RouteTooLargeException $routeTooLargeException) {
            return ProblemResponse::create(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid GPX file: '.$routeTooLargeException->getMessage());
        } catch (\RuntimeException $runtimeException) {
            // Swallowed without a trace until now: a malformed upload was indistinguishable
            // from a parser regression in the logs, because there were no logs.
            $this->logger->warning('GPX upload could not be parsed.', ['exception' => $runtimeException]);

            return ProblemResponse::create(Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid GPX file: could not parse XML content.');
        }

        if ([] === $points) {
            return ProblemResponse::create(Response::HTTP_UNPROCESSABLE_ENTITY, 'GPX file contains no track points.');
        }

        $title = $this->gpxUploadService->extractTitle($content);

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

        $request->attributes->set(TripSubscription::ATTRIBUTE, $result['tripId']);

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

        // A form field can nest (`enabledAccommodationTypes[0][]=x`), so an entry is not
        // necessarily a string.
        $enabledAccommodationTypes = $request->request->all('enabledAccommodationTypes');
        if ([] !== $enabledAccommodationTypes) {
            $allowed = TripRequest::ALL_ACCOMMODATION_TYPES;
            $filtered = array_values(array_filter(
                $enabledAccommodationTypes,
                static fn (mixed $type): bool => \is_string($type) && \in_array($type, $allowed, true),
            ));
            if ([] !== $filtered) {
                $tripRequest->enabledAccommodationTypes = $filtered;
            }
        }
    }
}
