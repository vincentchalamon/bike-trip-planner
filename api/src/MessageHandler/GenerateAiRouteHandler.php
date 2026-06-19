<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Engine\RouteSimplifierInterface;
use App\Enum\ComputationName;
use App\Enum\SourceType;
use App\Generation\AiGeneratedRoute;
use App\Generation\AiTripGenerationServiceInterface;
use App\Llm\Exception\AiFailureReason;
use App\Llm\Exception\AiUnavailableException;
use App\Llm\ResolvedLlmClient;
use App\Llm\TripLlmResolverInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\GenerateAiRoute;
use App\Message\GenerateStages;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Async route generation (B1, ADR-042): turns the rider's brief into a routed
 * itinerary using the trip owner's configured provider, then hands off to the
 * unchanged pacing pipeline.
 *
 * Mirrors {@see FetchAndParseRouteHandler}: it owns the ROUTE computation step,
 * stores the raw + decimated geometry, sets {@see SourceType::AI_GENERATED} and
 * dispatches {@see GenerateStages}. Non-success outcomes (out-of-zone,
 * unparseable, ungeocodable, routing-failed) surface as a Mercure validation
 * error and stop the pipeline. A transient provider failure is re-thrown so
 * Messenger retries with back-off; a terminal one (invalid token / quota) fails
 * fast so the user's quota is not burned.
 */
#[AsMessageHandler]
final readonly class GenerateAiRouteHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private TripLlmResolverInterface $tripLlmResolver,
        private AiTripGenerationServiceInterface $generationService,
        private RouteSimplifierInterface $routeSimplifier,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
    }

    public function __invoke(GenerateAiRoute $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;

        $this->executeWithTracking($tripId, ComputationName::ROUTE, function () use ($tripId, $message, $generation): void {
            $resolved = $this->tripLlmResolver->resolveForTrip($tripId);
            if (!$resolved instanceof ResolvedLlmClient) {
                // The owner cleared their AI configuration between the request and
                // its processing. Nothing to retry — surface and stop.
                $this->publisher->publishValidationError($tripId, 'AI_NOT_CONFIGURED', $this->notConfiguredMessage($message->locale));

                return;
            }

            try {
                $route = $this->generationService->generate($message->brief, $resolved, $message->locale);
            } catch (AiUnavailableException $aiUnavailableException) {
                // Transient (5xx / timeout / throttle): re-throw so executeWithTracking
                // marks the step failed and Messenger retries with back-off.
                if ($aiUnavailableException->isTransient()) {
                    throw $aiUnavailableException;
                }

                // Terminal (invalid token / quota): fail fast, do not burn the quota.
                $this->logger->warning('AI generation aborted on a terminal provider error.', [
                    'tripId' => $tripId,
                    'reason' => $aiUnavailableException->getReason()->value,
                ]);
                $this->publisher->publishValidationError($tripId, 'AI_UNAVAILABLE', $this->unavailableMessage($aiUnavailableException->getReason(), $message->locale));

                return;
            }

            if (!$route->isSuccess()) {
                $this->publisher->publishValidationError($tripId, strtoupper($route->outcome->value), $route->message);

                return;
            }

            $this->storeRouteAndDispatch($tripId, $route, $message->locale, $generation);
        }, $generation);
    }

    private function storeRouteAndDispatch(string $tripId, AiGeneratedRoute $route, string $locale, ?int $generation): void
    {
        $this->tripStateManager->storeRawPoints($tripId, $this->toPointArrays($route->coordinates));

        // Decimate exactly like the fetch pipeline so GenerateStages finds the
        // simplified geometry it reads for pacing.
        $decimated = $this->routeSimplifier->simplify($route->coordinates);
        $this->tripStateManager->storeDecimatedPoints($tripId, $this->toPointArrays($decimated));

        $this->tripStateManager->storeSourceType($tripId, SourceType::AI_GENERATED->value);

        $title = $this->title($route, $locale);
        $this->tripStateManager->storeTitle($tripId, $title);

        // The pacing engine derives the number of days from maxDistancePerDay;
        // align it with the model's km/day so the routed distance is split into
        // roughly the requested number of stages.
        $this->applyKmPerDay($tripId, $route);

        // Valhalla geometry carries no elevation, so D+/D- are reported as 0 here
        // (the pacing engine computes flat profiles for AI-generated routes).
        $this->publisher->publish($tripId, MercureEventType::ROUTE_PARSED, [
            'totalDistance' => round($route->distanceKm, 1),
            'totalElevation' => 0,
            'totalElevationLoss' => 0,
            'sourceType' => SourceType::AI_GENERATED->value,
            'title' => $title,
        ]);

        $this->messageBus->dispatch(new GenerateStages($tripId, $generation));
    }

    private function applyKmPerDay(string $tripId, AiGeneratedRoute $route): void
    {
        $request = $this->tripStateManager->getRequest($tripId);
        if (!$request instanceof TripRequest) {
            return;
        }

        $kmPerDay = $route->spec['km_per_day'] ?? null;
        if (!is_numeric($kmPerDay)) {
            return;
        }

        // Clamp to the same bounds the API enforces (Assert\Range on the entity)
        // so a stray model value cannot drive pacing out of range.
        $request->maxDistancePerDay = (float) max(30, min(300, (int) $kmPerDay));
        $this->tripStateManager->storeRequest($tripId, $request);
    }

    private function title(AiGeneratedRoute $route, string $locale): string
    {
        $start = $this->specString($route->spec, 'start');
        $end = $this->specString($route->spec, 'end');
        $loop = true === ($route->spec['loop'] ?? false);

        if ('' === $start) {
            return 'fr' === $locale ? 'Itinéraire généré' : 'Generated route';
        }

        if ($loop || '' === $end) {
            return 'fr' === $locale ? \sprintf('Boucle au départ de %s', $start) : \sprintf('Loop from %s', $start);
        }

        return \sprintf('%s - %s', $start, $end);
    }

    /**
     * @param list<Coordinate> $coordinates
     *
     * @return list<array{lat: float, lon: float, ele: float}>
     */
    private function toPointArrays(array $coordinates): array
    {
        return array_map(
            static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
            $coordinates,
        );
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function specString(array $spec, string $key): string
    {
        $value = $spec[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    private function notConfiguredMessage(string $locale): string
    {
        return 'fr' === $locale
            ? 'Configurez une IA dans vos réglages pour générer un itinéraire.'
            : 'Configure an AI provider in your settings to generate a route.';
    }

    private function unavailableMessage(AiFailureReason $reason, string $locale): string
    {
        if ('fr' === $locale) {
            return match ($reason) {
                AiFailureReason::INVALID_TOKEN => 'Votre clé IA semble invalide. Vérifiez-la dans vos réglages.',
                AiFailureReason::QUOTA_EXCEEDED => 'Le quota de votre offre IA est épuisé. Vérifiez votre compte chez le fournisseur.',
                default => 'Assistant IA temporairement indisponible. Réessayez dans un instant.',
            };
        }

        return match ($reason) {
            AiFailureReason::INVALID_TOKEN => 'Your AI key looks invalid. Check it in your settings.',
            AiFailureReason::QUOTA_EXCEEDED => 'Your AI plan quota is exhausted. Check your provider account.',
            default => 'AI assistant temporarily unavailable. Please try again shortly.',
        };
    }
}
