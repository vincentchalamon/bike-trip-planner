<?php

declare(strict_types=1);

namespace Provisioner;

use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Reads back the per-table promotion counts both importers write to
 * {@see ZonePromotion::REPORT_TABLE}, so opening a zone reports what it actually did:
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
    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory psql process factory; shared with the caller so commands are captured in tests
     */
    public function __construct(
        ?\Closure $processFactory = null,
        private float $timeoutSeconds = 60.0,
    ) {
        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
    }

    /**
     * @return list<array{source: string, table: string, candidates: int, inserted: int}> empty when the report cannot be read
     */
    public function forZone(string $zoneSlug, string $workDir): array
    {
        $path = $workDir.'/promotion-report.tsv';
        $sql = \sprintf(
            "\\copy (SELECT source, table_name, candidates, inserted FROM %s WHERE zone = '%s' ORDER BY source, table_name) TO '%s'",
            ZonePromotion::REPORT_TABLE,
            str_replace("'", "''", $zoneSlug),
            $path,
        );

        $process = ($this->processFactory)(['psql', '-v', 'ON_ERROR_STOP=1', '-c', $sql]);
        $process->setTimeout($this->timeoutSeconds);

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
