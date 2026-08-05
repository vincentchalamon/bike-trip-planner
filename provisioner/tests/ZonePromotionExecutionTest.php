<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\ZonePromotion;
use Symfony\Component\Process\Process;

/**
 * Runs the generated promotion against a real PostgreSQL, because the string assertions
 * in {@see ZonePromotionTest} cannot reach what actually breaks.
 *
 * That block leans on runtime-only PL/pgSQL: `format()` with `%I`/`%L`/positional `%N$s`,
 * `EXECUTE`, `GET DIAGNOSTICS`, `RAISE EXCEPTION` with its own `%` substitution. A
 * mismatched placeholder index or a quoting slip satisfies every substring match and
 * fails the first time someone runs `make provision` — which is exactly the silent
 * failure this model exists to remove, so it belongs in CI rather than in a one-off
 * manual check.
 *
 * The schemas are scratch (`zp_live_*` / `zp_staging_*`), created and dropped per test,
 * so this never touches the reference index. Connection comes from `DATABASE_URL` (what
 * CI sets) or the libpq `PG*` variables (what the provisioner container uses).
 *
 * It runs through **either** `pdo_pgsql` or the `psql` binary, whichever the environment
 * has, because neither is present everywhere: the provisioner image ships `psql` and no
 * `pdo_pgsql` (production talks to Postgres through the binary), while the CI container
 * image is the reverse. Supporting both is what keeps this from being a check that
 * quietly never happens — a silent skip here would be worse than no test at all.
 */
final class ZonePromotionExecutionTest extends TestCase
{
    private const string LIVE = 'zp_live';

    private const string STAGING = 'zp_staging';

    private const string SOURCE = 'test-execution';

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
                self::markTestSkipped(\sprintf('no reachable PostgreSQL: %s', $runtimeException->getMessage()));
            }
        } else {
            self::markTestSkipped('neither pdo_pgsql nor the psql binary is available');
        }

        $this->exec(\sprintf('DROP SCHEMA IF EXISTS %s, %s CASCADE', self::LIVE, self::STAGING));
        $this->exec(\sprintf('CREATE SCHEMA %s; CREATE SCHEMA %s', self::LIVE, self::STAGING));

        // Two shapes of identity, mirroring the real tables: `pois` keyed on
        // (osm_type, osm_id), `ways` on the id alone (it holds a single object type, so
        // tier1.lua gives it no osm_type column).
        $this->exec(\sprintf(
            <<<'SQL'
                CREATE TABLE %1$s.pois (
                    osm_type character(1) NOT NULL, osm_id bigint NOT NULL, name text, category text NOT NULL,
                    website text, zone text, last_seen_at timestamptz, PRIMARY KEY (osm_type, osm_id)
                );
                CREATE TABLE %1$s.ways (osm_id bigint NOT NULL, tags jsonb, zone text, last_seen_at timestamptz, PRIMARY KEY (osm_id));
                CREATE TABLE %2$s.pois (
                    osm_type character(1) NOT NULL, osm_id bigint NOT NULL, name text, category text NOT NULL,
                    website text, PRIMARY KEY (osm_type, osm_id)
                );
                CREATE TABLE %2$s.ways (osm_id bigint NOT NULL, tags jsonb, PRIMARY KEY (osm_id));
                SQL,
            self::LIVE,
            self::STAGING,
        ));

        $this->exec($this->promotion()->reportDdl());
        $this->exec(\sprintf("DELETE FROM %s WHERE source = '%s'", ZonePromotion::REPORT_TABLE, self::SOURCE));
    }

    protected function tearDown(): void
    {
        if (!$this->pdo instanceof \PDO && null === $this->psql) {
            return;
        }

        $this->exec(\sprintf('DROP SCHEMA IF EXISTS %s, %s CASCADE', self::LIVE, self::STAGING));
        $this->exec(\sprintf("DELETE FROM %s WHERE source = '%s'", ZonePromotion::REPORT_TABLE, self::SOURCE));
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
     * Runs one statement, the way production does when psql is the available path.
     *
     * @throws \RuntimeException on a failed statement, so both backends surface an abort
     *                           the same way
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

    private function promotion(): ZonePromotion
    {
        return new ZonePromotion(self::SOURCE, self::LIVE, [
            'pois' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
            'ways' => 'l.osm_id = s.osm_id',
        ]);
    }

    private function stage(): void
    {
        $this->exec(\sprintf(
            <<<'SQL'
                INSERT INTO %1$s.pois (osm_type, osm_id, name, category, website) VALUES
                    ('N', 1, 'Creperie du Port', 'restaurant', 'https://a.test'),
                    ('N', 2, NULL, 'bakery', NULL);
                INSERT INTO %1$s.ways (osm_id, tags) VALUES (10, '{"highway": "cycleway"}'::jsonb);
                SQL,
            self::STAGING,
        ));
    }

    private function promote(string $zone = 'bretagne'): void
    {
        $this->exec($this->promotion()->sql($zone, self::STAGING));
    }

    #[Test]
    public function theGeneratedSqlRunsAndPromotesEveryStagedRow(): void
    {
        $this->stage();
        $this->promote();

        self::assertSame('2', $this->scalar(\sprintf('SELECT count(*) FROM %s.pois', self::LIVE)));
        self::assertSame('1', $this->scalar(\sprintf('SELECT count(*) FROM %s.ways', self::LIVE)));
        // Every promoted row carries its provenance, on both identity shapes.
        self::assertSame('0', $this->scalar(\sprintf("SELECT count(*) FROM %s.pois WHERE zone <> 'bretagne' OR last_seen_at IS NULL", self::LIVE)));
        self::assertSame('bretagne', $this->scalar(\sprintf('SELECT zone FROM %s.ways', self::LIVE)));

        self::assertSame('2', $this->scalar(\sprintf(
            "SELECT inserted FROM %s WHERE source = '%s' AND zone = 'bretagne' AND table_name = 'pois'",
            ZonePromotion::REPORT_TABLE,
            self::SOURCE,
        )));
    }

    #[Test]
    public function reOpeningAnUnchangedZoneInsertsNothing(): void
    {
        // The proof of the gate (ADR-049): re-opening is cheap because the anti-join is
        // on identity alone, so an unchanged source adds nothing.
        $this->stage();
        $this->promote();
        $this->promote();

        self::assertSame('2', $this->scalar(\sprintf('SELECT count(*) FROM %s.pois', self::LIVE)));
        self::assertSame('0', $this->scalar(\sprintf(
            "SELECT inserted FROM %s WHERE source = '%s' AND table_name = 'pois'",
            ZonePromotion::REPORT_TABLE,
            self::SOURCE,
        )));
        self::assertSame('2', $this->scalar(\sprintf(
            "SELECT candidates FROM %s WHERE source = '%s' AND table_name = 'pois'",
            ZonePromotion::REPORT_TABLE,
            self::SOURCE,
        )), 'the rows were offered, and skipped because they were already held');
    }

    #[Test]
    public function reOpeningRefreshesLastSeenAtWithoutRewritingThePayload(): void
    {
        $this->stage();
        $this->promote();

        $before = $this->scalar(\sprintf('SELECT last_seen_at FROM %s.pois WHERE osm_id = 1', self::LIVE));

        // OSM renamed the place and changed its site. Append-only with obsolescence
        // assumed (ADR-049 §5): the imported payload is never rewritten.
        $this->exec(\sprintf("UPDATE %s.pois SET name = 'RENAMED', website = 'https://changed.test' WHERE osm_id = 1", self::STAGING));
        $this->exec('SELECT pg_sleep(0.01)');
        $this->promote();

        self::assertSame('Creperie du Port', $this->scalar(\sprintf('SELECT name FROM %s.pois WHERE osm_id = 1', self::LIVE)));
        self::assertSame('https://a.test', $this->scalar(\sprintf('SELECT website FROM %s.pois WHERE osm_id = 1', self::LIVE)));
        self::assertNotSame($before, $this->scalar(\sprintf('SELECT last_seen_at FROM %s.pois WHERE osm_id = 1', self::LIVE)), 'last_seen_at is the single exception, and it is metadata');
    }

    #[Test]
    public function openingASecondZoneKeepsTheFirstAndSkipsTheKeysItShares(): void
    {
        $this->stage();
        $this->promote('bretagne');

        // The neighbouring extract overlaps on osm_id 1 and brings one new row.
        $this->exec(\sprintf('TRUNCATE %1$s.pois, %1$s.ways', self::STAGING));
        $this->exec(\sprintf(
            "INSERT INTO %s.pois (osm_type, osm_id, name, category) VALUES ('N', 1, 'Creperie du Port', 'restaurant'), ('N', 3, 'Estaminet', 'pub')",
            self::STAGING,
        ));
        $this->promote('picardie');

        self::assertSame('3', $this->scalar(\sprintf('SELECT count(*) FROM %s.pois', self::LIVE)));
        self::assertSame('bretagne', $this->scalar(\sprintf('SELECT zone FROM %s.pois WHERE osm_id = 1', self::LIVE)), 'the shared key keeps the zone that first imported it');
        self::assertSame('picardie', $this->scalar(\sprintf('SELECT zone FROM %s.pois WHERE osm_id = 3', self::LIVE)));
        self::assertSame('1', $this->scalar(\sprintf(
            "SELECT inserted FROM %s WHERE source = '%s' AND zone = 'picardie' AND table_name = 'pois'",
            ZonePromotion::REPORT_TABLE,
            self::SOURCE,
        )));
        self::assertSame('2', $this->scalar(\sprintf(
            "SELECT count(*) FROM %s.pois WHERE zone = 'bretagne'",
            self::LIVE,
        )), 'opening a second zone left the first intact');
    }

    #[Test]
    public function aStagingColumnWithNoLiveCounterpartAbortsTheWholePromotion(): void
    {
        $this->stage();
        $this->exec(\sprintf('ALTER TABLE %s.pois ADD COLUMN michelin_stars int', self::STAGING));

        try {
            $this->promote();
            self::fail('Expected the promotion to abort');
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString('zone promotion aborted', $runtimeException->getMessage());
            self::assertStringContainsString('michelin_stars', $runtimeException->getMessage());
            self::assertStringContainsString('api/migrations', $runtimeException->getMessage());
        }

        // One transaction: the abort left nothing behind, `ways` included, even though
        // the loop reaches `pois` first and could have committed it.
        self::assertSame('0', $this->scalar(\sprintf('SELECT count(*) FROM %s.pois', self::LIVE)));
        self::assertSame('0', $this->scalar(\sprintf('SELECT count(*) FROM %s.ways', self::LIVE)));
    }

    #[Test]
    public function aMissingStagingTableAbortsRatherThanPromotingSilently(): void
    {
        $this->stage();
        $this->exec(\sprintf('DROP TABLE %s.ways', self::STAGING));

        try {
            $this->promote();
            self::fail('Expected the promotion to abort');
        } catch (\RuntimeException $runtimeException) {
            self::assertStringContainsString('does not exist', $runtimeException->getMessage());
        }

        self::assertSame('0', $this->scalar(\sprintf('SELECT count(*) FROM %s.pois', self::LIVE)));
    }
}
