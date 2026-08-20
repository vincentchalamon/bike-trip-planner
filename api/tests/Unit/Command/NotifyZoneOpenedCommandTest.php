<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\NotifyZoneOpenedCommand;
use App\Notification\NotificationDispatcherInterface;
use App\Notification\ZoneOpeningNotifier;
use App\Repository\NotificationPreferenceRepositoryInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NotifyZoneOpenedCommandTest extends TestCase
{
    #[Test]
    public function usesTheExplicitNameAndSkipsTheZoneLookup(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchOne');

        $tester = new CommandTester($this->command($connection));
        $status = $tester->execute(['slug' => 'corse', '--name' => 'Corse']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Corse', $tester->getDisplay());
    }

    #[Test]
    public function fallsBackToTheZoneNameFromOsmZones(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('osm.zones'), ['slug' => 'corse'])
            ->willReturn('Corse');

        $tester = new CommandTester($this->command($connection));
        $status = $tester->execute(['slug' => 'corse']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Corse', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAnUnknownZoneWithoutAnExplicitName(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(false);

        $tester = new CommandTester($this->command($connection));
        $status = $tester->execute(['slug' => 'atlantis']);

        self::assertSame(Command::INVALID, $status);
        self::assertStringContainsString('Unknown zone', $tester->getDisplay());
    }

    private function command(Connection $connection): NotifyZoneOpenedCommand
    {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('findEnabledUsers')->willReturn([]);

        $notifier = new ZoneOpeningNotifier(
            $preferences,
            $this->createStub(NotificationDispatcherInterface::class),
            $this->createStub(TranslatorInterface::class),
        );

        return new NotifyZoneOpenedCommand($notifier, $connection);
    }
}
