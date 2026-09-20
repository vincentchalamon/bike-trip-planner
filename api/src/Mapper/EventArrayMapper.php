<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\Model\Event;

/**
 * Converts an {@see Event} to and from its stored/published array form.
 *
 * Extracted from {@see \App\MessageHandler\ScanEventsHandler} when events became persisted
 * (ADR-068): the handler and the repository must agree on the shape, and two private copies
 * of the same mapping is how they stop agreeing.
 */
final readonly class EventArrayMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Event $event): array
    {
        return [
            'name' => $event->name,
            'type' => $event->type,
            'lat' => $event->lat,
            'lon' => $event->lon,
            'startDate' => $event->startDate->format(\DateTimeInterface::ATOM),
            'endDate' => $event->endDate->format(\DateTimeInterface::ATOM),
            'url' => $event->url,
            'description' => $event->description,
            'priceMin' => $event->priceMin,
            'distanceToEndPoint' => $event->distanceToEndPoint,
            'source' => $event->source,
            'wikidataId' => $event->wikidataId,
            'imageUrl' => $event->imageUrl,
            'wikipediaUrl' => $event->wikipediaUrl,
            'openingHours' => $event->openingHours,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): Event
    {
        return new Event(
            name: $this->str($data, 'name') ?? '',
            type: $this->str($data, 'type') ?? '',
            lat: $this->num($data, 'lat') ?? 0.0,
            lon: $this->num($data, 'lon') ?? 0.0,
            startDate: new \DateTimeImmutable($this->str($data, 'startDate') ?? 'now'),
            endDate: new \DateTimeImmutable($this->str($data, 'endDate') ?? 'now'),
            url: $this->str($data, 'url'),
            description: $this->str($data, 'description'),
            priceMin: $this->num($data, 'priceMin'),
            distanceToEndPoint: $this->num($data, 'distanceToEndPoint') ?? 0.0,
            source: $this->str($data, 'source') ?? 'datatourisme',
            wikidataId: $this->str($data, 'wikidataId'),
            imageUrl: $this->str($data, 'imageUrl'),
            wikipediaUrl: $this->str($data, 'wikipediaUrl'),
            openingHours: $this->str($data, 'openingHours'),
        );
    }

    /** @param array<string, mixed> $data */
    private function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $data */
    private function num(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return \is_int($value) || \is_float($value) ? (float) $value : null;
    }
}
