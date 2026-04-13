<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ensures every alert rule implemented in the codebase is documented in README.md.
 *
 * Source of truth: translations/alerts.en.yaml — every "alert.X.Y" key whose
 * second segment maps to an actual rule (not a fallback/label string) must have
 * a corresponding **Rule name** entry in the README alert-engine table.
 *
 * When you add a new alert key, add the namespace→README mapping to
 * ALERT_RULE_MAP below and add the corresponding row to README.md.
 */
final class AlertDocumentationTest extends TestCase
{
    /**
     * Maps translation-key namespaces (second segment of "alert.X.Y") to the
     * bold rule name used in the README alert-engine table.
     *
     * Keys without a user-visible alert row (fallback labels, weather codes…)
     * are listed in IGNORED_NAMESPACES instead.
     */
    private const array ALERT_RULE_MAP = [
        'elevation' => 'Elevation',
        'lunch' => 'Resupply',
        'continuity' => 'Continuity',
        'surface' => 'Surface',
        'traffic' => 'Traffic',
        'bike_shop' => 'Bike shops',
        'ebike_range' => 'E-bike range',
        'cemetery' => 'Water points',
        'calendar' => 'Calendar',
        'resupply' => 'Resupply',
        'steep_gradient' => 'Steep gradient',
        'wind' => 'Wind',
        'comfort' => 'Comfort',
        'accommodation' => 'Accommodation',
        'rest_day' => 'Rest day',
        'sunset' => 'Sunset',
        'cultural_poi' => 'Cultural POI',
        'railway_station' => 'Railway station',
        'health_service' => 'Health services',
        'border_crossing' => 'Border crossing',
    ];

    /**
     * Translation namespaces that are helper strings, not alert rows
     * (fallback labels, stage labels, weather descriptions…).
     */
    private const array IGNORED_NAMESPACES = ['fallback', 'label', 'sunday_nudge'];

    #[Test]
    public function everyAlertTranslationKeyIsDocumentedInReadme(): void
    {
        $readmePath = \dirname(__DIR__, 3).'/README.md';
        $translationsPath = \dirname(__DIR__, 2).'/translations/alerts.en.yaml';

        self::assertFileExists($readmePath, 'README.md not found at project root.');
        self::assertFileExists($translationsPath, 'Alert translation file not found.');

        $readme = (string) file_get_contents($readmePath);
        $yaml = (string) file_get_contents($translationsPath);

        // Extract all "alert.X.*" namespaces from the translation file
        preg_match_all('/^alert\.(\w+)\./m', $yaml, $matches);
        $namespaces = array_unique($matches[1]);

        foreach ($namespaces as $namespace) {
            if (\in_array($namespace, self::IGNORED_NAMESPACES, true)) {
                continue;
            }

            self::assertArrayHasKey(
                $namespace,
                self::ALERT_RULE_MAP,
                \sprintf(
                    'Alert namespace "alert.%s.*" is not mapped in ALERT_RULE_MAP. '.
                    'Add an entry to ALERT_RULE_MAP and a row to the README.md alert-engine table.',
                    $namespace,
                ),
            );

            $readmeBoldEntry = '**'.self::ALERT_RULE_MAP[$namespace].'**';

            self::assertStringContainsString(
                $readmeBoldEntry,
                $readme,
                \sprintf(
                    'Alert rule "%s" (translation namespace: alert.%s) is missing from README.md. '.
                    'Add a row to the alert-engine table.',
                    self::ALERT_RULE_MAP[$namespace],
                    $namespace,
                ),
            );
        }
    }
}
