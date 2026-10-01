<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\DataTourismeImporter;
use Provisioner\Exception\ImportFailedException;
use Provisioner\OpenAgendaImporter;
use Provisioner\OverrideImporter;
use Provisioner\PlaceEnrichmentPass;
use Provisioner\PostgisImporter;
use Provisioner\RoutingPerimeter;
use Provisioner\WikidataEnrichmentPass;
use Symfony\Component\Process\Process;

/**
 * A child killed by a signal (the container OOM-killer SIGKILLs psql or osm2pgsql, ADR-041)
 * or one that cannot start makes Symfony Process throw something other than a timeout. Each
 * runner must turn that into an {@see ImportFailedException}, the only exception the commands
 * catch: anything else escapes continue-on-error and never reaches provisioner.log.
 */
final class ProcessFailureTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/process-failure-'.uniqid('', true);
        mkdir($this->workDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->workDir)) {
            rmdir($this->workDir);
        }
    }

    /**
     * @return iterable<string, array{list<string>, ?string}>
     */
    public static function failingProcesses(): iterable
    {
        yield 'killed by a signal' => [['sh', '-c', 'kill -9 $$'], null];
        yield 'cannot start' => [['true'], '/nonexistent'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function runners(): iterable
    {
        foreach (['postgis', 'datatourisme', 'openagenda', 'place enrichment', 'wikidata enrichment', 'override', 'routing perimeter'] as $runner) {
            yield $runner => [$runner];
        }
    }

    /**
     * @return iterable<string, array{string, list<string>, ?string}>
     */
    public static function cases(): iterable
    {
        foreach (self::runners() as $runnerName => [$runner]) {
            foreach (self::failingProcesses() as $failureName => [$command, $cwd]) {
                yield $runnerName.', '.$failureName => [$runner, $command, $cwd];
            }
        }
    }

    /**
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('cases')]
    public function aProcessThatDiesOrNeverStartsIsAnImportFailure(string $runner, array $command, ?string $cwd): void
    {
        $factory = static fn (array $ignored): Process => new Process($command, $cwd);

        $this->expectException(ImportFailedException::class);

        match ($runner) {
            'postgis' => new PostgisImporter('tier1.lua', processFactory: $factory)->dropStaging('osm_staging_bretagne'),
            'datatourisme' => new DataTourismeImporter('https://diffuseur.datatourisme.fr/flux', processFactory: $factory)->dropRefreshStaging('tourism_staging_bretagne'),
            'openagenda' => new OpenAgendaImporter('https://public.opendatasoft.com/export', processFactory: $factory)->dropRefreshStaging('openagenda_staging'),
            'place enrichment' => new PlaceEnrichmentPass('osm', 'a.id', 'l.id = a.id', processFactory: $factory)->run($this->workDir, 'osm_staging_bretagne', 'accommodations'),
            'wikidata enrichment' => new WikidataEnrichmentPass($factory)->run($this->workDir, 'osm_staging_bretagne', ['accommodations']),
            'override' => new OverrideImporter($factory)->import($this->overrideFile(), 'bretagne'),
            'routing perimeter' => new RoutingPerimeter($this->workDir, $factory)->record(),
            default => self::fail('unknown runner '.$runner),
        };
    }

    private function overrideFile(): string
    {
        $path = $this->workDir.'/override.tsv';
        file_put_contents($path, "osm\tN/42\tcamp_site\t48.5\t2.5\tCamping du Moulin\n");

        return $path;
    }
}
