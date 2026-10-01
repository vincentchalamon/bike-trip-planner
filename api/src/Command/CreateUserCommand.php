<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AccessRequest;
use App\Entity\MagicLink;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\MagicLinkRepository;
use App\Security\MagicLinkMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a new user (invite-only registration) and sends an invitation email
 * with a magic link for first login.
 */
#[AsCommand(
    name: 'app:create-user',
    description: 'Create a new user and send an invitation email',
)]
final readonly class CreateUserCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MagicLinkRepository $magicLinkRepository,
        private AccessRequestRepository $accessRequestRepository,
        private MagicLinkMailer $magicLinkMailer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email address of the new user')]
        string $email,
        #[Option('Locale for the invitation email', shortcut: 'l')]
        string $locale = 'fr',
        #[Option('Create the user without an invitation magic link or email (e.g. so a caller can drive /auth/request-link itself)')]
        bool $noInvite = false,
    ): int {
        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error(\sprintf('Invalid email address: %s', $email));

            return Command::FAILURE;
        }

        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (null !== $existingUser) {
            $io->error(\sprintf('User with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        if (!\in_array($locale, User::SUPPORTED_LOCALES, true)) {
            $io->error(\sprintf('Unsupported locale: %s. Supported locales: %s', $locale, implode(', ', User::SUPPORTED_LOCALES)));

            return Command::FAILURE;
        }

        $user = new User($email);
        $user->setLocale($locale);

        $this->entityManager->persist($user);

        // Remove corresponding AccessRequest if it exists (early-access workflow)
        $accessRequest = $this->accessRequestRepository->findByEmail($email);
        if ($accessRequest instanceof AccessRequest) {
            $this->entityManager->remove($accessRequest);
        }

        // --no-invite: create the account only. An invitation magic link stays active
        // for MagicLinkRepository::TTL_MINUTES and short-circuits AuthRequestLinkProcessor (hasActiveLinkForUser),
        // so a caller wanting to exercise /auth/request-link itself must skip it here.
        if ($noInvite) {
            $this->entityManager->flush();
            $io->success(\sprintf('User created: %s (ID: %s)', $email, $user->getId()));
            $io->info('Skipped invitation (--no-invite): no magic link created or emailed.');

            return Command::SUCCESS;
        }

        // Create magic link for invitation
        $magicLink = $this->magicLinkRepository->issue($user);

        if (!$magicLink instanceof MagicLink) {
            $this->entityManager->flush();
            $io->success(\sprintf('User created: %s (ID: %s)', $email, $user->getId()));
            $io->warning(\sprintf('An active invitation link already exists for user %s.', $email));

            return Command::SUCCESS;
        }

        $this->magicLinkMailer->sendInvitation($user, $magicLink);
        // The same flush commits the user and the access-request removal above.
        $this->magicLinkRepository->save($magicLink);

        $io->success(\sprintf('User created: %s (ID: %s)', $email, $user->getId()));
        $io->success('Invitation email sent.');

        return Command::SUCCESS;
    }
}
