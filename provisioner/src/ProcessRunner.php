<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the external commands (osm2pgsql, osmium, psql) the importers shell out to, with one
 * timeout and one way of turning a failure into an {@see ImportFailedException}.
 *
 * The factory is what tests replace to capture the commands instead of running them.
 */
final readonly class ProcessRunner
{
    /**
     * @var \Closure(list<string>): Process
     */
    private \Closure $factory;

    /**
     * @param (\Closure(list<string>): Process)|null $factory defaults to a real {@see Process}
     */
    public function __construct(
        ?\Closure $factory = null,
        private float $timeoutSeconds = 60.0,
    ) {
        $this->factory = $factory ?? static fn (array $command): Process => new Process($command);
    }

    /**
     * The process, timeout set and not started, for a caller that handles the outcome itself.
     *
     * @param list<string> $command
     */
    public function process(array $command): Process
    {
        $process = ($this->factory)($command);
        $process->setTimeout($this->timeoutSeconds);

        return $process;
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @throws ImportFailedException
     */
    public function run(array $command, string $label, array $env = []): void
    {
        $process = $this->process($command);
        if ([] !== $env) {
            $process->setEnv($env);
        }

        $this->execute($process, $label, "\nCommand: ".implode(' ', $command));
    }

    /**
     * One `psql -c`. The failure message leaves the SQL out: it is long, and the label already
     * says which statement it was.
     *
     * @throws ImportFailedException
     */
    public function psql(string $sql, string $label, bool $singleTransaction = false): void
    {
        $command = $singleTransaction
            ? ['psql', '-v', 'ON_ERROR_STOP=1', '--single-transaction', '-c', $sql]
            : ['psql', '-v', 'ON_ERROR_STOP=1', '-c', $sql];

        $this->execute($this->process($command), $label, '');
    }

    /**
     * @throws ImportFailedException
     */
    private function execute(Process $process, string $label, string $commandLine): void
    {
        try {
            $process->run();
        } catch (ProcessTimedOutException $processTimedOutException) {
            throw new ImportFailedException(\sprintf('%s timed out after %.1fs', $label, $this->timeoutSeconds), 0, $processTimedOutException);
        } catch (ProcessExceptionInterface $processException) {
            throw new ImportFailedException(\sprintf('%s failed: %s', $label, $processException->getMessage()), 0, $processException);
        }

        if (!$process->isSuccessful()) {
            throw new ImportFailedException(\sprintf("%s failed (exit %s).%s\nStderr: %s", $label, (string) $process->getExitCode(), $commandLine, $process->getErrorOutput()));
        }
    }
}
