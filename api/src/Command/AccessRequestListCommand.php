<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AccessRequestRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
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
final readonly class AccessRequestListCommand
{
    public function __construct(
        private AccessRequestRepository $accessRequestRepository,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Filter requests verified before this date (ISO 8601)')]
        ?string $before = null,
        #[Option('Filter requests verified after this date (ISO 8601)')]
        ?string $after = null,
        #[Option('Filter by email pattern (substring match)')]
        ?string $email = null,
        #[Option('Page number (default: 1)')]
        string $page = '1',
        #[Option('Results per page (default: 20)')]
        string $limit = '20',
    ): int {
        try {
            $beforeDate = $this->parseDate('before', $before);
            $afterDate = $this->parseDate('after', $after);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $io->error($invalidArgumentException->getMessage());

            return Command::FAILURE;
        }

        $pageNumber = max(1, (int) $page);
        $pageSize = max(1, min(100, (int) $limit));

        $requests = $this->accessRequestRepository->findVerified(
            before: $beforeDate,
            after: $afterDate,
            emailPattern: '' === $email ? null : $email,
            page: $pageNumber,
            limit: $pageSize,
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

        $io->success(\sprintf('Found %d verified access request(s) (page %d, limit %d).', \count($requests), $pageNumber, $pageSize));

        return Command::SUCCESS;
    }

    /**
     * @throws \InvalidArgumentException when the value is neither ATOM nor Y-m-d
     */
    private function parseDate(string $option, ?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d', $value)
            ?: throw new \InvalidArgumentException(\sprintf('Invalid --%s date format: %s. Expected ISO 8601 (e.g. 2026-01-15 or 2026-01-15T00:00:00+00:00).', $option, $value));
    }
}
