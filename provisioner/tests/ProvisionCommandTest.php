<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\DataTourismeImporter;
use Provisioner\OsmDataDownloader;
use Provisioner\PostgisImporter;
use Provisioner\PromotionReport;
use Provisioner\ProvisionCommand;
use Provisioner\RoutingPerimeter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

final class ProvisionCommandTest extends TestCase
{
    private string $tmpDir;

    private string $regionsDir;

    private string $routingDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/provision-cmd-'.uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);

        $this->regionsDir = $this->tmpDir.'/regions';
        $this->routingDir = $this->tmpDir.'/routing';
        mkdir($this->routingDir, 0o755, true);
        // Default fixture: a routing graph covering France, so the containment check
        // passes unless a test deliberately empties it.
        file_put_contents($this->routingDir.'/france-latest.osm.pbf', 'graph');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir.'/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                $this->removeDir($entry);
            } else {
                unlink($entry);
            }
        }

        rmdir($dir);
    }

    private function buildTester(
        ?MockHttpClient $httpClient = null,
        ?PostgisImporter $postgisImporter = null,
        ?DataTourismeImporter $dataTourismeImporter = null,
        ?string $lockFile = null,
        ?string $routingDir = null,
    ): CommandTester {
        $command = new ProvisionCommand(
            regionsDir: $this->regionsDir,
            downloader: new OsmDataDownloader(
                regionsDir: $this->regionsDir,
                httpClient: $httpClient ?? new MockHttpClient(static fn (): MockResponse => new MockResponse('osm-bytes')),
            ),
            filteredPbf: $this->tmpDir.'/tier1-filtered.osm.pbf',
            postgisImporter: $postgisImporter,
            dataTourismeDir: $this->tmpDir.'/datatourisme',
            dataTourismeImporter: $dataTourismeImporter,
            lockFile: $lockFile ?? $this->tmpDir.'/provision.lock',
            logFile: $this->tmpDir.'/provisioner.log',
            routingPerimeter: new RoutingPerimeter(
                $routingDir ?? $this->routingDir,
                static fn (array $command): Process => new Process(['true']),
            ),
            promotionReport: new PromotionReport(static fn (array $command): Process => new Process(['true'])),
        );

        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('provision'));
    }

    private function capturingImporter(?\Closure $onCommand = null): PostgisImporter
    {
        return new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: static function (array $command) use ($onCommand): Process {
                if ($onCommand instanceof \Closure) {
                    $onCommand($command);
                }

                return new Process(['true']);
            },
        );
    }

    #[Test]
    public function withoutAZoneArgumentItFailsWithAnExplicitMessage(): void
    {
        // ADR-049 §1: there is no default zone and no cumulative selection to fall back
        // on, so a run with no argument must say what to pass rather than guess.
        $tester = $this->buildTester();

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('A zone is required', $output);
        self::assertStringContainsString('bretagne', $output, 'the known zones are listed so the operator can act on the error');
    }

    #[Test]
    public function anUnknownZoneFailsAndNamesWhatWasAsked(): void
    {
        $tester = $this->buildTester();

        $exitCode = $tester->execute(['zone' => '../../evil'], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('is not a known zone', $tester->getDisplay());
    }

    #[Test]
    public function wholeFranceIsRefusedAsAReferenceZone(): void
    {
        // It is the routing grain, not the reference grain: 4 400 MB re-imported to open
        // one region would defeat "one zone per run" (ADR-049 §1).
        $tester = $this->buildTester();

        self::assertSame(1, $tester->execute(['zone' => 'france'], ['interactive' => false]));
        self::assertStringContainsString('is not a known zone', $tester->getDisplay());
    }

    #[Test]
    public function aZoneTheRoutingGraphDoesNotCoverIsRefusedWithAnActionableMessage(): void
    {
        // The invariant of ADR-049 §6 is checked, never maintained, so the refusal message
        // is the whole user experience of the check.
        unlink($this->routingDir.'/france-latest.osm.pbf');

        $httpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('a refused zone must not be downloaded');
        });
        $tester = $this->buildTester($httpClient);

        $exitCode = $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('routing graph does not cover', $output);
        // The SymfonyStyle error block word-wraps, so assert non-splittable tokens.
        self::assertStringContainsString('routing-build', $output);
        self::assertStringContainsString('currently built from', $output);
        self::assertStringContainsString('nothing', $output);
    }

    #[Test]
    public function anUnobservableRoutingPerimeterWarnsRatherThanBlocks(): void
    {
        // A missing volume mount must not become a provisioning outage; only an observed
        // perimeter can refuse.
        $tester = $this->buildTester(
            postgisImporter: $this->capturingImporter(),
            routingDir: $this->tmpDir.'/no-routing-volume',
        );

        $exitCode = $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('not mounted', $tester->getDisplay());
    }

    #[Test]
    public function itDownloadsOnlyTheRequestedZoneAndImportsItIntoThatZoneStagingSchema(): void
    {
        /** @var list<list<string>> $commands */
        $commands = [];
        $importer = $this->capturingImporter(static function (array $command) use (&$commands): void {
            /** @var list<string> $cmd */
            $cmd = $command;
            $commands[] = $cmd;
        });

        $tester = $this->buildTester(postgisImporter: $importer);

        self::assertSame(0, $tester->execute(['zone' => 'bretagne'], ['interactive' => false]), $tester->getDisplay());

        // Exactly one extract, and it is the zone's own.
        $downloaded = glob($this->regionsDir.'/*.osm.pbf') ?: [];
        self::assertSame([$this->regionsDir.'/bretagne-latest.osm.pbf'], $downloaded);

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $commands);
        $joinedAll = implode(' | ', $joined);

        // No merge: one zone per run means one extract to filter, so `osmium merge` and
        // the reference staging PBF it produced are gone.
        self::assertStringNotContainsString('osmium merge', $joinedAll);
        self::assertStringContainsString('osmium tags-filter', $joinedAll);
        self::assertStringContainsString($this->regionsDir.'/bretagne-latest.osm.pbf', $joinedAll);
        self::assertStringContainsString('CREATE SCHEMA osm_staging_bretagne', $joinedAll);
        // And no live schema is ever dropped or renamed.
        self::assertStringNotContainsString('DROP SCHEMA IF EXISTS osm CASCADE', $joinedAll);
        self::assertStringNotContainsString('RENAME TO osm', $joinedAll);
    }

    #[Test]
    public function itRecordsTheObservedRoutingPerimeter(): void
    {
        /** @var list<list<string>> $captured */
        $captured = [];
        $command = new ProvisionCommand(
            regionsDir: $this->regionsDir,
            downloader: new OsmDataDownloader(
                regionsDir: $this->regionsDir,
                httpClient: new MockHttpClient(static fn (): MockResponse => new MockResponse('osm-bytes')),
            ),
            filteredPbf: $this->tmpDir.'/tier1-filtered.osm.pbf',
            postgisImporter: $this->capturingImporter(),
            dataTourismeDir: $this->tmpDir.'/datatourisme',
            lockFile: $this->tmpDir.'/provision.lock',
            logFile: $this->tmpDir.'/provisioner.log',
            routingPerimeter: new RoutingPerimeter($this->routingDir, function (array $cmd) use (&$captured): Process {
                /** @var list<string> $command */
                $command = $cmd;
                $captured[] = $command;

                return new Process(['true']);
            }),
            promotionReport: new PromotionReport(static fn (array $command): Process => new Process(['true'])),
        );

        $app = new Application();
        $app->addCommand($command);

        $tester = new CommandTester($app->find('provision'));

        self::assertSame(0, $tester->execute(['zone' => 'bretagne'], ['interactive' => false]), $tester->getDisplay());

        // Recorded so /api/health can assert containment from the database alone.
        self::assertCount(1, $captured);
        self::assertStringContainsString("('france', now())", implode(' ', $captured[0]));
    }

    #[Test]
    public function dryRunDownloadsNothingAndImportsNothing(): void
    {
        $httpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('Dry run should not perform HTTP requests');
        });
        $importer = $this->capturingImporter(static function (array $command): never {
            self::fail('Dry run should not run any import command');
        });

        $tester = $this->buildTester($httpClient, postgisImporter: $importer);

        $exitCode = $tester->execute(['zone' => 'bretagne', '--dry-run' => true], ['interactive' => false]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Dry run', $output);
        self::assertStringContainsString('osm_staging_bretagne', $output);
        self::assertFalse(is_file($this->regionsDir.'/bretagne-latest.osm.pbf'));
    }

    #[Test]
    public function itReportsWhatTheZoneOpeningActuallyAdded(): void
    {
        // "0 new entries" on a re-open is the evidence that the identity anti-join works,
        // so the report states it instead of leaving it to be inferred from silence.
        $report = new PromotionReport(function (array $command): Process {
            if (1 === preg_match("/TO '([^']+)'/", implode(' ', $command), $matches)) {
                file_put_contents($matches[1], "osm\tpois\t120\t0\nosm\taccommodations\t8\t0\n");
            }

            return new Process(['true']);
        });

        $command = new ProvisionCommand(
            regionsDir: $this->regionsDir,
            downloader: new OsmDataDownloader(
                regionsDir: $this->regionsDir,
                httpClient: new MockHttpClient(static fn (): MockResponse => new MockResponse('osm-bytes')),
            ),
            filteredPbf: $this->tmpDir.'/tier1-filtered.osm.pbf',
            postgisImporter: $this->capturingImporter(),
            dataTourismeDir: $this->tmpDir.'/datatourisme',
            lockFile: $this->tmpDir.'/provision.lock',
            logFile: $this->tmpDir.'/provisioner.log',
            routingPerimeter: new RoutingPerimeter($this->routingDir, static fn (array $c): Process => new Process(['true'])),
            promotionReport: $report,
        );

        $app = new Application();
        $app->addCommand($command);

        $tester = new CommandTester($app->find('provision'));

        self::assertSame(0, $tester->execute(['zone' => 'bretagne'], ['interactive' => false]), $tester->getDisplay());

        $output = $tester->getDisplay();
        self::assertStringContainsString('Zone opening report', $output);
        self::assertStringContainsString('already present', $output);
        self::assertStringContainsString('0 new entries', $output);
    }

    #[Test]
    public function stagesTheCuratedFluxBeforeTheOsmImportAndPromotesItAfter(): void
    {
        // The ordering #885 needs. The flux is the curated source — not one of its 124 240
        // accommodations has an empty name — so the OSM gate must have it in hand before it
        // decides what to reject. Promotion still comes last, because it clips to the zone
        // geometry only the OSM import produces.
        /** @var list<string> $log */
        $log = [];
        $record = static function (string $step) use (&$log): void {
            $log[] = $step;
        };

        $fluxZip = $this->emptyFluxZip();
        $dataTourisme = new DataTourismeImporter(
            fluxUrl: 'https://example.test/flux',
            httpClient: new MockHttpClient(static fn (): MockResponse => new MockResponse($fluxZip)),
            processFactory: static function (array $command) use ($record): Process {
                $sql = end($command);
                if (\is_string($sql) && str_contains($sql, 'INSERT INTO tourism.metadata')) {
                    $record('datatourisme:promote');
                } elseif (\is_string($sql) && str_contains($sql, 'CREATE SCHEMA tourism_staging_bretagne')) {
                    $record('datatourisme:stage');
                }

                // The zone has a geometry, so the promotion has something to clip against.
                if (\is_string($sql) && 1 === preg_match("/TO '([^']+zone-geometry[^']*)'/", $sql, $matches)) {
                    file_put_contents($matches[1], "1\n");
                }

                return new Process(['true']);
            },
        );

        /** @var list<string> $osmCommands */
        $osmCommands = [];
        $osm = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: static function (array $command) use ($record, &$osmCommands): Process {
                $joined = implode(' ', $command);
                $osmCommands[] = $joined;
                if (str_contains($joined, 'osm2pgsql')) {
                    $record('osm:import');
                }

                return new Process(['true']);
            },
        );

        $tester = $this->buildTester(postgisImporter: $osm, dataTourismeImporter: $dataTourisme);

        self::assertSame(0, $tester->execute(['zone' => 'bretagne'], ['interactive' => false]), $tester->getDisplay());

        self::assertSame(['datatourisme:stage', 'osm:import', 'datatourisme:promote'], $log);

        // And the staged table is the one the OSM gate matches against.
        $export = array_values(array_filter($osmCommands, static fn (string $c): bool => str_contains($c, 'place-candidates.tsv')));
        self::assertCount(1, $export);
        self::assertStringContainsString('FROM tourism_staging_bretagne.accommodations t', $export[0]);
    }

    #[Test]
    public function anUnconfiguredFluxLeavesTheOsmGateWithoutAMatchStep(): void
    {
        // DataTourisme is optional (ADR-041 continue-on-error): OSM must still provision, with
        // one fewer resolver step rather than a broken query.
        $previousFluxId = getenv('DATATOURISME_FLUX_ID');
        $previousAppKey = getenv('DATATOURISME_APP_KEY');
        putenv('DATATOURISME_FLUX_ID');
        putenv('DATATOURISME_APP_KEY');

        /** @var list<string> $osmCommands */
        $osmCommands = [];
        $osm = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: static function (array $command) use (&$osmCommands): Process {
                $osmCommands[] = implode(' ', $command);

                return new Process(['true']);
            },
        );

        try {
            $tester = $this->buildTester(postgisImporter: $osm);
            $exitCode = $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);
        } finally {
            false === $previousFluxId ? putenv('DATATOURISME_FLUX_ID') : putenv('DATATOURISME_FLUX_ID='.$previousFluxId);
            false === $previousAppKey ? putenv('DATATOURISME_APP_KEY') : putenv('DATATOURISME_APP_KEY='.$previousAppKey);
        }

        self::assertSame(0, $exitCode, $tester->getDisplay());
        $export = array_values(array_filter($osmCommands, static fn (string $c): bool => str_contains($c, 'place-candidates.tsv')));
        self::assertCount(1, $export);
        self::assertStringNotContainsString('ST_DWithin', $export[0]);
    }

    /**
     * A syntactically valid but empty flux ZIP, so `stage()` reaches its psql calls without
     * needing a fixture of JSON-LD objects.
     */
    private function emptyFluxZip(): string
    {
        $path = $this->tmpDir.'/empty-flux.zip';
        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('index.json', '{}');
        $zip->close();

        return (string) file_get_contents($path);
    }

    #[Test]
    public function aFailedOsmImportLeavesTheStagedFluxUnpromotedRatherThanPromotingNothing(): void
    {
        // Staging succeeds, the OSM import fails, so the registry has no geometry for this
        // zone. The promotion clips to that geometry, so running it would promote exactly
        // nothing and report success — silently, which is worse than refusing.
        $fluxZip = $this->emptyFluxZip();
        /** @var list<string> $dtCommands */
        $dtCommands = [];
        $dataTourisme = new DataTourismeImporter(
            fluxUrl: 'https://example.test/flux',
            httpClient: new MockHttpClient(static fn (): MockResponse => new MockResponse($fluxZip)),
            processFactory: static function (array $command) use (&$dtCommands): Process {
                $sql = end($command);
                $dtCommands[] = \is_string($sql) ? $sql : '';

                // No zone geometry yet: the precondition read comes back 0.
                if (\is_string($sql) && 1 === preg_match("/TO '([^']+zone-geometry[^']*)'/", $sql, $matches)) {
                    file_put_contents($matches[1], "0\n");
                }

                return new Process(['true']);
            },
        );

        $failingOsm = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: static fn (array $command): Process => new Process(['sh', '-c', 'echo "boom" 1>&2; exit 1']),
        );

        $tester = $this->buildTester(postgisImporter: $failingOsm, dataTourismeImporter: $dataTourisme);
        $exitCode = $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);

        // OSM failed, so the aggregate fails; DataTourisme is a skip, not a failure.
        self::assertSame(1, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('no registry geometry', $tester->getDisplay());
        self::assertMatchesRegularExpression('/\x{2717}\s*osm/u', $tester->getDisplay());
        self::assertMatchesRegularExpression('/\x{2713}\s*datatourisme/u', $tester->getDisplay());

        // Nothing was promoted, and the staging schema was still reclaimed.
        $promotions = array_filter($dtCommands, static fn (string $c): bool => str_contains($c, 'INSERT INTO tourism.metadata'));
        self::assertSame([], $promotions, 'no promotion without a geometry to clip against');
        self::assertNotSame([], array_filter($dtCommands, static fn (string $c): bool => str_contains($c, 'DROP SCHEMA IF EXISTS tourism_staging_bretagne')));
    }

    #[Test]
    public function aFailedOsmRefreshStillPromotesTheFluxForAZoneAlreadyOpen(): void
    {
        // The other side of the same coin (ADR-041): once the zone has a geometry, a failed
        // OSM refresh must not block the DataTourisme refresh. The precondition is the
        // geometry, never the sibling step's exit code.
        $fluxZip = $this->emptyFluxZip();
        /** @var list<string> $dtCommands */
        $dtCommands = [];
        $dataTourisme = new DataTourismeImporter(
            fluxUrl: 'https://example.test/flux',
            httpClient: new MockHttpClient(static fn (): MockResponse => new MockResponse($fluxZip)),
            processFactory: static function (array $command) use (&$dtCommands): Process {
                $sql = end($command);
                $dtCommands[] = \is_string($sql) ? $sql : '';

                if (\is_string($sql) && 1 === preg_match("/TO '([^']+)'/", $sql, $matches)) {
                    file_put_contents($matches[1], str_contains($matches[1], 'zone-geometry') ? "1\n" : "0\n");
                }

                return new Process(['true']);
            },
        );

        $failingOsm = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: static fn (array $command): Process => new Process(['sh', '-c', 'echo "boom" 1>&2; exit 1']),
        );

        $tester = $this->buildTester(postgisImporter: $failingOsm, dataTourismeImporter: $dataTourisme);

        self::assertSame(1, $tester->execute(['zone' => 'bretagne'], ['interactive' => false]), $tester->getDisplay());
        self::assertNotSame(
            [],
            array_filter($dtCommands, static fn (string $c): bool => str_contains($c, 'INSERT INTO tourism.metadata')),
            'an already-open zone still gets its flux refresh',
        );
    }

    #[Test]
    public function postgisImportFailureReturnsFailure(): void
    {
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: static fn (array $command): Process => new Process(['sh', '-c', 'echo "boom" 1>&2; exit 1']),
        );

        $tester = $this->buildTester(postgisImporter: $importer);

        $exitCode = $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('tags-filter failed', $tester->getDisplay());
    }

    #[Test]
    public function aFailedDataTourismeSourceDoesNotMaskTheSucceededOsmSource(): void
    {
        // Continue-on-error (ADR-041): OSM succeeds, DataTourisme fails; the run
        // attempts both, the summary records each, and the aggregate exit is a
        // failure without aborting the OSM source.
        $failingDataTourisme = new DataTourismeImporter(
            fluxUrl: 'https://example.test/flux',
            httpClient: new MockHttpClient(new MockResponse('nope', ['http_code' => 500])),
            processFactory: static fn (array $command): Process => new Process(['true']),
        );

        $tester = $this->buildTester(
            postgisImporter: $this->capturingImporter(),
            dataTourismeImporter: $failingDataTourisme,
        );

        $exitCode = $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);

        self::assertSame(1, $exitCode, $tester->getDisplay());
        $output = $tester->getDisplay();
        self::assertStringContainsString('is open', $output, 'the OSM source still completed');
        self::assertStringContainsString('Provisioning summary', $output);
        self::assertMatchesRegularExpression('/\x{2713}\s*osm/u', $output, 'osm reported as succeeded');
        self::assertMatchesRegularExpression('/\x{2717}\s*datatourisme/u', $output, 'datatourisme reported as failed');
    }

    #[Test]
    public function proceedsWithWarningWhenLockFileCannotBeOpened(): void
    {
        // /data not mounted (fopen returns false): the run proceeds with a warning
        // rather than blocking on an inability to lock (ADR-041 R1).
        $tester = $this->buildTester(
            postgisImporter: $this->capturingImporter(),
            lockFile: '/dev/null/no-such-path',
        );
        $exitCode = $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        // The warning block word-wraps, so assert a single non-splittable token.
        self::assertStringContainsString('concurrency', $tester->getDisplay());
    }

    #[Test]
    public function aConcurrentRunIsRefusedWhileTheLockIsHeld(): void
    {
        // Hold the lock the command tries to acquire.
        $lockFile = $this->tmpDir.'/provision.lock';
        $handle = fopen($lockFile, 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, \LOCK_EX | \LOCK_NB));

        $tester = $this->buildTester();
        $exitCode = $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Another provisioning run is already in progress', $tester->getDisplay());

        flock($handle, \LOCK_UN);
        fclose($handle);
    }

    #[Test]
    public function missingDataTourismeCredentialsSkipsGracefullyWithoutFailingOsm(): void
    {
        // No DataTourisme importer injected and no DATATOURISME_* env: the step is
        // skipped with a warning and reported as a success, so a deployment without
        // DataTourisme credentials still provisions OSM (ADR-041 continue-on-error).
        $previousFluxId = getenv('DATATOURISME_FLUX_ID');
        $previousAppKey = getenv('DATATOURISME_APP_KEY');
        putenv('DATATOURISME_FLUX_ID');
        putenv('DATATOURISME_APP_KEY');

        try {
            $tester = $this->buildTester(postgisImporter: $this->capturingImporter());

            $exitCode = $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);
        } finally {
            false === $previousFluxId ? putenv('DATATOURISME_FLUX_ID') : putenv('DATATOURISME_FLUX_ID='.$previousFluxId);
            false === $previousAppKey ? putenv('DATATOURISME_APP_KEY') : putenv('DATATOURISME_APP_KEY='.$previousAppKey);
        }

        self::assertSame(0, $exitCode, $tester->getDisplay());
        $output = $tester->getDisplay();
        self::assertStringContainsString('DataTourisme import skipped', $output);
        self::assertMatchesRegularExpression('/\x{2713}\s*osm/u', $output, 'osm reported as succeeded');
        self::assertMatchesRegularExpression('/\x{2713}\s*datatourisme/u', $output, 'datatourisme reported as skipped-success');
    }
}
