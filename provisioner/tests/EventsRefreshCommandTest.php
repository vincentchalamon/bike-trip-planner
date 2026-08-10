<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\DataTourismeImporter;
use Provisioner\EventsRefreshCommand;
use Provisioner\OpenAgendaImporter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

/**
 * Drives {@see EventsRefreshCommand} with mocked psql processes and HTTP feeds, the way
 * {@see ProvisionCommandTest} drives the zone-open command.
 *
 * The behaviour under test is the orchestration (ADR-051 §4): the command discovers the
 * open zones from the registry, downloads each feed once, upsert-and-purges each zone in
 * turn, and reports per source with continue-on-error. The upsert/purge SQL itself has its
 * own execution test ({@see EventsPromotionExecutionTest}).
 */
final class EventsRefreshCommandTest extends TestCase
{
    private string $tmpDir;

    /**
     * @var list<string>
     */
    private array $dtCommands = [];

    /**
     * @var list<string>
     */
    private array $oaCommands = [];

    private int $dtDownloads = 0;

    private string|false $previousFluxId;

    private string|false $previousAppKey;

    private string|false $previousDataset;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/events-refresh-'.uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);

        // Keep the resolution deterministic: unless a test injects an importer, no source is
        // configured, so the env-backed resolvers must see nothing.
        $this->previousFluxId = getenv('DATATOURISME_FLUX_ID');
        $this->previousAppKey = getenv('DATATOURISME_APP_KEY');
        $this->previousDataset = getenv('OPENAGENDA_DATASET');
        putenv('DATATOURISME_FLUX_ID');
        putenv('DATATOURISME_APP_KEY');
        putenv('OPENAGENDA_DATASET');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*/*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->tmpDir.'/*') ?: [] as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }

        @rmdir($this->tmpDir);

        false === $this->previousFluxId ? putenv('DATATOURISME_FLUX_ID') : putenv('DATATOURISME_FLUX_ID='.$this->previousFluxId);
        false === $this->previousAppKey ? putenv('DATATOURISME_APP_KEY') : putenv('DATATOURISME_APP_KEY='.$this->previousAppKey);
        false === $this->previousDataset ? putenv('OPENAGENDA_DATASET') : putenv('OPENAGENDA_DATASET='.$this->previousDataset);
    }

    /**
     * A processFactory for the command's own zone discovery: writes the slugs to the
     * `\copy ... TO '<file>'` destination the command reads back.
     *
     * @param list<string> $zones
     */
    private function zoneDiscoveryFactory(array $zones): \Closure
    {
        return static function (array $command) use ($zones): Process {
            $joined = implode(' ', $command);
            if (1 === preg_match("/TO '([^']+open-zones[^']*)'/", $joined, $matches)) {
                file_put_contents($matches[1], '' === implode('', $zones) ? '' : implode("\n", $zones)."\n");
            }

            return new Process(['true']);
        };
    }

    private function dataTourismeImporter(bool $downloadFails = false): DataTourismeImporter
    {
        $response = $downloadFails
            ? static fn (): MockResponse => new MockResponse('nope', ['http_code' => 500])
            : function (): MockResponse {
                ++$this->dtDownloads;

                return new MockResponse($this->emptyFluxZip());
            };

        return new DataTourismeImporter(
            fluxUrl: 'https://diffuseur.datatourisme.fr/webservice/flux/key',
            httpClient: new MockHttpClient($response),
            processFactory: function (array $command): Process {
                $this->dtCommands[] = implode(' ', $command);

                return new Process(['true']);
            },
        );
    }

    private function openAgendaImporter(): OpenAgendaImporter
    {
        return new OpenAgendaImporter(
            exportUrl: 'https://public.opendatasoft.com/x',
            httpClient: new MockHttpClient(new MockResponse("\n")),
            processFactory: function (array $command): Process {
                $this->oaCommands[] = implode(' ', $command);

                return new Process(['true']);
            },
        );
    }

    /**
     * @param list<string> $zones
     */
    private function tester(
        array $zones,
        ?DataTourismeImporter $dataTourisme = null,
        ?OpenAgendaImporter $openAgenda = null,
    ): CommandTester {
        $command = new EventsRefreshCommand(
            dataTourismeDir: $this->tmpDir.'/datatourisme',
            dataTourismeImporter: $dataTourisme,
            openAgendaDir: $this->tmpDir.'/openagenda',
            openAgendaImporter: $openAgenda,
            workDir: $this->tmpDir.'/work',
            lockFile: $this->tmpDir.'/provision.lock',
            logFile: $this->tmpDir.'/provisioner.log',
            processFactory: $this->zoneDiscoveryFactory($zones),
            today: '2026-07-15',
        );

        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('events-refresh'));
    }

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
    public function refreshesEveryOpenZoneFromEachConfiguredSource(): void
    {
        $tester = $this->tester(['bretagne', 'normandie'], $this->dataTourismeImporter(), $this->openAgendaImporter());

        self::assertSame(0, $tester->execute([], ['interactive' => false]), $tester->getDisplay());

        $output = $tester->getDisplay();
        self::assertStringContainsString('events ending before 2026-07-15 are dropped', $output);
        self::assertStringContainsString('Zones: bretagne, normandie', $output);

        // The national flux is downloaded once, not once per zone.
        self::assertSame(1, $this->dtDownloads, 'the feed is downloaded once for all zones');

        // The events-only refresh staging schema is loaded, and only its events table — no
        // place tables, so the command writes tourism.events alone.
        $ddl = array_values(array_filter($this->dtCommands, static fn (string $c): bool => str_contains($c, 'CREATE SCHEMA tourism_events_refresh')));
        self::assertCount(1, $ddl, 'the refresh staging schema is created once');
        self::assertStringContainsString('CREATE TABLE tourism_events_refresh.events', $ddl[0]);
        self::assertStringNotContainsString('cultural_pois', $ddl[0]);
        self::assertStringNotContainsString('accommodations', $ddl[0]);
        foreach ($this->dtCommands as $command) {
            self::assertStringNotContainsString('INSERT INTO tourism.metadata', $command, 'the refresh never touches the metadata snapshot');
        }

        // Each zone is upsert-and-purged from that one staging schema.
        $upserts = array_values(array_filter($this->dtCommands, static fn (string $c): bool => \str_contains($c, 'ON CONFLICT (id) DO UPDATE')));
        self::assertCount(2, $upserts, 'one upsert per open zone');
        self::assertStringContainsString("slug = 'bretagne'", implode("\n", $upserts));
        self::assertStringContainsString("slug = 'normandie'", implode("\n", $upserts));
        self::assertStringContainsString("end_date < '2026-07-15'::date", implode("\n", $upserts), 'the pinned purge date reaches the SQL');

        self::assertMatchesRegularExpression('/\x{2713}\s*datatourisme/u', $output);
        self::assertMatchesRegularExpression('/\x{2713}\s*openagenda/u', $output);
    }

    #[Test]
    public function refreshesASingleZoneWhenAsked(): void
    {
        $tester = $this->tester(['bretagne', 'normandie'], $this->dataTourismeImporter());

        self::assertSame(0, $tester->execute(['--zone' => 'normandie'], ['interactive' => false]), $tester->getDisplay());

        $upserts = array_values(array_filter($this->dtCommands, static fn (string $c): bool => str_contains($c, 'ON CONFLICT (id) DO UPDATE')));
        self::assertCount(1, $upserts, 'only the requested zone is refreshed');
        self::assertStringContainsString("slug = 'normandie'", $upserts[0]);
    }

    #[Test]
    public function anUnknownZoneOptionFails(): void
    {
        $tester = $this->tester(['bretagne'], $this->dataTourismeImporter());

        self::assertSame(1, $tester->execute(['--zone' => 'picardie'], ['interactive' => false]));
        self::assertStringContainsString('is not open', $tester->getDisplay());
        self::assertSame([], $this->dtCommands, 'nothing is downloaded when the zone is refused');
    }

    #[Test]
    public function dryRunListsTheZonesWithoutDownloadingAnything(): void
    {
        $tester = $this->tester(['bretagne', 'normandie'], $this->dataTourismeImporter(), $this->openAgendaImporter());

        self::assertSame(0, $tester->execute(['--dry-run' => true], ['interactive' => false]), $tester->getDisplay());

        $output = $tester->getDisplay();
        self::assertStringContainsString('Zones: bretagne, normandie', $output);
        self::assertStringContainsString('Dry run', $output);
        self::assertSame(0, $this->dtDownloads, 'a dry run downloads nothing');
        self::assertSame([], $this->dtCommands);
        self::assertSame([], $this->oaCommands);
    }

    #[Test]
    public function noOpenZoneIsAWarningNotAFailure(): void
    {
        $tester = $this->tester([], $this->dataTourismeImporter());

        self::assertSame(0, $tester->execute([], ['interactive' => false]), $tester->getDisplay());
        self::assertStringContainsString('No open zone to refresh', $tester->getDisplay());
        self::assertSame([], $this->dtCommands);
    }

    #[Test]
    public function withNoConfiguredSourceItWarnsAndSucceeds(): void
    {
        // No importer injected and no env: both resolvers return null, so there is nothing to
        // refresh — a no-op, not a failure (ADR-041).
        $tester = $this->tester(['bretagne']);

        self::assertSame(0, $tester->execute([], ['interactive' => false]), $tester->getDisplay());
        self::assertStringContainsString('No events source is configured', $tester->getDisplay());
    }

    #[Test]
    public function aFailedSourceDoesNotAbortTheOther(): void
    {
        // DataTourisme's download fails; OpenAgenda still refreshes. The run reports the
        // failure (exit 1) without masking the source that worked (ADR-041 continue-on-error).
        $tester = $this->tester(['bretagne'], $this->dataTourismeImporter(downloadFails: true), $this->openAgendaImporter());

        self::assertSame(1, $tester->execute([], ['interactive' => false]));

        $output = $tester->getDisplay();
        self::assertStringContainsString('datatourisme feed download/parse failed', $output);
        // OpenAgenda still ran its per-zone upsert.
        $upserts = array_values(array_filter($this->oaCommands, static fn (string $c): bool => str_contains($c, 'ON CONFLICT (id) DO UPDATE')));
        self::assertCount(1, $upserts, 'the surviving source still refreshed its zone');
        self::assertMatchesRegularExpression('/\x{2717}\s*datatourisme/u', $output);
        self::assertMatchesRegularExpression('/\x{2713}\s*openagenda/u', $output);
    }
}
