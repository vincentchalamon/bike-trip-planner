<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RefreshToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Clock\ClockInterface;

/**
 * Purges expired refresh tokens that were never rotated or revoked.
 *
 * Magic links are deleted on consumption and cleaned up opportunistically
 * in MagicLinkRepository::create(). Only refresh tokens from abandoned
 * sessions (user never returns) accumulate and need periodic purging.
 *
 * Intended to be run via cron (e.g. daily):
 *   docker compose exec php bin/console app:purge-expired-tokens
 */
#[AsCommand(
    name: 'app:purge-expired-tokens',
    description: 'Purge expired refresh tokens from abandoned sessions',
)]
final readonly class PurgeExpiredTokensCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        /** @var int $deleted */
        $deleted = $this->entityManager->createQueryBuilder()
            ->delete(RefreshToken::class, 'rt')
            ->where('rt.expiresAt <= :now')
            ->setParameter('now', $this->clock->now())
            ->getQuery()
            ->execute();

        $io->success(\sprintf('Purged %d expired refresh token(s).', $deleted));

        return Command::SUCCESS;
    }
}
