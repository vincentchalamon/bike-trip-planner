<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\RefreshToken;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Clock\ClockInterface;

/**
 * Purges expired refresh tokens that were never rotated or revoked.
 *
 * A rotated token expires 30 seconds after its rotation, but its row is what lets
 * AuthRefreshProcessor recognise a replay and revoke the family. It is therefore kept for as
 * long as the successor it pointed at can live, and only purged after that.
 *
 * Magic links are deleted on consumption and cleaned up opportunistically
 * in MagicLinkRepository::issue(). Only refresh tokens from abandoned
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
        $now = $this->clock->now();

        /** @var int $deleted */
        $deleted = $this->entityManager->createQueryBuilder()
            ->delete(RefreshToken::class, 'rt')
            ->where('rt.replacedByToken IS NULL AND rt.expiresAt <= :now')
            ->orWhere('rt.replacedByToken IS NOT NULL AND rt.expiresAt <= :rotatedCutoff')
            ->setParameter('now', $now)
            ->setParameter('rotatedCutoff', $now->modify(\sprintf('-%d days', RefreshTokenRepository::TTL_DAYS)))
            ->getQuery()
            ->execute();

        $io->success(\sprintf('Purged %d expired refresh token(s).', $deleted));

        return Command::SUCCESS;
    }
}
