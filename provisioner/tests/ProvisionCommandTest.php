<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\ProvisionCommand;
use Provisioner\RegionSelectionStore;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProvisionCommandTest extends TestCase
{
    private string $tmpDir;

    private string $regionsDir;

    private string $mergedPbf;

    private string $selectionFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/provision-cmd-'.uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);

        $this->regionsDir = $this->tmpDir.'/regions';
        $this->mergedPbf = $this->tmpDir.'/default.osm.pbf';
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

    private function buildTester(?MockHttpClient $httpClient = null): CommandTester
    {
        $command = new ProvisionCommand(
            regionsDir: $this->regionsDir,
            mergedPbf: $this->mergedPbf,
            selectionFile: $this->selectionFile,
            httpClient: $httpClient ?? new MockHttpClient(static fn (): MockResponse => new MockResponse('osm-bytes')),
            runMerge: false,
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
}
