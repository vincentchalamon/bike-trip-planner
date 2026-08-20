<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\NotifyWeatherSafetyCommand;
use App\Notification\NotificationDispatcherInterface;
use App\Notification\WeatherSafetyNotifier;
use App\Repository\OwnedTripFinderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NotifyWeatherSafetyCommandTest extends TestCase
{
    #[Test]
    public function todayIsAccepted(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute(['--day' => 'today']));
        self::assertStringContainsString('Dispatched 0 weather-safety', $tester->getDisplay());
    }

    #[Test]
    public function tomorrowIsAccepted(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute(['--day' => 'tomorrow']));
    }

    #[Test]
    public function anIsoDateIsAccepted(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute(['--day' => '2026-08-20']));
        self::assertStringContainsString('2026-08-20', $tester->getDisplay());
    }

    #[Test]
    public function aBogusDayIsRejected(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute(['--day' => 'nope']));
        self::assertStringContainsString('Invalid --day', $tester->getDisplay());
    }

    #[Test]
    public function anImpossibleIsoDateIsRejected(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute(['--day' => '2026-13-40']));
    }

    private function command(): NotifyWeatherSafetyCommand
    {
        $finder = $this->createStub(OwnedTripFinderInterface::class);
        $finder->method('findOwnedTripsCoveringDate')->willReturn([]);

        $notifier = new WeatherSafetyNotifier(
            $finder,
            $this->createStub(NotificationDispatcherInterface::class),
            $this->createStub(TranslatorInterface::class),
        );

        return new NotifyWeatherSafetyCommand($notifier);
    }
}
