<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\EventsPromotion;
use Provisioner\ZonePromotion;
use Symfony\Component\Process\Process;

/**
 * Runs the generated events promotion against a real PostgreSQL, the way
 * {@see ZonePromotionExecutionTest} does for the append-only place promotion.
 *
 * The upsert-and-purge is the temporal lifecycle of ADR-051 §4, and its correctness is
 * runtime behaviour a string assertion cannot reach: that a moved date updates the row in
 * place instead of duplicating it, that a passed event is deleted (including one this very
 * run just upserted), and that a second run changes nothing. The `xmax = 0` insert/update
 * discrimination in the report, in particular, is only observable against a live server.
 *
 * Scratch schemas (`ep_live` / `ep_osm` / `ep_staging`), created and dropped per test, so
 * this never touches the reference index. Runs through `pdo_pgsql` or the `psql` binary,
 * whichever the environment has — the same dual path {@see ZonePromotionExecutionTest}
 * documents.
 */
final class EventsPromotionExecutionTest extends TestCase
{
    private const string LIVE = 'ep_live';

    private const string OSM = 'ep_osm';

    private const string STAGING = 'ep_staging';

    private const string SOURCE = 'test-events';

    private const string TODAY = '2026-07-01';

    private ?\PDO $pdo = null;

    /**
     * @var array{0: string, 1: string, 2: string, 3: string}|null host, port, dbname, user (password via PGPASSWORD)
     */
    private ?array $psql = null;

    protected function setUp(): void
    {
        [$host, $port, $database, $user, $password] = $this->credentials();

        if (\extension_loaded('pdo_pgsql')) {
            try {
                $this->pdo = new \PDO(\sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $database), $user, $password);
                $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            } catch (\PDOException $pdoException) {
                self::markTestSkipped(\sprintf('no reachable PostgreSQL: %s', $pdoException->getMessage()));
            }
        } elseif (null !== $this->psqlBinary()) {
            putenv('PGPASSWORD='.$password);
            $this->psql = [$host, $port, $database, $user];
            try {
                $this->exec('SELECT 1');
            } catch (\RuntimeException $runtimeException) {
                $this->psql = null;
                self::markTestSkipped(\sprintf('no reachable PostgreSQL: %s', $runtimeException->getMessage()));
            }
        } else {
            self::markTestSkipped('neither pdo_pgsql nor the psql binary is available');
        }

        $this->exec(\sprintf('DROP SCHEMA IF EXISTS %s, %s, %s CASCADE', self::LIVE, self::OSM, self::STAGING));
        $this->exec(\sprintf('CREATE SCHEMA %s; CREATE SCHEMA %s; CREATE SCHEMA %s', self::LIVE, self::OSM, self::STAGING));

        // The live events table mirrors tourism.events (baseline schema + the source column
        // of Version20260810120000): id is the upsert key, zone/last_seen_at the provenance.
        $this->exec(\sprintf(
            <<<'SQL'
                CREATE TABLE %1$s.events (
                    id text NOT NULL PRIMARY KEY, name text, category text NOT NULL,
                    start_date date, end_date date, url text, description text,
                    price_min numeric(10, 2), tags jsonb, geom geometry(Point, 4326) NOT NULL,
                    zone text, last_seen_at timestamptz, source text NOT NULL DEFAULT 'datatourisme'
                );
                CREATE TABLE %2$s.zones (slug text PRIMARY KEY, geom geometry);
                CREATE TABLE %3$s.events (
                    id text NOT NULL PRIMARY KEY, name text, category text NOT NULL,
                    start_date date, end_date date, url text, description text,
                    price_min numeric(10, 2), source text NOT NULL DEFAULT 'datatourisme',
                    tags jsonb, geom geometry(Point, 4326) NOT NULL
                );
                SQL,
            self::LIVE,
            self::OSM,
            self::STAGING,
        ));

        // A Brittany-ish envelope; the staged points below sit inside it, the out-of-zone
        // one does not.
        $this->exec(\sprintf(
            "INSERT INTO %s.zones (slug, geom) VALUES ('bretagne', ST_MakeEnvelope(-5, 47, 0, 49, 4326))",
            self::OSM,
        ));

        $this->exec($this->promotion()->reportDdl());
        $this->exec(\sprintf("DELETE FROM %s WHERE source = '%s'", ZonePromotion::REPORT_TABLE, self::SOURCE));
    }

    protected function tearDown(): void
    {
        if (!$this->pdo instanceof \PDO && null === $this->psql) {
            return;
        }

        $this->exec(\sprintf('DROP SCHEMA IF EXISTS %s, %s, %s CASCADE', self::LIVE, self::OSM, self::STAGING));
        $this->exec(\sprintf("DELETE FROM %s WHERE source = '%s'", ZonePromotion::REPORT_TABLE, self::SOURCE));
    }

    #[Test]
    public function aMovedDateUpdatesTheEventInPlaceRatherThanDuplicatingIt(): void
    {
        $this->stage("('e1', 'Fest A', 'festival', '2026-08-01', '2026-09-01', 'https://a.test', 'openagenda')");
        $this->promote();

        self::assertSame('1', $this->liveCount());
        self::assertSame('2026-09-01', $this->scalar(\sprintf("SELECT end_date FROM %s.events WHERE id = 'e1'", self::LIVE)));
        self::assertSame('Fest A', $this->scalar(\sprintf("SELECT name FROM %s.events WHERE id = 'e1'", self::LIVE)));
        self::assertSame('openagenda', $this->scalar(\sprintf("SELECT source FROM %s.events WHERE id = 'e1'", self::LIVE)));

        // The festival is rescheduled and renamed. The id is stable, so the row is updated,
        // not duplicated — the whole point of the upsert over the append-only anti-join.
        $this->exec(\sprintf('TRUNCATE %s.events', self::STAGING));
        $this->stage("('e1', 'Fest A renamed', 'festival', '2026-09-20', '2026-10-15', 'https://a.test', 'openagenda')");
        $this->promote();

        self::assertSame('1', $this->liveCount(), 'the moved event is updated, not duplicated');
        self::assertSame('2026-10-15', $this->scalar(\sprintf("SELECT end_date FROM %s.events WHERE id = 'e1'", self::LIVE)));
        self::assertSame('Fest A renamed', $this->scalar(\sprintf("SELECT name FROM %s.events WHERE id = 'e1'", self::LIVE)));
    }

    #[Test]
    public function eventsThatHaveEndedArePurgedIncludingOneJustUpserted(): void
    {
        // Already live: one past (before TODAY), one open-ended (NULL end_date), both in zone.
        $this->exec(\sprintf(
            <<<'SQL'
                INSERT INTO %1$s.events (id, name, category, end_date, geom, zone, source) VALUES
                    ('past', 'Gone', 'festival', '2026-06-30', ST_SetSRID(ST_MakePoint(-1.7, 48.1), 4326), 'bretagne', 'openagenda'),
                    ('open', 'Ongoing', 'exhibition', NULL, ST_SetSRID(ST_MakePoint(-1.7, 48.1), 4326), 'bretagne', 'openagenda');
                SQL,
            self::LIVE,
        ));

        // Staged: a future event kept, and a past one that the upsert inserts but the purge
        // (a separate statement in the same transaction) must still remove.
        $this->stage("('future', 'Upcoming', 'concert', '2026-11-01', '2026-12-01', 'https://f.test', 'openagenda')");
        $this->stage("('fresh-past', 'Just ended', 'concert', '2026-06-10', '2026-06-15', 'https://p.test', 'openagenda')");
        $this->promote();

        self::assertSame('0', $this->scalar(\sprintf("SELECT count(*) FROM %s.events WHERE id = 'past'", self::LIVE)), 'a past event is purged');
        self::assertSame('0', $this->scalar(\sprintf("SELECT count(*) FROM %s.events WHERE id = 'fresh-past'", self::LIVE)), 'a just-upserted past event is purged too');
        self::assertSame('1', $this->scalar(\sprintf("SELECT count(*) FROM %s.events WHERE id = 'open'", self::LIVE)), 'an open-ended event survives (end_date IS NULL)');
        self::assertSame('1', $this->scalar(\sprintf("SELECT count(*) FROM %s.events WHERE id = 'future'", self::LIVE)), 'a future event survives');
    }

    #[Test]
    public function onlyEventsInsideTheZoneAreUpserted(): void
    {
        $this->stage("('inside', 'In zone', 'festival', '2026-08-01', '2026-09-01', 'https://i.test', 'openagenda')");
        // 12°E / 45°N is well outside the Brittany envelope.
        $this->exec(\sprintf(
            "INSERT INTO %s.events (id, name, category, start_date, end_date, url, source, geom) VALUES ('outside', 'Elsewhere', 'festival', '2026-08-01', '2026-09-01', 'https://o.test', 'openagenda', ST_SetSRID(ST_MakePoint(12, 45), 4326))",
            self::STAGING,
        ));
        $this->promote();

        self::assertSame('1', $this->liveCount(), 'the national feed is clipped to the zone geometry');
        self::assertSame('1', $this->scalar(\sprintf("SELECT count(*) FROM %s.events WHERE id = 'inside'", self::LIVE)));
    }

    #[Test]
    public function aDoubleRunIsIdempotentAndTheReportTellsInsertsFromUpdates(): void
    {
        $this->stage("('e1', 'A', 'festival', '2026-08-01', '2026-09-01', 'https://a.test', 'openagenda')");
        $this->stage("('e2', 'B', 'concert', '2026-08-02', '2026-09-02', 'https://b.test', 'openagenda')");
        $this->promote();

        self::assertSame('2', $this->liveCount());
        self::assertSame('2', $this->reportField('candidates'));
        self::assertSame('2', $this->reportField('inserted'), 'the first run inserts both');

        $this->promote();

        self::assertSame('2', $this->liveCount(), 'a second identical run adds nothing');
        self::assertSame('2', $this->reportField('candidates'), 'both were still offered');
        self::assertSame('0', $this->reportField('inserted'), 'and both were updates, not inserts');
    }

    private function promotion(): EventsPromotion
    {
        return new EventsPromotion(self::SOURCE, self::LIVE, self::OSM.'.zones');
    }

    /**
     * Inserts one staged row from a `(id, name, category, start_date, end_date, url, source)`
     * tuple, with a fixed in-zone geometry.
     */
    private function stage(string $tuple): void
    {
        $this->exec(\sprintf(
            'INSERT INTO %s.events (id, name, category, start_date, end_date, url, source, geom) SELECT v.id, v.name, v.category, v.start_date::date, v.end_date::date, v.url, v.source, ST_SetSRID(ST_MakePoint(-1.68, 48.11), 4326) FROM (VALUES %s) AS v(id, name, category, start_date, end_date, url, source)',
            self::STAGING,
            $tuple,
        ));
    }

    private function promote(string $zone = 'bretagne'): void
    {
        $this->exec($this->promotion()->sql($zone, self::STAGING, self::TODAY));
    }

    private function liveCount(): string
    {
        return $this->scalar(\sprintf('SELECT count(*) FROM %s.events', self::LIVE));
    }

    private function reportField(string $field): string
    {
        return $this->scalar(\sprintf(
            "SELECT %s FROM %s WHERE source = '%s' AND zone = 'bretagne' AND table_name = 'events'",
            $field,
            ZonePromotion::REPORT_TABLE,
            self::SOURCE,
        ));
    }

    private function psqlBinary(): ?string
    {
        foreach (['/usr/bin/psql', '/usr/local/bin/psql'] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @throws \RuntimeException on a failed statement
     */
    private function exec(string $sql): void
    {
        if ($this->pdo instanceof \PDO) {
            try {
                $this->pdo->exec($sql);
            } catch (\PDOException $pdoException) {
                throw new \RuntimeException($pdoException->getMessage(), 0, $pdoException);
            }

            return;
        }

        $this->runPsql(['-v', 'ON_ERROR_STOP=1', '--single-transaction', '-c', $sql]);
    }

    private function scalar(string $sql): string
    {
        if ($this->pdo instanceof \PDO) {
            $statement = $this->pdo->query($sql);

            return false === $statement ? '' : $this->stringify($statement->fetchColumn());
        }

        return trim($this->runPsql(['-t', '-A', '-c', $sql]));
    }

    private function stringify(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param list<string> $arguments
     */
    private function runPsql(array $arguments): string
    {
        \assert(null !== $this->psql);
        [$host, $port, $database, $user] = $this->psql;

        $process = new Process(array_merge([$this->psqlBinary() ?? 'psql', '-h', $host, '-p', $port, '-d', $database, '-U', $user], $arguments));
        $process->setTimeout(60.0);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} host, port, dbname, user, password
     */
    private function credentials(): array
    {
        $url = getenv('DATABASE_URL');
        if (\is_string($url) && '' !== $url && false !== ($parts = parse_url($url))) {
            return [
                (string) ($parts['host'] ?? 'database'),
                (string) ($parts['port'] ?? 5432),
                ltrim((string) ($parts['path'] ?? '/app'), '/'),
                rawurldecode((string) ($parts['user'] ?? 'app')),
                rawurldecode((string) ($parts['pass'] ?? '')),
            ];
        }

        return [
            getenv('PGHOST') ?: 'database',
            getenv('PGPORT') ?: '5432',
            getenv('PGDATABASE') ?: 'app',
            getenv('PGUSER') ?: 'app',
            getenv('PGPASSWORD') ?: '',
        ];
    }
}
