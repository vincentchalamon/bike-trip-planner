<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mercure;

use App\Mercure\MercureEventType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the PHP publisher and the shared TypeScript contract on the same list of events.
 *
 * `core/mercure.ts` carries compile-time guards for everything it can see: that the union
 * is exhaustive, that every event is classified data or signal, and that no `data` payload
 * publishes a field no GET returns (ADR-065). None of that can see the emitting side — a
 * case added to MercureEventType and published would break nothing, and its payload would
 * never face the coverage check. This closes that side.
 *
 * Same shape as {@see \App\Tests\Unit\AlertDocumentationTest}: a scan and a diff both ways,
 * no hand-kept map.
 */
final class MercureEventContractTest extends TestCase
{
    /** Entries of the `MERCURE_EVENT_TYPES` array literal. */
    private const string WIRE_TYPE_PATTERN = '/^\s*"([a-z_]+)",$/m';

    /** Keys of the `MERCURE_EVENT_KIND` record. */
    private const string CLASSIFICATION_PATTERN = '/^\s*([a-z_]+): "(data|signal)",$/m';

    #[Test]
    public function everyPublishedEventTypeExistsInTheSharedContract(): void
    {
        $missing = array_diff($this->phpEventValues(), $this->wireEventTypes());

        self::assertSame(
            [],
            array_values($missing),
            'MercureEventType declares events that core/mercure.ts does not know about; both clients would ignore them.',
        );
    }

    #[Test]
    public function theSharedContractDeclaresNoEventThePublisherCannotSend(): void
    {
        $extra = array_diff($this->wireEventTypes(), $this->phpEventValues());

        self::assertSame(
            [],
            array_values($extra),
            'core/mercure.ts declares events no MercureEventType case can publish.',
        );
    }

    #[Test]
    public function everyEventIsClassifiedAsDataOrSignal(): void
    {
        $unclassified = array_diff($this->phpEventValues(), $this->classifiedEventTypes());

        self::assertSame(
            [],
            array_values($unclassified),
            'MERCURE_EVENT_KIND leaves events unclassified, so their payloads escape the coverage check (ADR-065).',
        );
    }

    /**
     * @return list<string>
     */
    private function phpEventValues(): array
    {
        $values = array_map(static fn (MercureEventType $case): string => $case->value, MercureEventType::cases());
        sort($values);

        return $values;
    }

    /**
     * @return list<string>
     */
    private function wireEventTypes(): array
    {
        preg_match_all(self::WIRE_TYPE_PATTERN, $this->mercureContract(), $matches);

        $types = $matches[1];
        self::assertNotSame([], $types, 'No event type found in core/mercure.ts — the scan is broken, not the code.');

        sort($types);

        return $types;
    }

    /**
     * @return list<string>
     */
    private function classifiedEventTypes(): array
    {
        preg_match_all(self::CLASSIFICATION_PATTERN, $this->mercureContract(), $matches);

        $types = $matches[1];
        self::assertNotSame([], $types, 'No MERCURE_EVENT_KIND entry found — the scan is broken, not the code.');

        sort($types);

        return $types;
    }

    private function mercureContract(): string
    {
        // api/tests/Unit/Mercure -> repository root.
        $path = \dirname(__DIR__, 4).'/core/mercure.ts';

        self::assertFileExists($path, 'core/mercure.ts not found at project root.');

        return (string) file_get_contents($path);
    }
}
