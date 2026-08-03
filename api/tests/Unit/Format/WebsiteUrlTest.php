<?php

declare(strict_types=1);

namespace App\Tests\Unit\Format;

use App\Format\WebsiteUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebsiteUrlTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function normalisedValues(): array
    {
        return [
            'already absolute' => ['https://gite-du-lac.test/chambres', 'https://gite-du-lac.test/chambres'],
            'http is kept' => ['http://gite-du-lac.test', 'http://gite-du-lac.test'],
            'schema-less host' => ['www.gite-du-lac.test', 'https://www.gite-du-lac.test'],
            'schema-less with path' => ['gite-du-lac.test/chambres', 'https://gite-du-lac.test/chambres'],
            'protocol relative' => ['//gite-du-lac.test/x', 'https://gite-du-lac.test/x'],
            'surrounding spaces' => ['  https://gite-du-lac.test  ', 'https://gite-du-lac.test'],
            'uppercase scheme and host' => ['HTTPS://Gite-Du-Lac.TEST/Chambres', 'https://gite-du-lac.test/Chambres'],
            'query and fragment kept' => ['gite-du-lac.test/x?a=1#b', 'https://gite-du-lac.test/x?a=1#b'],
            'port kept' => ['https://gite-du-lac.test:8443/x', 'https://gite-du-lac.test:8443/x'],
            'accented host' => ['gîte-du-lac.test', 'https://gîte-du-lac.test'],
        ];
    }

    #[Test]
    #[DataProvider('normalisedValues')]
    public function normalisesHandTypedValuesToAnAbsoluteUrl(string $raw, string $expected): void
    {
        self::assertSame($expected, WebsiteUrl::normalize($raw));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function unusableValues(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'blank' => ['   '],
            'free text' => ['nous contacter'],
            'bare word' => ['gite'],
            'e-mail address' => ['contact@gite.test'],
            'mailto scheme' => ['mailto:contact@gite.test'],
            'tel scheme' => ['tel:+33388000000'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,<script>'],
            'ftp scheme' => ['ftp://gite.test/x'],
            'credentials' => ['https://user:pass@gite.test'],
            'no dotted host' => ['http://localhost:8000'],
            'ip address' => ['http://169.254.169.254/latest'],
        ];
    }

    #[Test]
    #[DataProvider('unusableValues')]
    public function rejectsWhatIsNotAnOpenableWebsite(?string $raw): void
    {
        self::assertNull(WebsiteUrl::normalize($raw));
    }
}
