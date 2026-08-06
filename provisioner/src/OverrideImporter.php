<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Imports an operator's `override.tsv` into the live reference tables (#886).
 *
 * The loop it closes: opening a zone writes `rejected.tsv`, ranked by distance to the
 * nearest signed cycle route; the operator edits the rows worth fixing into an
 * `override.tsv` and imports it here. There is **no correction table, no endpoint, no
 * authentication, no interface and no versioning** — the corrected rows go straight into
 * the live tables, and from then on the enrichment cache's negative entry keeps the
 * resolver from re-analysing them while the promotion's identity anti-join keeps the
 * import from touching them. Two mechanisms that already existed; this adds no third one.
 *
 * **The file is the operator's to keep.** Nothing here stores it, so a database rebuilt
 * from scratch loses every correction whose file was not kept. That is the price of "no
 * table, no versioning", and it is written down in
 * docs/runbooks/zone-opening-corrections.md rather than left to be discovered.
 *
 * A malformed file is refused whole, naming the line: the insert runs in one transaction,
 * so there is no such thing as a partially applied override.
 */
final readonly class OverrideImporter
{
    /**
     * Columns an operator must provide, in order. Deliberately the ones `rejected.tsv`
     * already emits (`source`, `source_id`, `category`, `lat`, `lon`) plus the values being
     * supplied, so the workflow is "delete the columns you do not need, add a name".
     *
     * @var list<string>
     */
    public const array COLUMNS = ['source', 'source_id', 'category', 'lat', 'lon', 'name'];

    /**
     * Optional trailing columns, filled when the operator has them to hand.
     *
     * @var list<string>
     */
    public const array OPTIONAL_COLUMNS = ['website', 'description', 'opening_hours'];

    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $processFactory;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory psql process factory; defaults to a real {@see Process}
     */
    public function __construct(
        ?\Closure $processFactory = null,
        private float $timeoutSeconds = 300.0,
    ) {
        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
    }

    /**
     * @return int the number of rows the file offered
     *
     * @throws ImportFailedException on a malformed file, before anything is inserted
     */
    public function import(string $path, string $zoneSlug): int
    {
        if (!is_file($path)) {
            throw new ImportFailedException(\sprintf('Override file "%s" does not exist.', $path));
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new ImportFailedException(\sprintf('Override file "%s" cannot be read.', $path));
        }

        // Parsed and validated in full before a single statement runs: a file the operator
        // got half right must leave the index exactly as it was.
        $rows = $this->parse($contents);
        if ([] === $rows) {
            throw new ImportFailedException(\sprintf('Override file "%s" holds no data row.', $path));
        }

        $this->psql($this->insertSql($rows, $zoneSlug), \sprintf('psql import override %s', $path));

        return \count($rows);
    }

    /**
     * @return list<array{source: string, source_id: string, category: string, lat: float, lon: float, name: string, website: ?string, description: ?string, opening_hours: ?string}>
     *
     * @throws ImportFailedException
     */
    private function parse(string $contents): array
    {
        $rows = [];
        $expected = \count(self::COLUMNS);
        $maximum = $expected + \count(self::OPTIONAL_COLUMNS);

        foreach (explode("\n", $contents) as $index => $line) {
            $line = rtrim($line, "\r");
            $number = $index + 1;

            if ('' === trim($line)) {
                continue;
            }

            $fields = explode("\t", $line);
            // The header `rejected.tsv` writes, so an operator can edit that file in place.
            if (1 === $number && ($fields[0] ?? '') === self::COLUMNS[0]) {
                continue;
            }

            if (\count($fields) < $expected || \count($fields) > $maximum) {
                throw new ImportFailedException(\sprintf('Override line %d has %d tab-separated fields; expected %d to %d (%s[, %s]).', $number, \count($fields), $expected, $maximum, implode(', ', self::COLUMNS), implode(', ', self::OPTIONAL_COLUMNS)));
            }

            $rows[] = $this->row($fields, $number);
        }

        return $rows;
    }

    /**
     * @param list<string> $fields
     *
     * @return array{source: string, source_id: string, category: string, lat: float, lon: float, name: string, website: ?string, description: ?string, opening_hours: ?string}
     *
     * @throws ImportFailedException
     */
    private function row(array $fields, int $number): array
    {
        $source = trim($fields[0]);
        if (!\in_array($source, ['osm', 'datatourisme'], true)) {
            throw new ImportFailedException(\sprintf('Override line %d has source "%s"; expected osm or datatourisme.', $number, $source));
        }

        $sourceId = trim($fields[1]);
        if ('osm' === $source && 1 !== preg_match('/^[NWR]\/\d+$/i', $sourceId)) {
            throw new ImportFailedException(\sprintf('Override line %d has OSM id "%s"; expected the N/123 form rejected.tsv emits.', $number, $sourceId));
        }

        if ('' === $sourceId) {
            throw new ImportFailedException(\sprintf('Override line %d has an empty source id.', $number));
        }

        $category = trim($fields[2]);
        if ('' === $category) {
            throw new ImportFailedException(\sprintf('Override line %d has an empty category.', $number));
        }

        if (!is_numeric($fields[3]) || !is_numeric($fields[4])) {
            throw new ImportFailedException(\sprintf('Override line %d has non-numeric coordinates ("%s", "%s").', $number, $fields[3], $fields[4]));
        }

        $lat = (float) $fields[3];
        $lon = (float) $fields[4];
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            throw new ImportFailedException(\sprintf('Override line %d has coordinates outside the world (%s, %s).', $number, $fields[3], $fields[4]));
        }

        // The whole point of the file: a row rejected for having no usable name must arrive
        // with one, or importing it would only reproduce the rejection.
        $name = trim($fields[5]);
        if ('' === $name) {
            throw new ImportFailedException(\sprintf('Override line %d has an empty name, which is the one value the gate refused it for.', $number));
        }

        return [
            'source' => $source,
            'source_id' => $sourceId,
            'category' => $category,
            'lat' => $lat,
            'lon' => $lon,
            'name' => $name,
            'website' => $this->optional($fields, 6),
            'description' => $this->optional($fields, 7),
            'opening_hours' => $this->optional($fields, 8),
        ];
    }

    /**
     * @param list<string> $fields
     */
    private function optional(array $fields, int $index): ?string
    {
        $value = trim($fields[$index] ?? '');

        return '' === $value ? null : $value;
    }

    /**
     * One transaction, and `ON CONFLICT DO NOTHING` on every row.
     *
     * Append-only holds here too (ADR-049 §5): an override adds what the gate refused, it
     * never rewrites a row already imported. An operator who wants to change a value that is
     * already live cannot do it with this file, and that is deliberate — the alternative is a
     * mechanism that can silently overwrite the sources.
     *
     * @param list<array{source: string, source_id: string, category: string, lat: float, lon: float, name: string, website: ?string, description: ?string, opening_hours: ?string}> $rows
     */
    private function insertSql(array $rows, string $zoneSlug): string
    {
        $osm = [];
        $tourism = [];

        foreach ($rows as $row) {
            if ('osm' === $row['source']) {
                [$type, $id] = explode('/', $row['source_id']);
                $osm[] = \sprintf(
                    '(%s, %d, %s, %s, %s, %s, %s, %s, now())',
                    ZonePromotion::literal(strtoupper($type)),
                    (int) $id,
                    ZonePromotion::literal($row['name']),
                    ZonePromotion::literal($row['category']),
                    $this->nullable($row['website']),
                    $this->nullable($row['opening_hours']),
                    $this->nullable($row['description']),
                    $this->point($row['lat'], $row['lon']),
                );
                continue;
            }

            $tourism[] = \sprintf(
                '(%s, %s, %s, %s, %s, %s, %s, now())',
                ZonePromotion::literal($row['source_id']),
                ZonePromotion::literal($row['name']),
                ZonePromotion::literal($row['category']),
                $this->nullable($row['website']),
                $this->nullable($row['opening_hours']),
                $this->nullable($row['description']),
                $this->point($row['lat'], $row['lon']),
            );
        }

        $statements = [];
        if ([] !== $osm) {
            $statements[] = \sprintf(
                'INSERT INTO osm.accommodations (osm_type, osm_id, name, category, website, opening_hours, description, geom, zone, last_seen_at) SELECT v.osm_type, v.osm_id, v.name, v.category, v.website, v.opening_hours, v.description, v.geom, %s, v.seen FROM (VALUES %s) AS v(osm_type, osm_id, name, category, website, opening_hours, description, geom, seen) ON CONFLICT (osm_type, osm_id) DO NOTHING;',
                ZonePromotion::literal($zoneSlug),
                implode(', ', $osm),
            );
        }

        if ([] !== $tourism) {
            $statements[] = \sprintf(
                'INSERT INTO tourism.accommodations (id, name, category, website, opening_hours, description, geom, zone, last_seen_at) SELECT v.id, v.name, v.category, v.website, v.opening_hours, v.description, v.geom, %s, v.seen FROM (VALUES %s) AS v(id, name, category, website, opening_hours, description, geom, seen) ON CONFLICT (id) DO NOTHING;',
                ZonePromotion::literal($zoneSlug),
                implode(', ', $tourism),
            );
        }

        return implode(' ', $statements);
    }

    private function point(float $lat, float $lon): string
    {
        return \sprintf('ST_SetSRID(ST_MakePoint(%.7F, %.7F), 4326)', $lon, $lat);
    }

    private function nullable(?string $value): string
    {
        return null === $value ? 'NULL' : ZonePromotion::literal($value);
    }

    /**
     * @throws ImportFailedException
     */
    private function psql(string $sql, string $label): void
    {
        $process = ($this->processFactory)(['psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-c', $sql]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $processTimedOutException) {
            throw new ImportFailedException(\sprintf('%s timed out after %.1fs', $label, $this->timeoutSeconds), 0, $processTimedOutException);
        }

        if (!$process->isSuccessful()) {
            throw new ImportFailedException(\sprintf("%s failed (exit %s).\nStderr: %s", $label, (string) $process->getExitCode(), $process->getErrorOutput()));
        }
    }
}
