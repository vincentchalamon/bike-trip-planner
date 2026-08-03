<?php

declare(strict_types=1);

namespace App\Tests\Unit\Format;

use App\Format\OsmContactTags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OsmContactTagsTest extends TestCase
{
    #[Test]
    public function phoneReadsThePlainTag(): void
    {
        self::assertSame('+33 1 23 45 67 89', OsmContactTags::phone(['phone' => '+33 1 23 45 67 89']));
    }

    #[Test]
    public function phoneFallsBackToTheContactNamespace(): void
    {
        self::assertSame('0466378200', OsmContactTags::phone(['contact:phone' => '0466378200']));
    }

    #[Test]
    public function phonePrefersThePlainTagOverTheContactOne(): void
    {
        self::assertSame('first', OsmContactTags::phone(['phone' => 'first', 'contact:phone' => 'second']));
    }

    #[Test]
    public function phoneTrimsTheValue(): void
    {
        self::assertSame('0102030405', OsmContactTags::phone(['phone' => "  0102030405\n"]));
    }

    /**
     * A whitespace-only tag is as unusable as an absent one, and a non-string
     * value can reach us from the raw Overpass JSON the in-ride path parses.
     *
     * @param array<string, mixed> $tags
     */
    #[Test]
    #[DataProvider('unusablePhoneTags')]
    public function phoneReturnsNullForAnUnusableValue(array $tags): void
    {
        self::assertNull(OsmContactTags::phone($tags));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unusablePhoneTags(): iterable
    {
        yield 'no contact tag' => [['name' => 'Hotel du Nord']];
        yield 'empty string' => [['phone' => '']];
        yield 'blank string' => [['phone' => '   ']];
        yield 'non-string value' => [['phone' => 33123456789]];
        yield 'blank plain tag with no fallback' => [['phone' => ' ', 'contact:phone' => '']];
    }

    #[Test]
    public function phoneSkipsABlankPlainTagInFavourOfTheContactOne(): void
    {
        self::assertSame('0102030405', OsmContactTags::phone(['phone' => '  ', 'contact:phone' => '0102030405']));
    }

    /**
     * @param array<string, mixed> $tags
     */
    #[Test]
    #[DataProvider('websiteCascade')]
    public function websiteWalksTheFourSpellings(array $tags, ?string $expected): void
    {
        self::assertSame($expected, OsmContactTags::website($tags));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string}>
     */
    public static function websiteCascade(): iterable
    {
        yield 'website' => [['website' => 'https://a.example'], 'https://a.example'];
        yield 'contact:website' => [['contact:website' => 'https://b.example'], 'https://b.example'];
        yield 'url' => [['url' => 'https://c.example'], 'https://c.example'];
        yield 'contact:url' => [['contact:url' => 'https://d.example'], 'https://d.example'];
        yield 'website wins over the rest' => [
            ['contact:url' => 'https://d.example', 'website' => 'https://a.example'],
            'https://a.example',
        ];
        // Usability, not mere presence, drives the cascade: free text in an earlier
        // key must not shadow a real URL in a later one.
        yield 'free text does not shadow a usable later key' => [
            ['website' => 'nous contacter', 'contact:url' => 'https://d.example'],
            'https://d.example',
        ];
        yield 'an e-mail is not a website' => [['website' => 'contact@gite.fr'], null];
        yield 'a mailto: scheme is rejected' => [['website' => 'mailto:contact@gite.fr'], null];
        yield 'a schemeless value is absolutised' => [['website' => 'www.gite.fr'], 'https://www.gite.fr'];
        yield 'nothing at all' => [['name' => 'Gîte'], null];
    }
}
