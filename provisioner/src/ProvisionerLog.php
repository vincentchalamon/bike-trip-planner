<?php

declare(strict_types=1);

namespace Provisioner;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The persistent log both provisioning commands append to, so the detailed cause of a failure
 * (command + stderr) survives for later diagnosis even when the container logs are gone
 * (ADR-041).
 */
final readonly class ProvisionerLog
{
    public function __construct(
        private string $path,
    ) {
    }

    public function line(string $level, string $message): void
    {
        $line = \sprintf("[%s] [%s] %s\n", new \DateTimeImmutable()->format('Y-m-d H:i:s'), $level, $message);
        // Best-effort: never let logging failure mask the real outcome.
        @file_put_contents($this->path, $line, \FILE_APPEND);
    }

    /**
     * Reports a failure both to the console and to the log.
     */
    public function fail(SymfonyStyle $io, string $message): void
    {
        $io->error($message);
        $this->line('ERROR', $message);
    }

    /**
     * The closing summary, one line per source, on the console and in the log.
     *
     * @param array<string, int> $outcomes source label => Command exit code
     */
    public function summarize(SymfonyStyle $io, string $title, array $outcomes): void
    {
        $io->section($title);
        foreach ($outcomes as $source => $code) {
            $ok = Command::SUCCESS === $code;
            $io->writeln(\sprintf('  %s %s', $ok ? "\u{2713}" : "\u{2717}", $source));
            $this->line($ok ? 'INFO' : 'ERROR', \sprintf('source %s -> %s', $source, $ok ? 'ok' : 'failed'));
        }
    }
}
