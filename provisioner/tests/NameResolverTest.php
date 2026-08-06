<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\NameResolver;

final class NameResolverTest extends TestCase
{
    private NameResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new NameResolver();
    }

    #[Test]
    public function usesTheOperatorWhenThereIsNoName(): void
    {
        // The one tag key #878 found any volume behind, and the acceptance criterion of
        // #884: an OSM accommodation with no name but an exploitable operator is imported
        // with a readable label.
        $resolved = $this->resolver->resolve('camp_site', ['operator' => 'Commune de Jongieux']);

        self::assertSame('Commune de Jongieux', $resolved['name']);
        self::assertSame('operator', $resolved['via']);
    }

    #[Test]
    public function fallsBackToTheBrandAfterTheOperator(): void
    {
        self::assertSame('brand', $this->resolver->resolve('hotel', ['brand' => 'Ibis Budget'])['via']);

        // Operator wins when both are present: it names who runs this place, the brand names
        // a chain that may cover thousands.
        $both = $this->resolver->resolve('hotel', ['operator' => 'Hoteliers du Nord', 'brand' => 'Ibis Budget']);
        self::assertSame('Hoteliers du Nord', $both['name']);
    }

    #[Test]
    public function prefersTheWikidataLabelOverTheOperator(): void
    {
        // A Wikidata label is the place's own name; `operator` is whoever runs it. Both cost
        // zero network calls by this point (the Wikidata pass has already filled its cache),
        // so quality decides the order rather than cost.
        $resolved = $this->resolver->resolve('hotel', ['operator' => 'Commune de Sarlat'], 'Hotel de la Madeleine');

        self::assertSame('Hotel de la Madeleine', $resolved['name']);
        self::assertSame('wikidata', $resolved['via']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function notANameProvider(): iterable
    {
        // `yes` says a feature is operated, not by whom. The carriers are what #878 found
        // behind 184 of the 199 `operator` values on `shelter`.
        yield 'the yes tag' => ['yes'];
        yield 'a transport operator' => ['Transdev'];
        yield 'a dotted transport operator' => ['S.N.C.F.'];
        yield 'a lowercase carrier' => ['jcdecaux'];
        yield 'too short to identify anything' => ['AB'];
        yield 'blank' => ['   '];
    }

    #[Test]
    #[DataProvider('notANameProvider')]
    public function rejectsValuesThatAreATagRatherThanAName(string $value): void
    {
        $resolved = $this->resolver->resolve('camp_site', ['operator' => $value]);

        self::assertNull($resolved['name']);
        self::assertSame('no_usable_name_source', $resolved['reason']);
    }

    #[Test]
    public function givesUpWithAMotiveWhenNothingIsExploitable(): void
    {
        // The gate needs the motive: a rejection with no reason is invisible in the opening
        // report, which is the one blind spot ADR-049 names for this model.
        $resolved = $this->resolver->resolve('guest_house', ['tourism' => 'guest_house', 'building' => 'yes']);

        self::assertNull($resolved['name']);
        self::assertNull($resolved['via']);
        self::assertSame('no_usable_name_source', $resolved['reason']);
    }

    #[Test]
    public function neverResolvesShelters(): void
    {
        // Exempt from the gate (#878: `shelter_type`, not the name, separates a refuge from
        // street furniture), so resolving one would only pin a bus company's name onto
        // street furniture.
        $resolved = $this->resolver->resolve('shelter', ['operator' => 'Transdev'], 'Abri de la gare');

        self::assertNull($resolved['name']);
        self::assertSame('shelter_exempt', $resolved['reason']);
    }

    #[Test]
    public function qualifiesAGenericNameWithTheOfflineLocality(): void
    {
        // What turns an ambiguous "Camping municipal" into something a rider can tell apart,
        // using the commune boundaries imported by #880 — no network call.
        $resolved = $this->resolver->resolve('camp_site', ['operator' => 'Camping municipal'], null, 'Sarlat');

        self::assertSame('Camping municipal — Sarlat', $resolved['name']);
    }

    #[Test]
    public function doesNotRepeatALocalityAlreadyInTheName(): void
    {
        $resolved = $this->resolver->resolve('camp_site', ['operator' => 'Camping de Sarlat'], null, 'Sarlat');

        self::assertSame('Camping de Sarlat', $resolved['name']);
    }

    #[Test]
    public function aLocalityAloneNeverProducesAName(): void
    {
        // Otherwise the gate could never reject anything, and the 631 rows #878 measured as
        // unrecoverable would all be imported under a locality that identifies nothing. The
        // locality qualifies a name; it is not a name.
        $resolved = $this->resolver->resolve('camp_site', [], null, 'Sarlat');

        self::assertNull($resolved['name']);
        self::assertSame('no_usable_name_source', $resolved['reason']);
    }

    #[Test]
    public function versionIsAnIntegerCallersCanCompare(): void
    {
        // Stored per entry in the cache; a bump is what makes the next opening retry the
        // rows this version rejected.
        self::assertGreaterThan(0, NameResolver::VERSION);
    }
}
