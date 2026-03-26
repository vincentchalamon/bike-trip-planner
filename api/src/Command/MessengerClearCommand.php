<?php

declare(strict_types=1);

namespace App\Command;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Drains one or more Symfony Messenger transports by rejecting all pending envelopes.
 *
 * Intended for development/maintenance use only. Run via `make flush-queue` to ensure
 * workers are signalled first and the trip-state cache is cleared afterwards.
 */
#[AsCommand(
    name: 'app:messenger:clear',
    description: 'Clear messages from one or more Messenger transports',
)]
final class MessengerClearCommand extends Command
{
    /**
     * @param ServiceProviderInterface<ReceiverInterface> $receiverLocator
     */
    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ServiceProviderInterface $receiverLocator,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('transports', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Transport names to clear')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Clear all configured transports');
    }

    #[Override]
    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        /** @var string[] $transports */
        $transports = $input->getArgument('transports');

        if ([] !== $transports || $input->getOption('all')) {
            return;
        }

        $availableTransports = array_keys($this->receiverLocator->getProvidedServices());

        if ([] === $availableTransports) {
            return;
        }

        $io = new SymfonyStyle($input, $output);

        $chosen = $io->choice('Which transport do you want to clear?', $availableTransports);
        $input->setArgument('transports', [$chosen]);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string[] $transports */
        $transports = $input->getArgument('transports');
        $all = $input->getOption('all');

        $availableNames = array_keys($this->receiverLocator->getProvidedServices());

        if ($all && [] !== $transports) {
            $io->error('You cannot combine --all with explicit transport names.');

            return Command::FAILURE;
        }

        if ([] === $availableNames) {
            $io->error('No Messenger transports are configured.');

            return Command::FAILURE;
        }

        if ($all) {
            $transports = $availableNames;
        }

        if ([] === $transports) {
            $io->error('No transport specified. Use --all or provide transport names. In non-interactive mode, --all is required.');

            return Command::FAILURE;
        }

        foreach ($transports as $name) {
            if (!$this->receiverLocator->has($name)) {
                $io->error(\sprintf('Unknown transport "%s". Available: %s', $name, implode(', ', $availableNames)));

                return Command::FAILURE;
            }
        }

        foreach ($transports as $name) {
            $count = $this->drainTransport($this->receiverLocator->get($name));
            $io->success(\sprintf('Transport "%s" cleared: %d message(s) removed.', $name, $count));
        }

        return Command::SUCCESS;
    }

    #[Override]
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('transports')) {
            $suggestions->suggestValues(array_keys($this->receiverLocator->getProvidedServices()));
        }
    }

    private function drainTransport(ReceiverInterface $transport): int
    {
        $count = 0;

        do {
            $fetched = 0;
            foreach ($transport->get() as $envelope) {
                $transport->reject($envelope);
                ++$count;
                ++$fetched;
            }
        } while ($fetched > 0);

        return $count;
    }
}
