<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AccessRequest;
use App\Entity\MagicLink;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\MagicLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly MagicLinkRepository $magicLinkRepository,
        private readonly AccessRequestRepository $accessRequestRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        #[Autowire(env: 'FRONTEND_URL')]
        private readonly string $frontendUrl = 'https://localhost',
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of the new user')
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED, 'Locale for the invitation email', 'fr');
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

        $locale = $input->getOption('locale');
        \assert(\is_string($locale));

        $supportedLocales = ['fr', 'en'];

        if (!\in_array($locale, $supportedLocales, true)) {
            $io->error(\sprintf('Unsupported locale: %s. Supported locales: %s', $locale, implode(', ', $supportedLocales)));

            return Command::FAILURE;
        }

        $user = new User($email);
        $user->setLocale($locale);

        $this->entityManager->persist($user);

        // Remove corresponding AccessRequest if it exists (early-access workflow)
        $accessRequest = $this->entityManager->getRepository(AccessRequest::class)->findOneBy(['email' => $email]);
        if ($accessRequest instanceof AccessRequest) {
            $this->entityManager->remove($accessRequest);
        }

        // Create magic link for invitation
        $magicLink = $this->magicLinkRepository->create($user);

        if (!$magicLink instanceof MagicLink) {
            $this->entityManager->flush();
            $io->success(\sprintf('User created: %s (ID: %s)', $email, $user->getId()));
            $io->warning(\sprintf('An active invitation link already exists for user %s.', $email));

            return Command::SUCCESS;
        }

        $verifyUrl = \sprintf('%s/auth/verify/%s', rtrim($this->frontendUrl, '/'), $magicLink->getToken());

        $html = $this->twig->render('email/invitation.html.twig', [
            'verifyUrl' => $verifyUrl,
            'expiresInMinutes' => 30,
            'locale' => $locale,
        ]);

        $emailMessage = new Email()
            ->from(new Address('noreply@bike-trip-planner.com', 'Bike Trip Planner'))
            ->to($email)
            ->subject($this->translator->trans('auth.email.invitation.subject', [], 'auth', $locale))
            ->html($html);

        $this->mailer->send($emailMessage);
        $this->entityManager->flush();

        $io->success(\sprintf('User created: %s (ID: %s)', $email, $user->getId()));
        $io->success('Invitation email sent.');

        return Command::SUCCESS;
    }
}
