<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\DataTourismeImporter;
use Provisioner\OsmDataDownloader;
use Provisioner\PostgisImporter;
use Provisioner\ProvisionCommand;
use Provisioner\RegionSelectionStore;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

final class ProvisionCommandTest extends TestCase
{
    private string $tmpDir;

    private string $regionsDir;

    private string $referencePbf;

    private string $selectionFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/provision-cmd-'.uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);

        $this->regionsDir = $this->tmpDir.'/regions';
        $this->referencePbf = $this->tmpDir.'/reference-merged.osm.pbf';
        $this->selectionFile = $this->tmpDir.'/regions.json';
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
        bool $runMerge = false,
        ?\Closure $downloaderProcessFactory = null,
        ?PostgisImporter $postgisImporter = null,
        ?DataTourismeImporter $dataTourismeImporter = null,
        ?string $lockFile = null,
    ): CommandTester {
        $command = new ProvisionCommand(
            regionsDir: $this->regionsDir,
            referencePbf: $this->referencePbf,
            selectionFile: $this->selectionFile,
            downloader: new OsmDataDownloader(
                regionsDir: $this->regionsDir,
                httpClient: $httpClient ?? new MockHttpClient(static fn (): MockResponse => new MockResponse('osm-bytes')),
                processFactory: $downloaderProcessFactory,
            ),
            runMerge: $runMerge,
            postgisImporter: $postgisImporter,
            dataTourismeDir: $this->tmpDir.'/datatourisme',
            dataTourismeImporter: $dataTourismeImporter,
            lockFile: $lockFile ?? $this->tmpDir.'/provision.lock',
            logFile: $this->tmpDir.'/provisioner.log',
        );

        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('provision'));
    }

    #[Test]
    public function missingSelectionAndInteractiveRunsInstallFlowAndPersistsSelection(): void
    {
        $tester = $this->buildTester();
        $tester->setInputs([
            'Nord-Pas-de-Calais (223 MB)',
            '',
            'yes',
        ]);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('Nord-Pas-de-Calais', $output);
        self::assertStringContainsString('Done!', $output);

        self::assertTrue(is_file($this->selectionFile));
        self::assertSame(['nord-pas-de-calais'], new RegionSelectionStore($this->selectionFile)->load());
    }

    #[Test]
    public function missingSelectionAndNonInteractiveFailsWithClearError(): void
    {
        $tester = $this->buildTester();

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('First run requires interactive setup', $tester->getDisplay());
    }

    #[Test]
    public function existingSelectionAndNonInteractiveRunsSilentForcedUpdate(): void
    {
        new RegionSelectionStore($this->selectionFile)->save(['bretagne']);

        // Pre-existing PBF that should be re-downloaded (force = true).
        mkdir($this->regionsDir, 0o755, true);
        $pbfPath = $this->regionsDir.'/bretagne-latest.osm.pbf';
        file_put_contents($pbfPath, 'stale');

        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('fresh-bytes'));
        $tester = $this->buildTester($httpClient);

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertSame('fresh-bytes', file_get_contents($pbfPath));
        self::assertStringContainsString('Update complete', $tester->getDisplay());
    }

    #[Test]
    public function existingSelectionAndInteractiveShowsMenuWithUpdateReconfigureCancel(): void
    {
        new RegionSelectionStore($this->selectionFile)->save(['bretagne']);

        $tester = $this->buildTester();
        $tester->setInputs(['cancel']);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('Selection already exists', $output);
        self::assertStringContainsString('update', $output);
        self::assertStringContainsString('reconfigure', $output);
        self::assertStringContainsString('cancel', $output);
    }

    #[Test]
    public function existingSelectionInteractiveUpdateChoiceRunsForcedDownload(): void
    {
        new RegionSelectionStore($this->selectionFile)->save(['alsace']);

        mkdir($this->regionsDir, 0o755, true);
        $pbfPath = $this->regionsDir.'/alsace-latest.osm.pbf';
        file_put_contents($pbfPath, 'stale');

        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('refreshed'));
        $tester = $this->buildTester($httpClient);
        $tester->setInputs(['update']);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertSame('refreshed', file_get_contents($pbfPath));
    }

    #[Test]
    public function dryRunDuringInstallFlowDoesNotPersistSelection(): void
    {
        $tester = $this->buildTester();
        $tester->setInputs([
            'Nord-Pas-de-Calais (223 MB)',
            '',
        ]);

        $exitCode = $tester->execute(['--dry-run' => true], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertFalse(is_file($this->selectionFile));
    }

    #[Test]
    public function dryRunDuringUpdateFlowDoesNotDownload(): void
    {
        new RegionSelectionStore($this->selectionFile)->save(['bretagne']);

        $httpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('Dry run should not perform HTTP requests');
        });
        $tester = $this->buildTester($httpClient);

        $exitCode = $tester->execute(['--dry-run' => true], ['interactive' => false]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertFalse(is_file($this->regionsDir.'/bretagne-latest.osm.pbf'));
    }

    #[Test]
    public function unknownSlugInSelectionFailsWithClearError(): void
    {
        // Write a tampered selection bypassing RegionSelectionStore::save() validation.
        file_put_contents($this->selectionFile, json_encode(['slugs' => ['../../evil']]));

        $tester = $this->buildTester();

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('unknown slugs', $tester->getDisplay());
    }

    #[Test]
    public function emptySelectionExitsGracefully(): void
    {
        $tester = $this->buildTester();
        $tester->setInputs(['']);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No region selected', $tester->getDisplay());
    }

    #[Test]
    public function postgisImportAlwaysRuns(): void
    {
        new RegionSelectionStore($this->selectionFile)->save(['bretagne']);

        $calls = 0;
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: function (array $command) use (&$calls): Process {
                ++$calls;

                return new Process(['true']);
            },
        );

        $tester = $this->buildTester(
            runMerge: true,
            downloaderProcessFactory: static fn (array $command): Process => new Process(['true']),
            postgisImporter: $importer,
        );

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('Importing Tier-1 features into PostGIS', $tester->getDisplay());
        self::assertGreaterThan(0, $calls, 'PostgisImporter::run() should have been invoked');
    }

    #[Test]
    public function everyOsmiumMergeInTheFlowFeedsThePostgisImportAndNothingElse(): void
    {
        // #881: the routing graph no longer shares an artifact with the reference
        // import. The merge is a private staging step of the PostGIS import, so
        // there must be exactly one, its output must be the very file handed to
        // the importer, and it must stay inside the provisioner's own /data.
        new RegionSelectionStore($this->selectionFile)->save(['bretagne']);

        /** @var list<list<string>> $mergeCommands */
        $mergeCommands = [];
        /** @var list<list<string>> $importCommands */
        $importCommands = [];

        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: function (array $command) use (&$importCommands): Process {
                $importCommands[] = $command;

                return new Process(['true']);
            },
        );

        $tester = $this->buildTester(
            runMerge: true,
            downloaderProcessFactory: function (array $command) use (&$mergeCommands): Process {
                $mergeCommands[] = $command;

                return new Process(['true']);
            },
            postgisImporter: $importer,
        );

        self::assertSame(0, $tester->execute([], ['interactive' => false]), $tester->getDisplay());

        $merges = array_values(array_filter(
            $mergeCommands,
            static fn (array $command): bool => 'merge' === ($command[1] ?? null),
        ));
        self::assertCount(1, $merges, 'exactly one osmium merge feeds the reference import');

        $outputIndex = (int) array_search('-o', $merges[0], true) + 1;
        $mergeOutput = $merges[0][$outputIndex];
        self::assertSame($this->referencePbf, $mergeOutput, 'the merge writes the reference staging file');

        $tagsFilter = array_values(array_filter(
            $importCommands,
            static fn (array $command): bool => 'tags-filter' === ($command[1] ?? null),
        ));
        self::assertCount(1, $tagsFilter);
        self::assertContains(
            $mergeOutput,
            $tagsFilter[0],
            'the merged file is consumed by the PostGIS import, so it has no other consumer',
        );

        // The production default must stay inside the provisioner's /data mount and
        // must not be the neutral `default.osm.pbf` name Valhalla used to mount.
        $default = new \ReflectionClass(ProvisionCommand::class)->getConstant('DEFAULT_REFERENCE_PBF');
        self::assertIsString($default);
        self::assertStringStartsWith('/data/', $default);
        self::assertStringNotContainsString('default.osm.pbf', $default);
        self::assertStringNotContainsString('custom_files', $default);
    }

    #[Test]
    public function postgisImportFailureReturnsFailure(): void
    {
        new RegionSelectionStore($this->selectionFile)->save(['bretagne']);

        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: static fn (array $command): Process => new Process(['sh', '-c', 'echo "boom" 1>&2; exit 1']),
        );

        $tester = $this->buildTester(
            runMerge: true,
            downloaderProcessFactory: static fn (array $command): Process => new Process(['true']),
            postgisImporter: $importer,
        );

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('tags-filter failed', $tester->getDisplay());
    }

    #[Test]
    public function aFailedDataTourismeSourceDoesNotMaskTheSucceededOsmSource(): void
    {
        // Continue-on-error (ADR-041): OSM succeeds, DataTourisme fails; the run
        // attempts both, the summary records each, and the aggregate exit is a
        // failure without aborting the OSM source.
        new RegionSelectionStore($this->selectionFile)->save(['bretagne']);

        $failingDataTourisme = new DataTourismeImporter(
            fluxUrl: 'https://example.test/flux',
            httpClient: new MockHttpClient(new MockResponse('nope', ['http_code' => 500])),
            processFactory: static fn (array $command): Process => new Process(['true']),
        );

        $tester = $this->buildTester(dataTourismeImporter: $failingDataTourisme);

        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(1, $exitCode, $tester->getDisplay());
        $output = $tester->getDisplay();
        self::assertStringContainsString('Update complete', $output, 'the OSM source still completed');
        self::assertStringContainsString('Provisioning summary', $output);
        self::assertMatchesRegularExpression('/\x{2713}\s*osm/u', $output, 'osm reported as succeeded');
        self::assertMatchesRegularExpression('/\x{2717}\s*datatourisme/u', $output, 'datatourisme reported as failed');
    }

    #[Test]
    public function proceedsWithWarningWhenLockFileCannotBeOpened(): void
    {
        // /data not mounted (fopen returns false): the run proceeds with a warning
        // rather than blocking on an inability to lock (ADR-041 R1).
        new RegionSelectionStore($this->selectionFile)->save(['bretagne']);

        // A path under a non-directory makes fopen('…', 'c') fail.
        $tester = $this->buildTester(lockFile: '/dev/null/no-such-path');
        $exitCode = $tester->execute([], ['interactive' => false]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        // The warning block word-wraps, so assert a single non-splittable token.
        self::assertStringContainsString('concurrency', $tester->getDisplay());
    }

    #[Test]
    public function aConcurrentRunIsRefusedWhileTheLockIsHeld(): void
    {
        new RegionSelectionStore($this->selectionFile)->save(['bretagne']);

        // Hold the lock the command tries to acquire.
        $lockFile = $this->tmpDir.'/provision.lock';
        $handle = fopen($lockFile, 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, \LOCK_EX | \LOCK_NB));

        $tester = $this->buildTester();
        $exitCode = $tester->execute([], ['interactive' => false]);

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
        new RegionSelectionStore($this->selectionFile)->save(['bretagne']);

        $previousFluxId = getenv('DATATOURISME_FLUX_ID');
        $previousAppKey = getenv('DATATOURISME_APP_KEY');
        putenv('DATATOURISME_FLUX_ID');
        putenv('DATATOURISME_APP_KEY');

        try {
            $tester = $this->buildTester(
                downloaderProcessFactory: static fn (array $command): Process => new Process(['true']),
            );

            $exitCode = $tester->execute([], ['interactive' => false]);
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
