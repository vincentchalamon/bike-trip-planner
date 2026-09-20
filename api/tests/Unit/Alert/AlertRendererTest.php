<?php

declare(strict_types=1);

namespace App\Tests\Unit\Alert;

use App\Enum\AlertParameterFormat;
use App\Tests\Unit\AlertMessageTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rendering the producers stopped doing (ADR-069).
 *
 * These assertions used to live in the analyzers, one per rule, against a stub translator.
 * They belong here now: one place decides the language and the number formatting, so one
 * place has to prove it.
 */
final class AlertRendererTest extends TestCase
{
    use AlertMessageTestTrait;

    /**
     * @param list<array<string, mixed>> $rendered
     */
    private static function messageOf(array $rendered, int $index = 0): string
    {
        $message = $rendered[$index]['message'];
        \assert(\is_string($message));

        return $message;
    }

    /**
     * @param list<array<string, mixed>> $rendered
     *
     * @return array<string, mixed>
     */
    private static function actionOf(array $rendered): array
    {
        $action = $rendered[0]['action'];
        \assert(\is_array($action));

        /* @var array<string, mixed> $action */
        return $action;
    }

    /**
     * The reason this PR exists: the same stored row, no recomputation, two languages.
     */
    #[Test]
    public function theSameStoredAlertRendersInEitherLanguage(): void
    {
        $stored = [[
            'code' => 'elevation_gain',
            'type' => 'warning',
            'messageKey' => 'alert.elevation.warning',
            'parameters' => ['%elevation%' => 1500],
        ]];

        $renderer = $this->createAlertRenderer();

        self::assertSame(
            'Significant elevation: 1500m D+ on this stage.',
            self::messageOf($renderer->render($stored, 1, 'en')),
        );
        self::assertSame(
            'Important dénivelé positif : 1500m D+ sur cette étape.',
            self::messageOf($renderer->render($stored, 1, 'fr')),
        );
    }

    /**
     * The formatting was baked in as much as the language was: a French reader must not get
     * "8.3", nor an English one "8,3".
     */
    #[DataProvider('localisedNumberProvider')]
    #[Test]
    public function rawParametersAreFormattedInTheReadersLocale(string $locale, string $expected): void
    {
        $rendered = $this->createAlertRenderer()->render([[
            'messageKey' => 'alert.steep_gradient.warning',
            'parameters' => ['%gradient%' => 8.25, '%distance%' => 2400.0],
            'parameterFormats' => [
                '%gradient%' => AlertParameterFormat::DECIMAL_ONE->value,
                '%distance%' => AlertParameterFormat::DISTANCE->value,
            ],
        ]], 1, $locale);

        self::assertSame($expected, self::messageOf($rendered));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localisedNumberProvider(): iterable
    {
        yield 'english' => ['en', 'Steep climb detected: 8.3% gradient over 2.4 km. Tough with a loaded bike.'];
        yield 'french' => ['fr', 'Montée raide détectée : 8,3% de pente sur 2,4 km. Difficile avec un vélo chargé.'];
    }

    /**
     * `dayNumber` is deliberately absent from the stored row (ADR-068), so the renderer is
     * the only thing that can put a stage number in the sentence — and it has to be the
     * current one, not the one that was current when the alert was computed.
     */
    #[Test]
    public function theStageNumberComesFromTheOwningStageAndNotFromTheRow(): void
    {
        $stored = [['messageKey' => 'alert.ferry.warning']];
        $renderer = $this->createAlertRenderer();

        self::assertStringContainsString('Stage 2 ', self::messageOf($renderer->render($stored, 2, 'en')));
        // The very same row, after an edit renumbered its stage.
        self::assertStringContainsString('Stage 7 ', self::messageOf($renderer->render($stored, 7, 'en')));
    }

    /**
     * A continuity gap sits on the earlier of the two stages it spans, so the pair is always
     * consecutive — which is why neither number is stored.
     */
    #[Test]
    public function aContinuityGapNamesTheStageItSitsOnAndTheNextOne(): void
    {
        $rendered = $this->createAlertRenderer()->render([[
            'messageKey' => 'alert.continuity.critical',
            'parameters' => ['%distance%' => 600.0],
            'parameterFormats' => ['%distance%' => AlertParameterFormat::DISTANCE_KM->value],
        ]], 3, 'en');

        self::assertSame('Discontinuity: 0.6 km between stage 3 and 4.', self::messageOf($rendered));
    }

    /**
     * Surface names and POI categories are translations inside a parameter, so storing them
     * rendered would have left a French word in an English sentence.
     */
    #[Test]
    public function parametersThatAreThemselvesTranslatedFollowTheReader(): void
    {
        $stored = [[
            'messageKey' => 'alert.surface.warning',
            'parameters' => ['%length%' => 600.0, '%surface%' => ['gravel', 'dirt']],
            'parameterFormats' => [
                '%length%' => AlertParameterFormat::DISTANCE->value,
                '%surface%' => AlertParameterFormat::SURFACE_LIST->value,
            ],
        ]];
        $renderer = $this->createAlertRenderer();

        self::assertStringContainsString('(gravel, dirt)', self::messageOf($renderer->render($stored, 1, 'en')));
        self::assertStringContainsString('(gravier, terre)', self::messageOf($renderer->render($stored, 1, 'fr')));
    }

    /**
     * An OSM value the catalogue has no entry for must not reach the rider as a raw tag.
     */
    #[Test]
    public function anUnknownSurfaceFallsBackInsteadOfLeakingTheTag(): void
    {
        $rendered = $this->createAlertRenderer()->render([[
            'messageKey' => 'alert.surface.warning',
            'parameters' => ['%length%' => 600.0, '%surface%' => ['moon_dust']],
            'parameterFormats' => [
                '%length%' => AlertParameterFormat::DISTANCE->value,
                '%surface%' => AlertParameterFormat::SURFACE_LIST->value,
            ],
        ]], 1, 'en');

        self::assertStringNotContainsString('moon_dust', self::messageOf($rendered));
    }

    /**
     * The action label travels as a key too, and is rendered next to the message rather than
     * by a second pass a caller could forget.
     */
    #[Test]
    public function theActionLabelIsRenderedAlongsideTheMessage(): void
    {
        $rendered = $this->createAlertRenderer()->render([[
            'messageKey' => 'alert.ferry.warning',
            'action' => [
                'kind' => 'navigate',
                'labelKey' => 'alert.ferry.action',
                'payload' => ['lat' => 1.0, 'lon' => 2.0],
            ],
        ]], 1, 'fr');

        self::assertSame('Traversée en ferry', self::actionOf($rendered)['label']);
        // The key survives, so a client that prefers to translate on its own still can.
        self::assertSame('alert.ferry.action', self::actionOf($rendered)['labelKey']);
    }

    /**
     * The per-group Mercure payloads are one flat list for the whole trip, so each entry
     * names its own stage.
     */
    #[Test]
    public function aFlatPublishedListTakesTheStageNumberFromEachEntry(): void
    {
        $rendered = $this->createAlertRenderer()->renderFlat([
            ['dayNumber' => 2, 'messageKey' => 'alert.ferry.warning'],
            ['dayNumber' => 5, 'messageKey' => 'alert.ferry.warning'],
        ], 'en');

        self::assertStringContainsString('Stage 2 ', self::messageOf($rendered));
        self::assertStringContainsString('Stage 5 ', self::messageOf($rendered, 1));
    }

    /**
     * An API client gets the number it can compute with, not a string it has to parse back.
     */
    #[Test]
    public function theRawParametersSurviveRendering(): void
    {
        $rendered = $this->createAlertRenderer()->render([[
            'messageKey' => 'alert.steep_gradient.warning',
            'parameters' => ['%gradient%' => 8.25, '%distance%' => 2400.0],
            'parameterFormats' => ['%distance%' => AlertParameterFormat::DISTANCE->value],
        ]], 1, 'en');

        self::assertSame(['%gradient%' => 8.25, '%distance%' => 2400.0], $rendered[0]['parameters']);
    }
}
