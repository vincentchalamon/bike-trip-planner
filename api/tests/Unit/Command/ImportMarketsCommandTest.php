<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ImportMarketsCommand;
use App\Entity\Market;
use App\Repository\MarketRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class ImportMarketsCommandTest extends TestCase
{
    private const string FIXTURE_CSV = <<<'CSV'
        id;Nom du marché;Geo Point;Jour;Heure début;Heure fin;Commune;Département
        MKT-001;Marché de la Bastille;48.8534,2.3699;lundi;08:00;13:00;Paris;75
        MKT-002;Marché de Noailles;43.2964,5.3820;mardi;07:30;12:30;Marseille;13
        MKT-003;Marché Victor Hugo;43.6047,1.4442;mercredi;07:00;13:30;Toulouse;31
        MKT-004;Marché des Capucins;44.8378,-0.5792;samedi;06:00;14:00;Bordeaux;33
        MKT-005;;INVALID_GEO;vendredi;;; ;
        CSV;

    /** @var MarketRepositoryInterface&MockObject */
    private MarketRepositoryInterface $marketRepository;

    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;

    /** @var HttpClientInterface&MockObject */
    private HttpClientInterface $httpClient;

    #[\Override]
    protected function setUp(): void
    {
        $this->marketRepository = $this->createMock(MarketRepositoryInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    private function createCommandWithFixtureCsv(string $csvContent): CommandTester
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'market_test_');
        file_put_contents($tmpFile, $csvContent);

        $response = $this->createStub(ResponseInterface::class);

        $chunk = $this->createStub(ChunkInterface::class);
        $chunk->method('getContent')->willReturn(file_get_contents($tmpFile) ?: '');
        $chunk->method('isLast')->willReturn(true);

        $stream = $this->createMock(ResponseStreamInterface::class);
        $stream->method('current')->willReturn($chunk);
        $stream->method('valid')->willReturnOnConsecutiveCalls(true, false);
        $stream->method('rewind')->willReturn(null);
        $stream->method('next')->willReturn(null);

        $this->httpClient->method('request')->willReturn($response);
        $this->httpClient->method('stream')->willReturn($stream);

        @unlink($tmpFile);

        $command = new ImportMarketsCommand(
            $this->marketRepository,
            $this->entityManager,
            new NullLogger(),
            $this->httpClient,
            'https://example.com/markets.csv',
        );

        return new CommandTester($command);
    }

    #[Test]
    public function insertsNewMarketsAndSkipsMissingGeo(): void
    {
        $this->marketRepository
            ->method('findByExternalId')
            ->willReturn(null);

        $this->marketRepository
            ->expects($this->exactly(4))
            ->method('save');

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $tester = $this->createCommandWithFixtureCsv(self::FIXTURE_CSV);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('4 inserted', $tester->getDisplay());
        $this->assertStringContainsString('0 updated', $tester->getDisplay());
        $this->assertStringContainsString('1 skipped', $tester->getDisplay());
    }

    #[Test]
    public function updatesExistingMarketsOnUpsert(): void
    {
        $existing = new Market('MKT-001', 'Old Name');
        $existing->setLat(0.0);
        $existing->setLon(0.0);
        $existing->setDayOfWeek(1);
        $existing->setCommune('Old');
        $existing->setDepartment('00');

        $this->marketRepository
            ->method('findByExternalId')
            ->willReturnCallback(static fn (string $id): ?Market => 'MKT-001' === $id ? $existing : null);

        $this->marketRepository
            ->expects($this->exactly(3))
            ->method('save');

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $tester = $this->createCommandWithFixtureCsv(self::FIXTURE_CSV);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('3 inserted', $tester->getDisplay());
        $this->assertStringContainsString('1 updated', $tester->getDisplay());

        $this->assertSame('Marché de la Bastille', $existing->getName());
        $this->assertSame(48.8534, $existing->getLat());
        $this->assertSame(1, $existing->getDayOfWeek());
        $this->assertSame('08:00', $existing->getStartTime());
        $this->assertSame('13:00', $existing->getEndTime());
    }

    #[Test]
    public function dryRunDoesNotWriteToDatabase(): void
    {
        $this->marketRepository
            ->method('findByExternalId')
            ->willReturn(null);

        $this->marketRepository
            ->expects($this->never())
            ->method('save');

        $this->entityManager
            ->expects($this->never())
            ->method('flush');

        $tester = $this->createCommandWithFixtureCsv(self::FIXTURE_CSV);
        $exitCode = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('4 inserted', $tester->getDisplay());
        $this->assertStringContainsString('1 skipped', $tester->getDisplay());
        $this->assertStringContainsString('Dry-run mode', $tester->getDisplay());
    }

    #[Test]
    public function limitOptionCapsProcessedRows(): void
    {
        $this->marketRepository
            ->method('findByExternalId')
            ->willReturn(null);

        $this->marketRepository
            ->expects($this->exactly(2))
            ->method('save');

        $tester = $this->createCommandWithFixtureCsv(self::FIXTURE_CSV);
        $exitCode = $tester->execute(['--limit' => '2']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('2 inserted', $tester->getDisplay());
    }
}
