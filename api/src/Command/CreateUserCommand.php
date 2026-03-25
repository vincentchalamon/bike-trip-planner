<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Security\MagicLinkManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Creates a new user (invite-only registration) and sends an invitation email
 * with a magic link for first login.
 */
#[AsCommand(
    name: 'app:create-user',
    description: 'Create a new user and send an invitation email',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MagicLinkManager $magicLinkManager,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of the new user');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');

        if (!\is_string($email) || '' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error(\sprintf('Invalid email address: %s', (string) $email));

            return Command::FAILURE;
        }

        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if (null !== $existingUser) {
            $io->error(\sprintf('User with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $user = new User($email);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(\sprintf('User created: %s (ID: %s)', $email, $user->getId()));

        // Create magic link for invitation
        $magicLink = $this->magicLinkManager->create($user);

        if (!$magicLink instanceof \App\Entity\MagicLink) {
            $io->warning('Could not create invitation link.');

            return Command::SUCCESS;
        }

        $verifyUrl = $this->urlGenerator->generate(
            'auth_verify',
            ['token' => $magicLink->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('email/invitation.html.twig', [
            'verifyUrl' => $verifyUrl,
            'expiresInMinutes' => 30,
        ]);

        $emailMessage = new Email()
            ->to($email)
            ->subject('Invitation — Bike Trip Planner')
            ->html($html);

        $this->mailer->send($emailMessage);

        $io->success('Invitation email sent.');

        return Command::SUCCESS;
    }
}
