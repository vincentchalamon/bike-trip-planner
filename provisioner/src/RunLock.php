<?php

declare(strict_types=1);

namespace Provisioner;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The exclusive, non-blocking file lock `provision` and `events-refresh` share, held for the
 * whole run: two runs writing the same zone would race on its staging schema (ADR-041). The OS
 * releases it when the process ends, including a crash, so a killed run never leaves a stale
 * lock behind.
 */
final class RunLock
{
    /**
     * @var resource|null
     */
    private $handle;

    public function __construct(
        private readonly string $path,
        private readonly ProvisionerLog $log,
    ) {
    }

    public function acquire(SymfonyStyle $io): bool
    {
        $handle = @fopen($this->path, 'c');
        if (false === $handle) {
            // No lock file location (e.g. /data not mounted): proceed rather than block
            // provisioning on an inability to lock.
            $io->warning(\sprintf('Cannot open lock file "%s"; proceeding without a concurrency lock.', $this->path));

            return true;
        }

        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);
            $message = 'Another provisioning run is already in progress; aborting.';
            $io->error($message);
            $this->log->line('ERROR', $message);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if (\is_resource($this->handle)) {
            flock($this->handle, \LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
