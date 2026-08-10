<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\OpenAgendaMapper;

final class OpenAgendaMapperTest extends TestCase
{
    private OpenAgendaMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new OpenAgendaMapper();
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function record(array $extra = []): array
    {
        return $extra + [
            'uid' => 42,
            'canonicalurl' => 'https://openagenda.com/events/e-42',
            'title_fr' => 'Un evenement',
            'firstdate_begin' => '2026-07-01T18:00:00+02:00',
            'lastdate_end' => '2026-07-03T23:00:00+02:00',
            'location_coordinates' => ['lat' => 48.11, 'lon' => -1.68],
        ];
    }

    #[Test]
    public function mapsGeoDatesUrlAndTags(): void
    {
        $row = $this->mapper->map($this->record([
            'keywords_fr' => ['Concert'],
            'description_fr' => 'Un beau concert',
            'location_city' => 'Rennes',
            'location_postalcode' => '35000',
            'image' => 'https://cdn.openagenda.com/e-42.jpg',
        ]));

        self::assertNotNull($row);
        self::assertSame('openagenda:42', $row['id']);
        self::assertSame('Un evenement', $row['name']);
        self::assertSame('concert', $row['category']);
        self::assertSame(48.11, $row['lat']);
        self::assertSame(-1.68, $row['lon']);
        self::assertSame('2026-07-01', $row['startDate'], 'the date is extracted from the ISO datetime');
        self::assertSame('2026-07-03', $row['endDate']);
        self::assertSame('https://openagenda.com/events/e-42', $row['url']);
        self::assertSame('Un beau concert', $row['description']);
        self::assertNull($row['priceMin']);
        self::assertSame(['Concert'], $row['tags']['keywords']);
        self::assertSame('Rennes', $row['tags']['city']);
        self::assertSame('35000', $row['tags']['postal_code']);
        self::assertSame('https://cdn.openagenda.com/e-42.jpg', $row['tags']['image_url']);
    }

    #[Test]
    public function dropsARecordWithoutACanonicalUrl(): void
    {
        // ADR-051: an event a rider cannot open is noise, so it never reaches the table.
        self::assertNull($this->mapper->map($this->record(['canonicalurl' => ''])));

        $noUrl = $this->record();
        unset($noUrl['canonicalurl']);
        self::assertNull($this->mapper->map($noUrl));
    }

    #[Test]
    public function dropsARecordWithoutAUsableDateRange(): void
    {
        $noStart = $this->record();
        unset($noStart['firstdate_begin']);
        self::assertNull($this->mapper->map($noStart));

        $noEnd = $this->record();
        unset($noEnd['lastdate_end']);
        self::assertNull($this->mapper->map($noEnd));
    }

    #[Test]
    public function dropsARecordWithoutCoordinates(): void
    {
        $noGeo = $this->record();
        unset($noGeo['location_coordinates']);
        self::assertNull($this->mapper->map($noGeo));
    }

    #[Test]
    public function readsCoordinatesFromALatLonPairToo(): void
    {
        $row = $this->mapper->map($this->record(['location_coordinates' => [48.5, 2.3]]));

        self::assertNotNull($row);
        self::assertSame(48.5, $row['lat']);
        self::assertSame(2.3, $row['lon']);
    }

    /**
     * @param list<string> $keywords
     */
    #[Test]
    #[DataProvider('keywordCategories')]
    public function normalisesKeywordsOntoTheSharedEventVocabulary(array $keywords, string $expected): void
    {
        $row = $this->mapper->map($this->record(['keywords_fr' => $keywords]));

        self::assertNotNull($row);
        self::assertSame($expected, $row['category']);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function keywordCategories(): iterable
    {
        yield 'festival' => [['Festival de rue'], 'festival'];
        yield 'accented concert' => [['Musique électronique'], 'concert'];
        yield 'exhibition' => [['Exposition de peinture'], 'exhibition'];
        yield 'sports' => [['Course cycliste'], 'sports'];
        yield 'fair' => [['Foire aux vins'], 'fair'];
        yield 'show' => [['Spectacle de danse'], 'show'];
        yield 'unmapped keyword falls back to the dropped generic' => [['Conférence'], 'event'];
        yield 'no keyword at all' => [[], 'event'];
        // Word-boundary matching: a needle embedded in a larger word must not match.
        yield 'transport does not embed-match sport' => [['Transport en commun'], 'event'];
        yield 'demarche does not embed-match marche' => [['Démarche administrative'], 'event'];
    }

    #[Test]
    public function youngAudienceIsNormalisedOutsideTheWhitelist(): void
    {
        // ADR-051 §3: a source normalises young-audience events to a category the shared
        // relevance whitelist drops. `youth` is deliberately outside RELEVANT_CATEGORIES,
        // and audience takes precedence over the topic keyword.
        $byKeyword = $this->mapper->map($this->record(['keywords_fr' => ['Concert', 'Jeune public']]));
        self::assertNotNull($byKeyword);
        self::assertSame('youth', $byKeyword['category']);

        $byAge = $this->mapper->map($this->record(['keywords_fr' => ['Spectacle'], 'age_max' => 6]));
        self::assertNotNull($byAge);
        self::assertSame('youth', $byAge['category']);
    }

    #[Test]
    public function acceptsAStringUidAndAScalarKeyword(): void
    {
        $row = $this->mapper->map($this->record(['uid' => 'abc-1', 'keywords_fr' => 'Festival']));

        self::assertNotNull($row);
        self::assertSame('openagenda:abc-1', $row['id']);
        self::assertSame('festival', $row['category']);
    }
}
