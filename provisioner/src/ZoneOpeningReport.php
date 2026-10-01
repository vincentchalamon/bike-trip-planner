<?php

declare(strict_types=1);

namespace Provisioner;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What opening a zone did, rendered for the operator: what the completeness gate accepted
 * and refused, then what each source offered and what was actually new.
 */
final readonly class ZoneOpeningReport
{
    /**
     * @param string $zonesDir root of the per-zone report directory (`<zonesDir>/<zone>/rejected.tsv`)
     * @param string $workDir  scratch directory the promotion report is exported to
     */
    public function __construct(
        private ProvisionerLog $log,
        private PromotionReport $promotionReport,
        private string $zonesDir,
        private string $workDir,
    ) {
    }

    /**
     * What the completeness gate accepted and refused, and where to act on the refusals.
     *
     * The diagnostic line matters more than the numbers. A large `rejected.tsv` is **not** a
     * signal that more human work is needed — it is a signal that the resolvers are bad, the
     * tag projection first among them. Saying so here keeps that reading from being buried
     * under a long file, which is exactly what #886 asks for.
     *
     * @param array{resolved?: int, rejected?: int, matched?: int, ambiguous?: int, reasons?: array<string, int>} $gate
     */
    public function gate(SymfonyStyle $io, string $zoneSlug, array $gate): void
    {
        $resolved = $gate['resolved'] ?? 0;
        $rejected = $gate['rejected'] ?? 0;
        $matched = $gate['matched'] ?? 0;
        $ambiguous = $gate['ambiguous'] ?? 0;

        if (0 === $resolved && 0 === $rejected) {
            return;
        }

        $io->section('Completeness gate');
        $io->writeln(\sprintf('  %d name(s) resolved, of which %d from the curated flux.', $resolved, $matched));
        $io->writeln(\sprintf('  %d entry(ies) refused%s.', $rejected, $ambiguous > 0 ? \sprintf(', %d of them as ambiguous matches', $ambiguous) : ''));

        foreach ($gate['reasons'] ?? [] as $reason => $count) {
            $io->writeln(\sprintf('    - %s: %d', $reason, $count));
        }

        if ($rejected > 0) {
            $io->writeln(\sprintf('  Ranked by distance to the nearest cycle route in %s/%s/rejected.tsv.', $this->zonesDir, $zoneSlug));
            $this->log->line('INFO', \sprintf('zone %s gate -> %d resolved, %d refused', $zoneSlug, $resolved, $rejected));
        }

        if ($rejected > $resolved) {
            $io->warning('More entries were refused than resolved. Read that as the resolvers being weak, not as a backlog of manual work: the tag projection should absorb most of the volume, and a long rejected.tsv means it did not.');
        }
    }

    /**
     * What each source offered and what was actually new. "0 new entries" on a re-open is
     * the evidence that the identity anti-join works, so it is stated rather than left to be
     * inferred from silence.
     *
     * @param \DateTimeImmutable $since start of the run: a source this run skipped or failed
     *                                  must not show the figures of an earlier promotion
     */
    public function promotion(SymfonyStyle $io, string $zoneSlug, \DateTimeImmutable $since): void
    {
        $rows = $this->promotionReport->forZone($zoneSlug, $this->workDir, $since);
        if ([] === $rows) {
            return;
        }

        $io->section(\sprintf('Zone opening report — %s', $zoneSlug));
        $io->table(
            ['source', 'table', 'candidates', 'new entries', 'already present'],
            array_map(
                static fn (array $row): array => [
                    $row['source'],
                    $row['table'],
                    (string) $row['candidates'],
                    (string) $row['inserted'],
                    (string) ($row['candidates'] - $row['inserted']),
                ],
                $rows,
            ),
        );

        $added = array_sum(array_column($rows, 'inserted'));
        $io->writeln(0 === $added
            ? '  0 new entries: the sources carry nothing this zone did not already hold.'
            : \sprintf('  %d new entries across %d tables.', $added, \count($rows)));
        $this->log->line('INFO', \sprintf('zone %s -> %d new entries', $zoneSlug, $added));
    }
}
