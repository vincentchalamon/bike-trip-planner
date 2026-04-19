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
        \assert(\is_array($fixture));
        \assert(isset($fixture['results']) && \is_array($fixture['results']));
        \assert(isset($fixture['results']['bindings']) && \is_array($fixture['results']['bindings']));
        $bindings = $fixture['results']['bindings'];

        $client = $this->createMock(WikidataClientInterface::class);
        $client->expects($this->once())
            ->method('query')
            ->willReturn($bindings);

        $enricher = new WikidataEnricher($client);

        $result = $enricher->enrichBatch(['Q12345', 'Q67890'], 'fr');

        $this->assertArrayHasKey('Q12345', $result);
        $versailles = $result['Q12345'];
        $this->assertArrayHasKey('label', $versailles);
        $this->assertArrayHasKey('description', $versailles);
        $this->assertArrayHasKey('imageUrl', $versailles);
        $this->assertArrayHasKey('website', $versailles);
        $this->assertArrayHasKey('openingHours', $versailles);
        $this->assertArrayHasKey('wikipediaUrl', $versailles);
        $this->assertSame('Château de Versailles', $versailles['label']);
        $this->assertSame('Palais royal situé à Versailles, France.', $versailles['description']);
        $this->assertStringContainsString('Versailles_Palace', $versailles['imageUrl']);
        $this->assertStringContainsString('width=400', $versailles['imageUrl']);
        $this->assertSame('https://www.chateauversailles.fr', $versailles['website']);
        $this->assertSame('Tu-Su 09:00-17:30', $versailles['openingHours']);
        $this->assertSame('https://fr.wikipedia.org/wiki/Château_de_Versailles', $versailles['wikipediaUrl']);

        $this->assertArrayHasKey('Q67890', $result);
        $eiffel = $result['Q67890'];
        $this->assertArrayHasKey('label', $eiffel);
        $this->assertSame('Tour Eiffel', $eiffel['label']);
        $this->assertArrayNotHasKey('website', $eiffel);
        $this->assertArrayNotHasKey('openingHours', $eiffel);
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

        $client = $this->createStub(WikidataClientInterface::class);
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

        $this->assertArrayHasKey('label', $merged);
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
        $client = $this->createStub(WikidataClientInterface::class);
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

        $client = $this->createStub(WikidataClientInterface::class);
        $client->method('query')->willReturn($bindings);

        $enricher = new WikidataEnricher($client);

        $result = $enricher->enrichBatch(['Q1'], 'en');

        $this->assertSame([], $result);
    }
}
