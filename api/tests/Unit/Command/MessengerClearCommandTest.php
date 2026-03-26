<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use stdClass;
use Closure;
use App\Command\MessengerClearCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

final class MessengerClearCommandTest extends TestCase
{
    #[Test]
    public function clearsSpecificTransportByName(): void
    {
        $envelope = new Envelope(new stdClass());

        $asyncTransport = $this->createMock(ReceiverInterface::class);
        $asyncTransport->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls([$envelope], []);
        $asyncTransport->expects($this->once())
            ->method('reject')
            ->with($envelope);

        $failedTransport = $this->createMock(ReceiverInterface::class);
        $failedTransport->expects($this->never())->method('get');

        $command = new MessengerClearCommand($this->createReceiverLocator([
            'async' => $asyncTransport,
            'failed' => $failedTransport,
        ]));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['transports' => ['async']]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Transport "async" cleared: 1 message(s) removed.', $tester->getDisplay());
    }

    #[Test]
    public function clearsAllTransportsWithAllFlag(): void
    {
        $asyncTransport = $this->createMock(ReceiverInterface::class);
        $asyncTransport->expects($this->once())->method('get')->willReturn([]);

        $failedTransport = $this->createMock(ReceiverInterface::class);
        $failedTransport->expects($this->once())->method('get')->willReturn([]);

        $command = new MessengerClearCommand($this->createReceiverLocator([
            'async' => $asyncTransport,
            'failed' => $failedTransport,
        ]));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--all' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Transport "async" cleared: 0 message(s) removed.', $tester->getDisplay());
        $this->assertStringContainsString('Transport "failed" cleared: 0 message(s) removed.', $tester->getDisplay());
    }

    #[Test]
    public function clearsMultipleTransportsByName(): void
    {
        $asyncEnvelope = new Envelope(new stdClass());
        $failedEnvelope = new Envelope(new stdClass());

        $asyncTransport = $this->createMock(ReceiverInterface::class);
        $asyncTransport->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls([$asyncEnvelope], []);
        $asyncTransport->expects($this->once())->method('reject')->with($asyncEnvelope);

        $failedTransport = $this->createMock(ReceiverInterface::class);
        $failedTransport->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls([$failedEnvelope], []);
        $failedTransport->expects($this->once())->method('reject')->with($failedEnvelope);

        $command = new MessengerClearCommand($this->createReceiverLocator([
            'async' => $asyncTransport,
            'failed' => $failedTransport,
        ]));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['transports' => ['async', 'failed']]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Transport "async" cleared: 1 message(s) removed.', $tester->getDisplay());
        $this->assertStringContainsString('Transport "failed" cleared: 1 message(s) removed.', $tester->getDisplay());
    }

    #[Test]
    public function drainsEmptyTransportGracefully(): void
    {
        $asyncTransport = $this->createMock(ReceiverInterface::class);
        $asyncTransport->expects($this->once())->method('get')->willReturn([]);
        $asyncTransport->expects($this->never())->method('reject');

        $command = new MessengerClearCommand($this->createReceiverLocator([
            'async' => $asyncTransport,
        ]));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['transports' => ['async']]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Transport "async" cleared: 0 message(s) removed.', $tester->getDisplay());
    }

    #[Test]
    public function interactiveChoiceWhenNoTransportSpecified(): void
    {
        $asyncTransport = $this->createMock(ReceiverInterface::class);
        $asyncTransport->expects($this->once())->method('get')->willReturn([]);

        $failedTransport = $this->createMock(ReceiverInterface::class);
        $failedTransport->expects($this->never())->method('get');

        $command = new MessengerClearCommand($this->createReceiverLocator([
            'async' => $asyncTransport,
            'failed' => $failedTransport,
        ]));

        $tester = new CommandTester($command);
        $tester->setInputs(['async']);

        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Transport "async" cleared: 0 message(s) removed.', $tester->getDisplay());
    }

    #[Test]
    public function failsWhenNoTransportInNonInteractiveMode(): void
    {
        $command = new MessengerClearCommand($this->createReceiverLocator([
            'async' => $this->createStub(ReceiverInterface::class),
        ]));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('No transport specified', $tester->getDisplay());
    }

    #[Test]
    public function failsWhenUnknownTransportSpecified(): void
    {
        $command = new MessengerClearCommand($this->createReceiverLocator([
            'async' => $this->createStub(ReceiverInterface::class),
        ]));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['transports' => ['nonexistent']]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Unknown transport "nonexistent"', $tester->getDisplay());
    }

    #[Test]
    public function failsWhenAllAndExplicitTransportsCombined(): void
    {
        $command = new MessengerClearCommand($this->createReceiverLocator([
            'async' => $this->createStub(ReceiverInterface::class),
        ]));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['transports' => ['async'], '--all' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('cannot combine --all', $tester->getDisplay());
    }

    #[Test]
    public function errorsWhenNoTransportsConfiguredInInteractiveMode(): void
    {
        $command = new MessengerClearCommand($this->createReceiverLocator([]));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('No Messenger transports are configured.', $tester->getDisplay());
    }

    /**
     * @param array<string, ReceiverInterface> $transports
     *
     * @return ServiceLocator<ReceiverInterface>
     */
    private function createReceiverLocator(array $transports): ServiceLocator
    {
        /** @var ServiceLocator<ReceiverInterface> $locator */
        $locator = new ServiceLocator(array_map(fn (ReceiverInterface $t): Closure => fn (): ReceiverInterface => $t, $transports));

        return $locator;
    }
}
