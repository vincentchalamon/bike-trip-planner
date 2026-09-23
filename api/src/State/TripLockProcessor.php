<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripRequest;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Refuses, with 423, the writes that would rewrite a trip already under way.
 *
 * A trip locks when its start date arrives, and never unlocks — the rider is on the road, and
 * an enrichment pass that replaces every stage under them is not an edit, it is a surprise.
 *
 * Declared per operation rather than called by hand in each processor. Nine processors used to
 * call `assertNotLocked()` themselves, which meant the list of locked operations existed twice:
 * once in the calls, once in whatever documented them. Two lists drift. Here the flag is both
 * what applies the rule and what {@see \App\Metadata\TripLockMetadataFactory} publishes, so
 * they cannot disagree — the same arrangement {@see PreconditionProcessor} uses for `If-Match`.
 *
 * Runs ahead of that precondition on purpose: telling a caller their version is stale, when the
 * trip will never accept an edit again whatever version they send, sends them round a reload
 * loop that cannot terminate.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
#[AsDecorator(decorates: 'api_platform.state_processor.write', priority: 10)]
final readonly class TripLockProcessor implements ProcessorInterface
{
    /**
     * Operations carrying this extra property refuse a started trip.
     *
     * Not every mutation does. `DELETE /trips/{id}` must stay open — the lock never lifts, so
     * refusing it would make a past trip permanently undeletable. `/duplicate` does not touch
     * the source. Sharing and revoking a link on a trip in progress is the point of sharing.
     * What is refused is rewriting the trip's own content.
     */
    public const string EXTRA_PROPERTY = 'refuses_locked_trip';

    /**
     * @param ProcessorInterface<mixed, mixed> $decorated
     */
    public function __construct(
        private ProcessorInterface $decorated,
        private TripRequestRepositoryInterface $tripStateManager,
        private TripLocker $tripLocker,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (true === ($operation->getExtraProperties()[self::EXTRA_PROPERTY] ?? false)) {
            $this->assertNotLocked($uriVariables, $context);
        }

        return $this->decorated->process($data, $operation, $uriVariables, $context);
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    private function assertNotLocked(array $uriVariables, array $context): void
    {
        $tripId = $uriVariables['tripId'] ?? $uriVariables['id'] ?? null;
        if (!\is_string($tripId)) {
            return;
        }

        $request = $this->storedTrip($tripId, $context);
        if (!$request instanceof TripRequest) {
            return;
        }

        $this->tripLocker->assertNotLocked($request);
    }

    /**
     * The trip as it stands, never as the request body would leave it.
     *
     * When the operation's own resource *is* the trip, reading the repository would hand back
     * the managed entity the deserializer has already populated — so an edit moving the start
     * date into the future would unlock the very trip it is editing. `previous_data` is the
     * clone taken before deserialisation, so it is the one to trust. The stage operations
     * deserialise a stage instead and address the trip by URI, so there is nothing to alias
     * and the repository is the only source.
     *
     * @param array<string, mixed> $context
     */
    private function storedTrip(string $tripId, array $context): ?TripRequest
    {
        $previous = $context['previous_data'] ?? null;

        return $previous instanceof TripRequest ? $previous : $this->tripStateManager->getRequest($tripId);
    }
}
