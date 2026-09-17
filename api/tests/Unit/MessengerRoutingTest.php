<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\ComputationName;
use App\State\AnalyzeTripProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the asynchronous contract of the computation pipeline.
 *
 * A message class with no entry in the Messenger routing table is **not** an
 * error: Symfony silently handles it on the default bus, i.e. synchronously,
 * inline in whichever process dispatched it. For this pipeline that is a bug
 * with three compounding effects, all observed on CheckFerries/CheckFords:
 *
 *   1. {@see \App\Service\TripAnalysisDispatcher} is called from
 *      {@see \App\MessageHandler\GenerateStagesHandler}, so a handler throwing
 *      inline fails the *structural* STAGES computation (ADR-043) — the trip
 *      never reaches `ready` — and each Messenger retry re-dispatches the whole
 *      enrichment fan-out;
 *   2. it is also called from HTTP processors declaring 202, which then block on
 *      the inline work and answer 500 instead, leaving the messages dispatched
 *      after it never dispatched at all;
 *   3. a synchronous failure raises no WorkerMessageFailedEvent, so
 *      {@see \App\EventListener\ComputationFailureSubscriber} never marks the
 *      computation `failed` and the completion gate can no longer close.
 *
 * Hence the invariant asserted here: every App\Message class is routed. There is
 * no ignore list — a message that must run synchronously would need this test
 * changed deliberately, which is the point.
 */
final class MessengerRoutingTest extends TestCase
{
    /** Matches `SomeMessage::class => 'transport',` rows of the routing table. */
    private const string ROUTING_PATTERN = '/^\s*([A-Za-z_][A-Za-z0-9_]*)::class\s*=>\s*\'([a-z_]+)\',/m';

    #[Test]
    public function everyMessageIsRoutedToATransport(): void
    {
        $unrouted = array_values(array_diff($this->declaredMessages(), array_keys($this->routedMessages())));

        self::assertSame(
            [],
            $unrouted,
            \sprintf(
                'Message class(es) %s have no entry in config/packages/messenger.php. They are therefore handled '.
                'SYNCHRONOUSLY, inline in the dispatching process — which for this pipeline breaks the 202 contract '.
                'and can prevent the completion gate from closing. Route them to the "async" transport.',
                implode(', ', $unrouted),
            ),
        );
    }

    #[Test]
    public function everyRoutedMessageStillExists(): void
    {
        $stale = array_values(array_diff(array_keys($this->routedMessages()), $this->declaredMessages()));

        self::assertSame(
            [],
            $stale,
            \sprintf(
                'config/packages/messenger.php routes %s, which no longer exist(s) in api/src/Message. '.
                'Remove the stale routing entry and its `use` statement.',
                implode(', ', $stale),
            ),
        );
    }

    #[Test]
    public function theAnalysisResetCoversEveryEnrichmentComputation(): void
    {
        // POST /trips/{id}/analyze resets then re-dispatches the enrichment pipeline.
        // A computation that is re-dispatched but never reset keeps its previous
        // status while it runs again, so the status map lies for the duration —
        // and a second run that fails flips `done` straight to `failed`.
        $expected = array_values(array_filter(
            ComputationName::pipeline(),
            static fn (ComputationName $c): bool => ComputationName::ROUTE !== $c && ComputationName::STAGES !== $c,
        ));

        /** @var list<ComputationName> $actual */
        $actual = new \ReflectionClass(AnalyzeTripProcessor::class)->getConstant('ANALYSIS_COMPUTATIONS');

        sort($expected);
        sort($actual);

        self::assertSame(
            array_map(static fn (ComputationName $c): string => $c->value, $expected),
            array_map(static fn (ComputationName $c): string => $c->value, $actual),
            'AnalyzeTripProcessor::ANALYSIS_COMPUTATIONS drifted from ComputationName::pipeline(): the reset/re-dispatch '.
            'cycle of POST /trips/{id}/analyze must cover every enrichment computation, i.e. the whole pipeline except '.
            'the structural ROUTE and STAGES steps (ADR-043).',
        );
    }

    /**
     * Short class names of every message declared in api/src/Message.
     *
     * @return list<string>
     */
    private function declaredMessages(): array
    {
        $dir = \dirname(__DIR__, 2).'/src/Message';

        self::assertDirectoryExists($dir, 'api/src/Message not found.');

        $names = [];
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $names[] = basename($file, '.php');
        }

        self::assertNotSame([], $names, 'No message class found in api/src/Message — the scan is broken, not the code.');

        sort($names);

        return $names;
    }

    /**
     * Short class name => transport name, read back from the routing table.
     *
     * @return array<string, string>
     */
    private function routedMessages(): array
    {
        $configPath = \dirname(__DIR__, 2).'/config/packages/messenger.php';

        self::assertFileExists($configPath, 'config/packages/messenger.php not found.');

        preg_match_all(self::ROUTING_PATTERN, (string) file_get_contents($configPath), $matches, \PREG_SET_ORDER);

        self::assertNotSame([], $matches, 'No routing entry found in config/packages/messenger.php — the config shape changed.');

        $routing = [];
        foreach ($matches as [, $class, $transport]) {
            $routing[$class] = $transport;
        }

        return $routing;
    }
}
