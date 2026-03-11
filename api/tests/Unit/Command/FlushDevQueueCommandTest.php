<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\FlushDevQueueCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class FlushDevQueueCommandTest extends TestCase
{
    #[Test]
    public function throwsWhenNotInDevEnvironment(): void
    {
        $command = new FlushDevQueueCommand(
            env: 'prod',
            asyncTransport: $this->createStub(ReceiverInterface::class),
            failedTransport: $this->createStub(ReceiverInterface::class),
            tripStateCache: $this->createStub(CacheItemPoolInterface::class),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/can only be run in the dev environment/');

        $tester = new CommandTester($command);
        $tester->execute([]);
    }

    #[Test]
    public function flushesQueuesAndClearsCacheInDevEnvironment(): void
    {
        $envelope = new Envelope(new \stdClass());

        $asyncTransport = $this->createMock(ReceiverInterface::class);
        $asyncTransport->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls([$envelope], []);
        $asyncTransport->expects($this->once())
            ->method('reject')
            ->with($envelope);

        $failedTransport = $this->createMock(ReceiverInterface::class);
        $failedTransport->expects($this->once())
            ->method('get')
            ->willReturn([]);
        $failedTransport->expects($this->never())
            ->method('reject');

        $cache = new ArrayAdapter();

        $command = new FlushDevQueueCommand(
            env: 'dev',
            asyncTransport: $asyncTransport,
            failedTransport: $failedTransport,
            tripStateCache: $cache,
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Async queue flushed: 1 message(s) removed.', $tester->getDisplay());
        $this->assertStringContainsString('Failed queue flushed: 0 message(s) removed.', $tester->getDisplay());
        $this->assertStringContainsString('Trip state cache cleared.', $tester->getDisplay());
    }

    #[Test]
    public function stopsWorkersWhenApplicationIsAvailable(): void
    {
        $asyncTransport = $this->createStub(ReceiverInterface::class);
        $asyncTransport->method('get')->willReturn([]);

        $failedTransport = $this->createStub(ReceiverInterface::class);
        $failedTransport->method('get')->willReturn([]);

        $command = new FlushDevQueueCommand(
            env: 'dev',
            asyncTransport: $asyncTransport,
            failedTransport: $failedTransport,
            tripStateCache: new ArrayAdapter(),
        );

        $stopCommand = new class () extends Command {
            public bool $ran = false;

            public function __construct()
            {
                parent::__construct('messenger:stop-workers');
            }

            #[\Override]
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                $this->ran = true;

                return Command::SUCCESS;
            }
        };

        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand($stopCommand);
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($stopCommand->ran, 'messenger:stop-workers command should have been executed');
        $this->assertStringContainsString('Stop signal sent', $tester->getDisplay());
    }

    #[Test]
    public function drainsEmptyQueuesGracefully(): void
    {
        $asyncTransport = $this->createMock(ReceiverInterface::class);
        $asyncTransport->expects($this->once())
            ->method('get')
            ->willReturn([]);
        $asyncTransport->expects($this->never())
            ->method('reject');

        $failedTransport = $this->createMock(ReceiverInterface::class);
        $failedTransport->expects($this->once())
            ->method('get')
            ->willReturn([]);
        $failedTransport->expects($this->never())
            ->method('reject');

        $command = new FlushDevQueueCommand(
            env: 'dev',
            asyncTransport: $asyncTransport,
            failedTransport: $failedTransport,
            tripStateCache: new ArrayAdapter(),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Async queue flushed: 0 message(s) removed.', $tester->getDisplay());
        $this->assertStringContainsString('Failed queue flushed: 0 message(s) removed.', $tester->getDisplay());
    }
}
