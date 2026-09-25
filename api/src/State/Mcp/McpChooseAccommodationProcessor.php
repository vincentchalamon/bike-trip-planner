<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\WriteAcknowledgement;
use App\ApiResource\StageSelectAccommodationRequest;
use App\Concurrency\TripVersionEtag;
use App\State\StageSelectAccommodationProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Picks where to sleep at the end of a day, and says what it moved.
 *
 * Wraps the HTTP processor untouched. Choosing a place is not only a note against a day: the
 * day's end point moves to it and the following day starts from there, which is why this write
 * moves the trip version like any other structural edit — and why the answer carries the new
 * one.
 *
 * @implements ProcessorInterface<StageSelectAccommodationRequest, WriteAcknowledgement>
 */
final readonly class McpChooseAccommodationProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<StageSelectAccommodationRequest, mixed> $selections
     */
    public function __construct(
        #[Autowire(service: StageSelectAccommodationProcessor::class)]
        private ProcessorInterface $selections,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WriteAcknowledgement
    {
        $this->selections->process($data, $operation, $uriVariables, $context);

        $chosen = null !== $data->selectedAccommodationLat && null !== $data->selectedAccommodationLon;

        return new WriteAcknowledgement(
            result: $chosen
                ? 'The place has been chosen. The day now ends there, and the next day starts from it.'
                : 'The choice has been cleared. The day ends where the route put it again.',
            version: $this->version($context),
            nextAction: 'Call `get_stage` for this day to see the distances after the move.',
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function version(array $context): ?int
    {
        $request = $context['request'] ?? null;
        $stamped = $request instanceof Request ? $request->attributes->get(TripVersionEtag::ATTRIBUTE) : null;

        return \is_int($stamped) ? $stamped : null;
    }
}
