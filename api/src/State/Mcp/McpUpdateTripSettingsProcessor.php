<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\WriteAcknowledgement;
use App\ApiResource\Trip;
use App\ApiResource\TripRequest;
use App\Concurrency\IfMatch;
use App\Concurrency\TripVersionEtag;
use App\Enum\ComputationStatus;
use App\State\TripUpdateProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Changes a trip's settings, and says what it cost.
 *
 * Wraps the HTTP processor untouched. Everything that makes a settings edit correct happens in
 * there — which computations a change invalidates, the generation bump that makes in-flight work
 * stale, the supersession of what is not re-dispatched — and none of it is transport business.
 *
 * What is transport business is the answer. `Trip` carries `{id, computationStatus, isLocked}`,
 * which is right for a 202 whose `Location` header says where to look; here there is no header,
 * and a model needs words and the next version.
 *
 * @implements ProcessorInterface<TripRequest, WriteAcknowledgement>
 */
final readonly class McpUpdateTripSettingsProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TripRequest, Trip> $updates
     */
    public function __construct(
        #[Autowire(service: TripUpdateProcessor::class)]
        private ProcessorInterface $updates,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WriteAcknowledgement
    {
        $trip = $this->updates->process($data, $operation, $uriVariables, $context);

        $recomputing = \in_array(ComputationStatus::PENDING->value, $trip->computationStatus, true);

        return new WriteAcknowledgement(
            result: $recomputing
                ? 'The settings have been saved. The days are being worked out again, so the existing split and any chosen accommodation are gone.'
                : 'The settings have been saved. Nothing about them changes how the days are cut, so the trip is unchanged otherwise.',
            version: $this->version($context),
            nextAction: $recomputing
                ? 'Call `get_trip` in a little while — usually under a minute — to see the new days.'
                : null,
        );
    }

    /**
     * The version this write left behind.
     *
     * Stamped from inside the write when the edit triggered a regeneration, which is the only
     * case that moves it. Re-reading the trip here instead would hand back whichever version won
     * a race after the write, which is precisely what the stamp exists to avoid.
     *
     * When nothing was regenerated, nothing moved, so the version the caller asserted is still
     * the current one — and saying so spares an agent a read it does not need.
     *
     * @param array<string, mixed> $context
     */
    private function version(array $context): ?int
    {
        $request = $context['request'] ?? null;
        $stamped = $request instanceof Request ? $request->attributes->get(TripVersionEtag::ATTRIBUTE) : null;

        return \is_int($stamped) ? $stamped : IfMatch::expectedVersion($context);
    }
}
