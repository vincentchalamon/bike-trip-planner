<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\AlertCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the alert-engine contract: one docs/alert-engine.md row per {@see AlertCode}
 * case, and every case actually emitted by the production code.
 *
 * The old version of this test keyed on the *namespace* of a translation key
 * ("alert.X.y" → X) and could only ever prove that a family of rules was
 * documented — `alert.surface.missing_data` sailed through it because another
 * `surface` variant was mapped. It now keys on the code, which is the rule's
 * real identity, so the three sets below must coincide exactly:
 *
 *   1. the cases declared by the AlertCode enum,
 *   2. the cases referenced by src/ (i.e. actually emitted),
 *   3. the codes documented in the docs/alert-engine.md table.
 *
 * When you add, change or remove an alert rule you must update BOTH
 * `App\Enum\AlertCode` and the docs/alert-engine.md table; there is no
 * hand-maintained map to keep in sync any more, and no ignore list.
 */
final class AlertDocumentationTest extends TestCase
{
    /** Matches the `Code` column of the docs/alert-engine.md table rows. */
    private const string DOC_ROW_PATTERN = '/^\|\s*\*\*[^|]+\*\*\s*\|\s*`([a-z0-9_]+)`\s*\|/m';

    /** Matches `AlertCode::SOME_CASE` anywhere in the production sources. */
    private const string EMISSION_PATTERN = '/\bAlertCode::([A-Z][A-Z0-9_]*)\b/';

    /**
     * Members of the enum that are constants, not cases: referencing them is not
     * an emission. Listed explicitly rather than filtered loosely, so a typo in a
     * real case name still trips the "referenced but not declared" guard.
     *
     * @var list<string>
     */
    private const array NON_CASE_MEMBERS = ['VALUES'];

    #[Test]
    public function everyEmittedAlertCodeIsDocumented(): void
    {
        $emitted = $this->emittedCodes();
        $documented = $this->documentedCodes();

        $missing = array_values(array_diff($emitted, $documented));

        self::assertSame(
            [],
            $missing,
            \sprintf(
                'Alert code(s) %s are emitted by api/src but have no row in the alert-engine table. '.
                'Add one row per code to the table in docs/alert-engine.md.',
                implode(', ', $missing),
            ),
        );
    }

    #[Test]
    public function everyDocumentedAlertCodeIsStillEmitted(): void
    {
        $emitted = $this->emittedCodes();
        $documented = $this->documentedCodes();

        $stale = array_values(array_diff($documented, $emitted));

        self::assertSame(
            [],
            $stale,
            \sprintf(
                'Alert code(s) %s are documented in the alert-engine table but no longer emitted by api/src. '.
                'Remove the stale row(s) from docs/alert-engine.md (and the case from App\Enum\AlertCode).',
                implode(', ', $stale),
            ),
        );
    }

    #[Test]
    public function theValuesConstantMirrorsTheCases(): void
    {
        // AlertCode::VALUES exists only because `#[ApiProperty(openapiContext: ...)]`
        // accepts constant expressions alone, so the OpenAPI enum cannot be computed
        // from cases(). Removing or renaming a case breaks compilation; adding one
        // silently would not, which is exactly what this asserts.
        self::assertSame(
            array_map(static fn (AlertCode $c): string => $c->value, AlertCode::cases()),
            AlertCode::VALUES,
            'AlertCode::VALUES drifted from the enum cases: the /trips/{id}/detail OpenAPI schema '.
            'would advertise a code list that no longer matches what the backend emits.',
        );
    }

    #[Test]
    public function everyAlertCodeCaseIsEmitted(): void
    {
        $declared = array_map(static fn (AlertCode $c): string => $c->value, AlertCode::cases());
        $unused = array_values(array_diff($declared, $this->emittedCodes()));

        self::assertSame(
            [],
            $unused,
            \sprintf(
                'AlertCode case(s) %s are declared but never emitted by api/src. Dead codes drift the documentation: '.
                'either wire them to a rule or drop them.',
                implode(', ', $unused),
            ),
        );
    }

    /**
     * Codes actually emitted, read back from the production sources.
     *
     * @return list<string>
     */
    private function emittedCodes(): array
    {
        $srcDir = \dirname(__DIR__, 2).'/src';
        $enumFile = $srcDir.'/Enum/AlertCode.php';

        self::assertDirectoryExists($srcDir, 'api/src not found.');

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir)),
            '/\.php$/',
        );

        $valueByCaseName = [];
        foreach (AlertCode::cases() as $case) {
            $valueByCaseName[$case->name] = $case->value;
        }

        $names = [];
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            // The enum declares the cases; referencing them there is not an emission.
            if ($file->getPathname() === $enumFile) {
                continue;
            }

            preg_match_all(self::EMISSION_PATTERN, (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $name) {
                if (\in_array($name, self::NON_CASE_MEMBERS, true)) {
                    continue;
                }

                self::assertArrayHasKey($name, $valueByCaseName, \sprintf('AlertCode::%s is referenced by api/src but not declared.', $name));
                $names[] = $valueByCaseName[$name];
            }
        }

        self::assertNotSame([], $names, 'No AlertCode reference found in api/src — the scan is broken, not the code.');

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * Codes documented in the docs/alert-engine.md table.
     *
     * @return list<string>
     */
    private function documentedCodes(): array
    {
        $docPath = \dirname(__DIR__, 3).'/docs/alert-engine.md';

        self::assertFileExists($docPath, 'docs/alert-engine.md not found at project root.');

        preg_match_all(self::DOC_ROW_PATTERN, (string) file_get_contents($docPath), $matches);

        $codes = $matches[1];

        self::assertNotSame([], $codes, 'No alert-engine row found in docs/alert-engine.md — the table format changed.');
        self::assertCount(
            \count(array_unique($codes)),
            $codes,
            'The docs/alert-engine.md table documents the same code twice; there must be exactly one row per code.',
        );

        sort($codes);

        return $codes;
    }
}
