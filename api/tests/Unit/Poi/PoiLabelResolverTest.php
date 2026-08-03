<?php

declare(strict_types=1);

namespace App\Tests\Unit\Poi;

use App\Poi\PoiLabelResolver;
use App\Tests\Unit\AlertMessageTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PoiLabelResolverTest extends TestCase
{
    use AlertMessageTestTrait;

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'french resupply' => ['fr', 'supermarket', 'supermarché', 'Supermarché'];
        yield 'english resupply' => ['en', 'supermarket', 'supermarket', 'Supermarket'];
        yield 'french cultural' => ['fr', 'castle', 'château', 'Château'];
        yield 'french underscored' => ['fr', 'archaeological_site', 'site archéologique', 'Site archéologique'];
        yield 'french unmapped falls back' => ['fr', 'obelisk', 'site remarquable', 'Site remarquable'];
        yield 'english unmapped falls back' => ['en', 'obelisk', 'point of interest', 'Point of interest'];
    }

    #[DataProvider('labelProvider')]
    #[Test]
    public function resolvesTheLocalisedLabelAndItsDisplayForm(string $locale, string $category, string $label, string $displayName): void
    {
        $resolver = new PoiLabelResolver($this->createAlertTranslator());

        self::assertSame($label, $resolver->label($category, $locale));
        self::assertSame($displayName, $resolver->displayName($category, $locale));
    }

    #[Test]
    public function neverReturnsTheRawCategorySlug(): void
    {
        $resolver = new PoiLabelResolver($this->createAlertTranslator());

        self::assertSame('boulangerie', $resolver->label('bakery', 'fr'));
        self::assertSame('restauration rapide', $resolver->label('fast_food', 'fr'));
    }
}
