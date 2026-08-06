<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\ImportOverrideCommand;
use Provisioner\OverrideImporter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class ImportOverrideCommandTest extends TestCase
{
    private string $zonesDir;

    /**
     * @var list<list<string>>
     */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->zonesDir = sys_get_temp_dir().'/override-cmd-'.uniqid('', true);
        mkdir($this->zonesDir.'/bretagne', 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->zonesDir.'/*/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->zonesDir.'/*') ?: [] as $dir) {
            rmdir($dir);
        }

        if (is_dir($this->zonesDir)) {
            rmdir($this->zonesDir);
        }
    }

    private function tester(): CommandTester
    {
        $command = new ImportOverrideCommand(
            zonesDir: $this->zonesDir,
            importer: new OverrideImporter(function (array $command): Process {
                /** @var list<string> $cmd */
                $cmd = $command;
                $this->captured[] = $cmd;

                return new Process(['true']);
            }),
        );

        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('provision-override'));
    }

    private function writeOverride(string $contents, ?string $path = null): string
    {
        $path ??= $this->zonesDir.'/bretagne/override.tsv';
        file_put_contents($path, $contents);

        return $path;
    }

    #[Test]
    public function importsTheZonesDefaultOverrideFile(): void
    {
        // The operator drops the file where the report was written; no path to remember.
        $this->writeOverride("osm\tN/42\tcamp_site\t48.5\t2.5\tCamping du Moulin\n");

        $tester = $this->tester();
        $exitCode = $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);

        self::assertSame(0, $exitCode, $tester->getDisplay());
        self::assertStringContainsString('1 correction(s) offered', $tester->getDisplay());
        self::assertCount(1, $this->captured);
    }

    #[Test]
    public function acceptsAnExplicitPath(): void
    {
        $path = $this->writeOverride("osm\tN/42\tcamp_site\t48.5\t2.5\tCamping\n", $this->zonesDir.'/elsewhere.tsv');

        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['zone' => 'bretagne', 'file' => $path], ['interactive' => false]), $tester->getDisplay());
    }

    #[Test]
    public function refusesAMalformedFileAndSaysNothingWasInserted(): void
    {
        // No partial application: the parse completes before any statement runs.
        $this->writeOverride("osm\tN/42\tcamp_site\tnorth\t2.5\tCamping\n");

        $tester = $this->tester();
        $exitCode = $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        $output = $tester->getDisplay();
        self::assertStringContainsString('non-numeric coordinates', $output);
        self::assertStringContainsString('Nothing was inserted', $output);
        self::assertSame([], $this->captured);
    }

    #[Test]
    public function withoutAZoneItFailsWithAnExplicitMessage(): void
    {
        $tester = $this->tester();

        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        $output = $tester->getDisplay();
        self::assertStringContainsString('A zone is required', $output);
        self::assertStringContainsString('bretagne', $output);
    }

    #[Test]
    public function anUnknownZoneIsRefused(): void
    {
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['zone' => '../../evil'], ['interactive' => false]));
        self::assertStringContainsString('is not a known zone', $tester->getDisplay());
    }

    #[Test]
    public function aMissingFileIsReportedWithItsPath(): void
    {
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['zone' => 'bretagne'], ['interactive' => false]));
        // The SymfonyStyle error block word-wraps, so assert non-splittable tokens.
        $output = $tester->getDisplay();
        self::assertStringContainsString('Override file', $output);
        self::assertStringContainsString('override.tsv', $output);
        self::assertStringContainsString('Nothing was inserted', $output);
    }

    #[Test]
    public function warnsThatNothingStoresTheFile(): void
    {
        // The assumed limitation of #886, repeated at the point of use rather than left in the
        // runbook alone: a database rebuilt from scratch loses every correction whose file the
        // operator did not keep.
        $this->writeOverride("osm\tN/42\tcamp_site\t48.5\t2.5\tCamping\n");

        $tester = $this->tester();
        $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);

        self::assertStringContainsString('Keep this file', $tester->getDisplay());
    }

    #[Test]
    public function saysTheCorrectionsWillNotBeReanalysed(): void
    {
        $this->writeOverride("osm\tN/42\tcamp_site\t48.5\t2.5\tCamping\n");

        $tester = $this->tester();
        $tester->execute(['zone' => 'bretagne'], ['interactive' => false]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('will not re-analyse them', $output);
        self::assertStringContainsString('append-only', $output);
    }
}
