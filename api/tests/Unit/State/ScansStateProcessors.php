<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

/**
 * Finds the processors that honour a rule, by reading the source rather than listing them.
 *
 * The coverage tests that use this all have the same shape: a flag on an operation declares a
 * rule, some code applies it, and the two are free to drift apart because nothing links them.
 * Scanning is what links them — a hand-maintained list would drift in exactly the way it exists
 * to prevent.
 *
 * Delegation counts, and has to. An MCP tool cannot reuse its HTTP twin's processor outright:
 * a `tools/call` result is one JSON document, so "202 with a Location header" says nothing there
 * and the answer has to be rebuilt. The wrapper that rebuilds it must not rebuild the rule as
 * well, and wiring it to the processor that applies the rule is how that stays true. Reading the
 * wiring here is how this keeps saying something about it, instead of being widened until it
 * says nothing.
 */
trait ScansStateProcessors
{
    /**
     * Fully-qualified processors whose source contains any of the needles, plus those wired to
     * one that does.
     *
     * @param list<string> $needles
     *
     * @return list<string>
     */
    private function processorsContaining(array $needles): array
    {
        $sources = [];
        foreach (['/../../../src/State/*Processor.php', '/../../../src/State/Mcp/*Processor.php'] as $pattern) {
            foreach (glob(__DIR__.$pattern) ?: [] as $file) {
                $source = file_get_contents($file);
                if (false === $source) {
                    continue;
                }

                $namespace = str_contains($file, '/Mcp/') ? 'App\\State\\Mcp\\' : 'App\\State\\';
                $sources[$namespace.basename($file, '.php')] = $source;
            }
        }

        $direct = [];
        foreach ($sources as $class => $source) {
            foreach ($needles as $needle) {
                if (str_contains($source, $needle)) {
                    $direct[] = $class;
                    break;
                }
            }
        }

        $found = $direct;
        foreach ($sources as $class => $source) {
            if (\in_array($class, $found, true)) {
                continue;
            }

            foreach ($direct as $applying) {
                if (str_contains($source, substr((string) strrchr($applying, '\\'), 1).'::class')) {
                    $found[] = $class;
                    break;
                }
            }
        }

        sort($found);

        return $found;
    }
}
