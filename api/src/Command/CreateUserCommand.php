<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use App\Entity\MagicLink;
use App\Entity\User;
use App\Repository\MagicLinkRepository;
use App\Repository\UserRepository;
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
    private const array SUPPORTED_LOCALES = ['fr', 'en'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MagicLinkRepository $magicLinkRepository,
        private readonly UserRepository $userRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        #[Autowire(env: 'FRONTEND_URL')]
        private readonly string $frontendUrl,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of the new user')
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED, 'Locale for the invitation email (fr, en)', 'fr');
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

        $locale = $input->getOption('locale');
        \assert(\is_string($locale));

        if (!\in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $io->error(\sprintf('Unsupported locale "%s". Supported: %s', $locale, implode(', ', self::SUPPORTED_LOCALES)));

            return Command::FAILURE;
        }

        $existingUser = $this->userRepository->findByEmail($email);

        if ($existingUser instanceof User) {
            $io->error(\sprintf('User with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $user = new User($email);
        $user->setLocale($locale);

        // Persist but do NOT flush yet — defer until after the email send so that
        // an SMTP failure does not leave an orphaned user without an invitation.
        $this->entityManager->persist($user);

        // Create magic link for invitation
        $magicLink = $this->magicLinkRepository->create($user);

        if (!$magicLink instanceof MagicLink) {
            $io->warning(\sprintf('An active invitation link already exists for user %s.', $email));

            return Command::SUCCESS;
        }

        $verifyUrl = \sprintf('%s/auth/verify/%s', rtrim($this->frontendUrl, '/'), $magicLink->getToken());

        $html = $this->twig->render('email/invitation.html.twig', [
            'verifyUrl' => $verifyUrl,
            'expiresInMinutes' => MagicLinkRepository::TTL_MINUTES,
            'locale' => $locale,
        ]);

        $emailMessage = new Email()
            ->to($email)
            ->subject($this->translator->trans('auth.email.invitation.subject', [], 'auth', $locale))
            ->html($html);

        // Send email before flush: if SMTP fails, the magic link is not persisted
        // and the user can retry immediately instead of being locked out.
        $this->mailer->send($emailMessage);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            $io->warning(\sprintf('An active invitation link already exists for user %s (concurrent creation).', $email));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('User created: %s (ID: %s). Invitation email sent.', $email, $user->getId()));

        return Command::SUCCESS;
    }
}
