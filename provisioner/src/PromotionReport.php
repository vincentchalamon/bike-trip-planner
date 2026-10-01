<?php

declare(strict_types=1);

namespace Provisioner;

use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Reads back the per-table promotion counts both importers write to
 * {@see PromotionReportTable}, so opening a zone reports what it actually did:
 * how many rows the source offered and how many of them were new.
 *
 * That report is not decoration. ADR-049 names it as the cure for the model's one blind
 * spot — a promotion that inserts nothing looks exactly like a promotion that worked,
 * and "0 new entries" is precisely the proof that re-opening an unchanged zone is cheap.
 *
 * Exported through `\copy ... TO <file>` and parsed here, the idiom the enrichment pass
 * already uses to get data out of psql; a failure to read the report never fails the run
 * that produced it.
 */
final readonly class PromotionReport
{
    private ProcessRunner $processes;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory psql process factory; shared with the caller so commands are captured in tests
     */
    public function __construct(
        ?\Closure $processFactory = null,
        private float $timeoutSeconds = 60.0,
    ) {
        $this->processes = new ProcessRunner($processFactory, $this->timeoutSeconds);
    }

    /**
     * Only the rows promoted since `$since`, the start of the run being reported. The table
     * keeps one row per (source, zone, table), overwritten by each promotion, so a source that
     * this run skipped or failed still has the figures of its last successful run there, and
     * reporting them would present an old promotion as today's.
     *
     * @return list<array{source: string, table: string, candidates: int, inserted: int}> empty when the report cannot be read
     */
    public function forZone(string $zoneSlug, string $workDir, \DateTimeImmutable $since): array
    {
        $path = $workDir.'/promotion-report.tsv';
        $sql = \sprintf(
            "\\copy (SELECT source, table_name, candidates, inserted FROM %s WHERE zone = %s AND promoted_at >= %s::timestamptz ORDER BY source, table_name) TO '%s'",
            PromotionReportTable::NAME,
            Sql::literal($zoneSlug),
            Sql::literal($since->format(\DateTimeInterface::RFC3339_EXTENDED)),
            $path,
        );

        $process = $this->processes->process(['psql', '-v', 'ON_ERROR_STOP=1', '-c', $sql]);

        try {
            $process->run();
        } catch (ProcessExceptionInterface) {
            return [];
        }

        if (!$process->isSuccessful() || !is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            return [];
        }

        $rows = [];
        foreach (explode("\n", $contents) as $line) {
            $fields = explode("\t", trim($line));
            if (4 !== \count($fields) || '' === $fields[0]) {
                continue;
            }

            $rows[] = [
                'source' => $fields[0],
                'table' => $fields[1],
                'candidates' => (int) $fields[2],
                'inserted' => (int) $fields[3],
            ];
        }

        return $rows;
    }
}
