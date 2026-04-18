<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AccessRequestRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists verified access requests with optional filtering and pagination.
 *
 * Usage:
 *   app:access-request:list [--before=DATE] [--after=DATE] [--email=PATTERN] [--page=N] [--limit=N]
 */
#[AsCommand(
    name: 'app:access-request:list',
    description: 'List verified access requests',
)]
final class AccessRequestListCommand extends Command
{
    public function __construct(
        private readonly AccessRequestRepository $accessRequestRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Filter requests verified before this date (ISO 8601)')
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Filter requests verified after this date (ISO 8601)')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Filter by email pattern (substring match)')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number (default: 1)', '1')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Results per page (default: 20)', '20');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $before = null;
        $after = null;

        $beforeStr = $input->getOption('before');
        if (\is_string($beforeStr) && '' !== $beforeStr) {
            $before = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $beforeStr)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d', $beforeStr) ?: null;

            if (null === $before) {
                $io->error(\sprintf('Invalid --before date format: %s. Expected ISO 8601 (e.g. 2026-01-15 or 2026-01-15T00:00:00+00:00).', $beforeStr));

                return Command::FAILURE;
            }
        }

        $afterStr = $input->getOption('after');
        if (\is_string($afterStr) && '' !== $afterStr) {
            $after = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $afterStr)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d', $afterStr) ?: null;

            if (null === $after) {
                $io->error(\sprintf('Invalid --after date format: %s. Expected ISO 8601 (e.g. 2026-01-15 or 2026-01-15T00:00:00+00:00).', $afterStr));

                return Command::FAILURE;
            }
        }

        $emailPattern = $input->getOption('email');
        if (!\is_string($emailPattern) || '' === $emailPattern) {
            $emailPattern = null;
        }

        $page = max(1, (int) $input->getOption('page'));
        $limit = max(1, min(100, (int) $input->getOption('limit')));

        $requests = $this->accessRequestRepository->findVerified(
            before: $before,
            after: $after,
            emailPattern: $emailPattern,
            page: $page,
            limit: $limit,
        );

        if ([] === $requests) {
            $io->info('No verified access requests found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($requests as $accessRequest) {
            $rows[] = [
                $accessRequest->getId()->toRfc4122(),
                $accessRequest->getEmail(),
                $accessRequest->getIp(),
                $accessRequest->getVerifiedAt()?->format('Y-m-d H:i:s') ?? '-',
                $accessRequest->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }

        $io->table(
            ['ID', 'Email', 'IP', 'Verified At', 'Created At'],
            $rows,
        );

        $io->success(\sprintf('Found %d verified access request(s) (page %d, limit %d).', \count($requests), $page, $limit));

        return Command::SUCCESS;
    }
}
