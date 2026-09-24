<?php

declare(strict_types=1);

namespace App\State\Mcp;

use App\ApiResource\Model\Accommodation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mcp\StageDetail;
use App\ApiResource\StageResponse;
use App\State\StageDetailProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Projects one stage into what `get_stage` answers with: everything but the coordinate trail.
 *
 * Same arrangement as {@see TripDigestProvider} and for the same reason — the HTTP provider
 * decides authorization, absence and rendering, and a second implementation of any of those
 * would be a second place to get them wrong. Only the shape of the answer differs.
 *
 * @implements ProviderInterface<StageDetail>
 */
final readonly class StageDetailProjectionProvider implements ProviderInterface
{
    /**
     * Typed by the interface and wired to the one implementation, so this class can be unit
     * tested: {@see StageDetailProvider} is final and PHPUnit cannot double a final class.
     * Same reason {@see \App\State\Idempotency} is not final.
     *
     * @param ProviderInterface<StageResponse> $stage
     */
    public function __construct(
        #[Autowire(service: StageDetailProvider::class)]
        private ProviderInterface $stage,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StageDetail
    {
        $stage = $this->stage->provide($operation, $uriVariables, $context);

        // Narrowing, not paranoia: the dependency is typed by the interface so this class can
        // be doubled in a unit test, and the interface promises an item, a collection or
        // nothing. The one wired implementation always returns the item.
        \assert($stage instanceof StageResponse);

        return new StageDetail(
            id: $stage->id,
            dayNumber: $stage->dayNumber,
            distance: $stage->distance,
            elevation: $stage->elevation,
            elevationLoss: $stage->elevationLoss,
            startPoint: $stage->startPoint,
            endPoint: $stage->endPoint,
            label: ThirdPartyText::clean($stage->label),
            isRestDay: $stage->isRestDay,
            weather: $stage->weather,
            alerts: array_values($stage->alerts),
            resupply: $stage->resupply,
            accommodations: array_values(array_map($this->withCleanName(...), $stage->accommodations)),
            selectedAccommodation: $stage->selectedAccommodation instanceof Accommodation ? $this->withCleanName($stage->selectedAccommodation) : null,
            events: array_values(array_map($this->withCleanName(...), $stage->events)),
        );
    }

    /**
     * The same record with its `name` put through {@see ThirdPartyText}.
     *
     * An accommodation's or an event's `name` is a short OSM or DataTourisme label —
     * structurally the same thing as a stage's, which is cleaned, and the same thing
     * {@see TripDigestProvider} cleans on the way into the digest. Leaving it raw here meant
     * the two tools treated one category of data two different ways. The prose these records
     * also carry — a Wikidata description, opening hours — is deliberately untouched: the
     * 200-character cap is right for a name and would damage a sentence.
     *
     * Rebuilt by reflecting the constructor rather than by naming its nineteen parameters,
     * and that is the point rather than a shortcut. A hand-written list silently drops any
     * optional field added upstream — the value would simply stop reaching the caller, with
     * nothing to say so. Copying whatever the constructor declares cannot drift.
     *
     * @template T of object
     *
     * @param T $record
     *
     * @return T
     */
    private function withCleanName(object $record): object
    {
        $arguments = [];

        foreach (new \ReflectionClass($record)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $property = $parameter->getName();
            $arguments[$property] = $record->{$property};
        }

        \assert(\is_string($arguments['name'] ?? null), 'withCleanName() is for records whose `name` is their third-party label.');
        $arguments['name'] = ThirdPartyText::clean($arguments['name']) ?? '';

        return new ($record::class)(...$arguments);
    }
}
