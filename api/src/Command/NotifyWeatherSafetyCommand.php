<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\WeatherSafetyNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
final class NotifyWeatherSafetyCommand extends Command
{
    public function __construct(
        private readonly WeatherSafetyNotifier $notifier,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('day', null, InputOption::VALUE_REQUIRED, 'Target day: today | tomorrow | an ISO date (Y-m-d)', 'today');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $day = $input->getOption('day');
        \assert(\is_string($day));

        $date = match ($day) {
            'today' => new \DateTimeImmutable('today', new \DateTimeZone('UTC')),
            'tomorrow' => new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')),
            default => \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC')) ?: null,
        };

        if (!$date instanceof \DateTimeImmutable) {
            $io->error(\sprintf('Invalid --day value: %s. Expected today, tomorrow, or an ISO date (Y-m-d).', $day));

            return Command::INVALID;
        }

        $count = $this->notifier->notify($date);
        $io->success(\sprintf('Dispatched %d weather-safety push(es) for %s.', $count, $date->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
