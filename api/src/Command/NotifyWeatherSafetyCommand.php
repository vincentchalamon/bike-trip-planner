<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\WeatherSafetyNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pushes the weather-safety notification for the stages ridden on a target day (#1124).
 *
 * No Symfony Scheduler exists in this project, so this is the documented trigger
 * point: schedule it (cron / Coolify scheduled task) twice a day —
 *   app:notifications:weather-safety --day=tomorrow   # the evening before
 *   app:notifications:weather-safety --day=today       # the morning of
 * Owners who disabled the category, or have no registered device, are skipped.
 */
#[AsCommand(
    name: 'app:notifications:weather-safety',
    description: 'Push weather + safety notifications for the stages ridden on a target day',
)]
final readonly class NotifyWeatherSafetyCommand
{
    public function __construct(
        private WeatherSafetyNotifier $notifier,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Target day: today | tomorrow | an ISO date (Y-m-d)')]
        string $day = 'today',
    ): int {
        $date = match ($day) {
            'today' => new \DateTimeImmutable('today', new \DateTimeZone('UTC')),
            'tomorrow' => new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')),
            default => $this->parseIsoDate($day),
        };

        if (!$date instanceof \DateTimeImmutable) {
            $io->error(\sprintf('Invalid --day value: %s. Expected today, tomorrow, or an ISO date (Y-m-d).', $day));

            return Command::INVALID;
        }

        $count = $this->notifier->notify($date);
        $io->success(\sprintf('Dispatched %d weather-safety push(es) for %s.', $count, $date->format('Y-m-d')));

        return Command::SUCCESS;
    }

    /**
     * Strict ISO parse: createFromFormat silently rolls over impossible dates
     * (2026-13-40), so reject any value that does not round-trip to itself.
     */
    private function parseIsoDate(string $day): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));

        return false !== $date && $date->format('Y-m-d') === $day ? $date : null;
    }
}
