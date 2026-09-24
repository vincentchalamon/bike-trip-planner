<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Mcp;

use App\ApiResource\Mcp\StageDetail;
use ApiPlatform\Metadata\Get;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\Event;
use App\ApiResource\StageResponse;
use App\ApiResource\Trip;
use ApiPlatform\State\ProviderInterface;
use App\State\Mcp\StageDetailProjectionProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The projection cleans the labels it should and loses nothing on the way.
 *
 * `Accommodation` and `Event` are `final readonly` with nineteen and fifteen constructor
 * parameters, so cleaning one field means rebuilding the record. The provider does that by
 * reflecting the constructor rather than by naming its parameters, precisely so a field added
 * upstream cannot go missing in silence — these tests are what say that holds, and they are
 * the reason the reflection is defensible rather than clever.
 */
final class StageDetailProjectionProviderTest extends TestCase
{
    #[Test]
    public function aThirdPartyNameIsCleaned(): void
    {
        $detail = $this->project($this->stageWith(
            accommodation: $this->accommodation("Gîte\ndu\u{0007} Vercors"),
            event: $this->event("Fête\u{202E} du vélo"),
        ));

        self::assertSame('Gîte du Vercors', $detail->accommodations[0]->name);
        self::assertSame('Gîte du Vercors', $detail->selectedAccommodation?->name);
        self::assertSame('Fête du vélo', $detail->events[0]->name);
    }

    /**
     * The prose stays whole. The 200-character cap is right for a name and would cut a
     * Wikidata description mid-sentence, which is damaging the data in the name of protecting
     * it.
     */
    #[Test]
    public function theProseIsNotTouched(): void
    {
        $description = str_repeat('Une longue description patrimoniale. ', 12);

        $detail = $this->project($this->stageWith(
            accommodation: $this->accommodation('Gîte', $description),
            event: $this->event('Fête'),
        ));

        self::assertSame($description, $detail->accommodations[0]->description);
    }

    /**
     * Every other field survives the rebuild. Without this, an optional parameter added to
     * `Accommodation` later would quietly stop reaching the caller — the failure mode a
     * hand-written argument list has and this one must not.
     */
    #[Test]
    public function nothingButTheNameChanges(): void
    {
        $accommodation = $this->accommodation('Gîte');

        $rebuilt = $this->project($this->stageWith($accommodation, $this->event('Fête')))->accommodations[0];

        foreach (new \ReflectionClass(Accommodation::class)->getProperties() as $property) {
            if ('name' === $property->getName()) {
                continue;
            }

            self::assertSame(
                $property->getValue($accommodation),
                $property->getValue($rebuilt),
                \sprintf('`%s` did not survive the rebuild.', $property->getName()),
            );
        }
    }

    private function project(StageResponse $stage): StageDetail
    {
        $inner = $this->createStub(ProviderInterface::class);
        $inner->method('provide')->willReturn($stage);

        return new StageDetailProjectionProvider($inner)->provide(new Get());
    }

    private function stageWith(Accommodation $accommodation, Event $event): StageResponse
    {
        $stage = new StageResponse();
        $stage->id = '01936f6e-0000-7000-8000-0000000009b1';
        $stage->trip = new Trip('01936f6e-0000-7000-8000-0000000009a1', [], false);
        $stage->dayNumber = 1;
        $stage->distance = 85.5;
        $stage->elevation = 1200.0;
        $stage->elevationLoss = 900.0;
        $stage->startPoint = new Coordinate(45.0, 6.0, 1000.0);
        $stage->endPoint = new Coordinate(45.5, 6.5, 800.0);
        $stage->accommodations = [$accommodation];
        $stage->selectedAccommodation = $accommodation;
        $stage->events = [$event];

        return $stage;
    }

    private function accommodation(string $name, ?string $description = null): Accommodation
    {
        return new Accommodation(
            name: $name,
            type: 'hostel',
            lat: 45.5,
            lon: 6.5,
            estimatedPriceMin: 25.0,
            estimatedPriceMax: 40.0,
            isExactPrice: false,
            url: 'https://example.org/gite',
            description: $description,
        );
    }

    private function event(string $name): Event
    {
        return new Event(
            name: $name,
            type: 'festival',
            lat: 45.5,
            lon: 6.5,
            startDate: new \DateTimeImmutable('2026-07-01'),
            endDate: new \DateTimeImmutable('2026-07-02'),
        );
    }
}
