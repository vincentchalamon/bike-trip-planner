<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A `VALUES` constant hand-mirrors its enum's cases, because an attribute argument (the
 * OpenAPI `enum` of an `ApiProperty`) cannot call `cases()`. A case added without its mirror
 * would leave the published schema, and the TypeScript union generated from it, one value
 * short. Every backed enum in `src/Enum` that declares `VALUES` is found by scanning, so a new
 * one is guarded without being listed here.
 */
final class EnumValuesMirrorTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<\BackedEnum>}>
     */
    public static function enumsWithValues(): iterable
    {
        $files = glob(__DIR__.'/../../../src/Enum/*.php');
        self::assertIsArray($files);

        foreach ($files as $file) {
            $class = 'App\\Enum\\'.basename($file, '.php');
            if (!enum_exists($class) || !is_subclass_of($class, \BackedEnum::class)) {
                continue;
            }

            if ((new \ReflectionClass($class))->hasConstant('VALUES')) {
                yield $class => [$class];
            }
        }
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    #[Test]
    #[DataProvider('enumsWithValues')]
    public function valuesMirrorTheCasesInOrder(string $enum): void
    {
        self::assertSame(
            array_map(static fn (\BackedEnum $case): int|string => $case->value, $enum::cases()),
            (new \ReflectionClassConstant($enum, 'VALUES'))->getValue(),
            \sprintf('%s::VALUES drifted from its cases.', $enum),
        );
    }

    #[Test]
    public function theScanFindsTheKnownMirrors(): void
    {
        $found = array_keys(iterator_to_array(self::enumsWithValues()));

        foreach (['AlertCode', 'AlertGroup', 'AlertParameterFormat', 'ComputationStatus', 'WeatherAvailability'] as $name) {
            self::assertContains('App\\Enum\\'.$name, $found);
        }
    }
}
