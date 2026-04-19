<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use App\ApiResource\Model\Event;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    #[Test]
    public function eventHasCorrectRequiredProperties(): void
    {
        $startDate = new \DateTimeImmutable('2025-07-10');
        $endDate = new \DateTimeImmutable('2025-07-12');

        $event = new Event(
            name: 'Festival de Jazz',
            type: 'schema:Festival',
            lat: 48.5,
            lon: 2.5,
            startDate: $startDate,
            endDate: $endDate,
        );

        $this->assertSame('Festival de Jazz', $event->name);
        $this->assertSame('schema:Festival', $event->type);
        $this->assertSame(48.5, $event->lat);
        $this->assertSame(2.5, $event->lon);
        $this->assertSame($startDate, $event->startDate);
        $this->assertSame($endDate, $event->endDate);
    }

    #[Test]
    public function eventHasCorrectDefaultValues(): void
    {
        $event = new Event(
            name: 'Concert',
            type: 'schema:MusicEvent',
            lat: 44.0,
            lon: 3.0,
            startDate: new \DateTimeImmutable('2025-08-01'),
            endDate: new \DateTimeImmutable('2025-08-01'),
        );

        $this->assertNull($event->url);
        $this->assertNull($event->description);
        $this->assertNull($event->priceMin);
        $this->assertSame(0.0, $event->distanceToEndPoint);
        $this->assertSame('datatourisme', $event->source);
        $this->assertNull($event->wikidataId);
    }

    #[Test]
    public function eventHasCorrectOptionalProperties(): void
    {
        $event = new Event(
            name: 'Exposition Renoir',
            type: 'schema:Exhibition',
            lat: 48.86,
            lon: 2.35,
            startDate: new \DateTimeImmutable('2025-06-01'),
            endDate: new \DateTimeImmutable('2025-09-30'),
            url: 'https://example.com/expo',
            description: 'Grande exposition impressionniste',
            priceMin: 12.0,
            distanceToEndPoint: 3500.0,
            source: 'datatourisme',
            wikidataId: 'Q123456',
        );

        $this->assertSame('https://example.com/expo', $event->url);
        $this->assertSame('Grande exposition impressionniste', $event->description);
        $this->assertSame(12.0, $event->priceMin);
        $this->assertSame(3500.0, $event->distanceToEndPoint);
        $this->assertSame('datatourisme', $event->source);
        $this->assertSame('Q123456', $event->wikidataId);
    }
}
