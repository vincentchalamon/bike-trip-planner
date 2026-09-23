<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\IdempotencyKeyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drops idempotency keys past their retention window.
 *
 * A key is a memory of a request, not a record of anything: past the window a replay is no
 * longer a retry, it is a new intent, and the row would only keep a trip alive in an index
 * nobody reads. Twenty-four hours is what the header's draft suggests.
 */
#[AsCommand(name: 'app:idempotency:purge', description: 'Delete idempotency keys older than the retention window')]
final class PurgeIdempotencyKeysCommand extends Command
{
    private const string RETENTION = '-24 hours';

    public function __construct(
        private readonly IdempotencyKeyRepository $keys,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cutoff = new \DateTimeImmutable(self::RETENTION);
        $deleted = $this->keys->purgeOlderThan($cutoff);

        (new SymfonyStyle($input, $output))->success(\sprintf(
            'Deleted %d idempotency key(s) recorded before %s.',
            $deleted,
            $cutoff->format(\DateTimeInterface::ATOM),
        ));

        return Command::SUCCESS;
    }
}
