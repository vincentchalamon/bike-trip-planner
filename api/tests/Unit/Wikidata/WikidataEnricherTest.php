<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wikidata;

use App\Wikidata\WikidataClientInterface;
use App\Wikidata\WikidataEnricher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WikidataEnricherTest extends TestCase
{
    // -------------------------------------------------------------------------
    // enrichBatch() — empty input
    // -------------------------------------------------------------------------

    #[Test]
    public function enrichBatchWithEmptyQIdsReturnsEmpty(): void
    {
        $client = $this->createMock(WikidataClientInterface::class);
        $client->expects($this->never())->method('query');

        $enricher = new WikidataEnricher($client);

        $this->assertSame([], $enricher->enrichBatch([], 'fr'));
    }

    // -------------------------------------------------------------------------
    // enrichBatch() — fixture response
    // -------------------------------------------------------------------------

    #[Test]
    public function enrichBatchParsesFixtureResponse(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/../../Fixtures/wikidata/batch-response.json'),
            true,
        );
        /** @var array<string, mixed> $fixture */
        $bindings = $fixture['results']['bindings'];

        $client = $this->createMock(WikidataClientInterface::class);
        $client->expects($this->once())
            ->method('query')
            ->willReturn($bindings);

        $enricher = new WikidataEnricher($client);

        $result = $enricher->enrichBatch(['Q12345', 'Q67890'], 'fr');

        $this->assertArrayHasKey('Q12345', $result);
        $this->assertSame('Château de Versailles', $result['Q12345']['label']);
        $this->assertSame('Palais royal situé à Versailles, France.', $result['Q12345']['description']);
        $this->assertStringContainsString('Versailles_Palace', $result['Q12345']['imageUrl']);
        $this->assertStringContainsString('width=400', $result['Q12345']['imageUrl']);
        $this->assertSame('https://www.chateauversailles.fr', $result['Q12345']['website']);
        $this->assertSame('Tu-Su 09:00-17:30', $result['Q12345']['openingHours']);
        $this->assertSame('https://fr.wikipedia.org/wiki/Château_de_Versailles', $result['Q12345']['wikipediaUrl']);

        $this->assertArrayHasKey('Q67890', $result);
        $this->assertSame('Tour Eiffel', $result['Q67890']['label']);
        $this->assertArrayNotHasKey('website', $result['Q67890']);
        $this->assertArrayNotHasKey('openingHours', $result['Q67890']);
    }

    // -------------------------------------------------------------------------
    // enrichBatch() — batching 50 per 50
    // -------------------------------------------------------------------------

    #[Test]
    public function enrichBatchSplitsInto50PerBatch(): void
    {
        $qIds = array_map(static fn (int $i): string => 'Q'.$i, range(1, 110));

        $client = $this->createMock(WikidataClientInterface::class);
        $client->expects($this->exactly(3))
            ->method('query')
            ->willReturn([]);

        $enricher = new WikidataEnricher($client);
        $enricher->enrichBatch($qIds, 'en');
    }

    #[Test]
    public function enrichBatchExactly50QIdsMakesOneBatch(): void
    {
        $qIds = array_map(static fn (int $i): string => 'Q'.$i, range(1, 50));

        $client = $this->createMock(WikidataClientInterface::class);
        $client->expects($this->once())
            ->method('query')
            ->willReturn([]);

        $enricher = new WikidataEnricher($client);
        $enricher->enrichBatch($qIds, 'en');
    }

    // -------------------------------------------------------------------------
    // enrichBatch() — locale fallback
    // -------------------------------------------------------------------------

    #[Test]
    public function enrichBatchUsesLocaleInSparql(): void
    {
        $client = $this->createMock(WikidataClientInterface::class);
        $client->expects($this->once())
            ->method('query')
            ->with($this->stringContains('"de,en"'))
            ->willReturn([]);

        $enricher = new WikidataEnricher($client);
        $enricher->enrichBatch(['Q1'], 'de');
    }

    #[Test]
    public function enrichBatchUsesFirstTwoCharsOfLocale(): void
    {
        $client = $this->createMock(WikidataClientInterface::class);
        $client->expects($this->once())
            ->method('query')
            ->with($this->stringContains('"fr,en"'))
            ->willReturn([]);

        $enricher = new WikidataEnricher($client);
        $enricher->enrichBatch(['Q1'], 'fr-FR');
    }

    // -------------------------------------------------------------------------
    // enrichBatch() — no-overwrite merge
    // -------------------------------------------------------------------------

    #[Test]
    public function enrichBatchDoesNotOverwriteExistingFieldsWhenMerged(): void
    {
        $bindings = [
            [
                'item' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q999'],
                'itemLabel' => ['type' => 'literal', 'value' => 'Wikidata Label'],
                'itemDescription' => ['type' => 'literal', 'value' => 'Wikidata description'],
                'openingHours' => ['type' => 'literal', 'value' => 'Mo-Fr 09:00-18:00'],
            ],
        ];

        $client = $this->createMock(WikidataClientInterface::class);
        $client->method('query')->willReturn($bindings);

        $enricher = new WikidataEnricher($client);
        $enrichments = $enricher->enrichBatch(['Q999'], 'en');

        $existing = [
            'name' => 'Local Name',
            'openingHours' => 'Sa-Su 10:00-20:00',
            'wikidataId' => 'Q999',
        ];

        // Simulate the merge strategy used in handlers: array_merge($wikidata, $candidate)
        // The candidate (right side) wins for all existing fields
        $merged = array_merge($enrichments['Q999'], $existing);

        $this->assertSame('Local Name', $merged['name']);
        $this->assertSame('Sa-Su 10:00-20:00', $merged['openingHours'], 'Existing openingHours must not be overwritten');
        $this->assertSame('Wikidata Label', $merged['label'], 'Wikidata-only field is still present');
    }

    // -------------------------------------------------------------------------
    // enrichBatch() — client error returns empty
    // -------------------------------------------------------------------------

    #[Test]
    public function enrichBatchReturnsEmptyOnClientError(): void
    {
        $client = $this->createMock(WikidataClientInterface::class);
        $client->method('query')->willReturn([]);

        $enricher = new WikidataEnricher($client);

        $result = $enricher->enrichBatch(['Q1', 'Q2'], 'en');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // enrichBatch() — invalid item URI is skipped
    // -------------------------------------------------------------------------

    #[Test]
    public function enrichBatchSkipsBindingWithInvalidItemUri(): void
    {
        $bindings = [
            [
                'item' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/P31'],
                'itemLabel' => ['type' => 'literal', 'value' => 'Some property'],
            ],
        ];

        $client = $this->createMock(WikidataClientInterface::class);
        $client->method('query')->willReturn($bindings);

        $enricher = new WikidataEnricher($client);

        $result = $enricher->enrichBatch(['Q1'], 'en');

        $this->assertSame([], $result);
    }
}
