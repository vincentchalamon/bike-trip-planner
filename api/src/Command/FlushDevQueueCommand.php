<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

#[AsCommand(
    name: 'app:flush-dev-queue',
    description: 'Flush Messenger queues and trip state cache (dev environment only)',
)]
final class FlushDevQueueCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $env,
        #[Autowire(service: 'messenger.transport.async')]
        private readonly ReceiverInterface $asyncTransport,
        #[Autowire(service: 'messenger.transport.failed')]
        private readonly ReceiverInterface $failedTransport,
        #[Autowire(service: 'cache.trip_state')]
        private readonly CacheItemPoolInterface $tripStateCache,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('dev' !== $this->env) {
            throw new \LogicException(\sprintf('The "%s" command can only be run in the dev environment (current: %s).', $this->getName() ?? 'app:flush-dev-queue', $this->env));
        }

        $application = $this->getApplication();
        if ($application instanceof Application) {
            $this->stopWorkers($application, $output, $io);
        } else {
            $io->note('Could not stop Messenger workers: command has no Application context. Drain may race active workers.');
        }

        // Note: workers may still be processing their current message; Redis visibility
        // timeouts prevent double-processing, but the queue may not be fully drained
        // until in-flight messages are ACKed/NACKed.
        $asyncCount = $this->drainTransport($this->asyncTransport);
        $io->success(\sprintf('Async queue flushed: %d message(s) removed.', $asyncCount));

        $failedCount = $this->drainTransport($this->failedTransport);
        $io->success(\sprintf('Failed queue flushed: %d message(s) removed.', $failedCount));

        $this->tripStateCache->clear();
        $io->success('Trip state cache cleared.');

        return Command::SUCCESS;
    }

    private function stopWorkers(Application $application, OutputInterface $output, SymfonyStyle $io): void
    {
        $io->section('Stopping Messenger workers…');

        $stopCommand = $application->find('messenger:stop-workers');
        $stopCommand->run(new ArrayInput([]), $output);

        $io->text('Stop signal sent. Workers will finish their current message and then exit.');
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
