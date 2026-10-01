<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logger;

use App\Logger\EmailFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * No log line carries an email address: a User is logged by its id, an address
 * without a User by {@see EmailFingerprint}. The guard reads every logger call in
 * api/src, context included however many lines it spans, so a new call that logs
 * an address fails here rather than in a log aggregator.
 */
final class NoEmailInLogsTest extends TestCase
{
    /**
     * An address reaches a log line as a getter call, an `email` context key, or
     * the conventional `$email` variable passed as is.
     */
    private const string LEAK = '/getEmail\(\)|[\'"]email[\'"]\s*=>|=>\s*\$email\b|\{\$email\}/';

    #[Test]
    public function noLoggerCallCarriesAnEmailAddress(): void
    {
        $leaks = [];
        foreach ($this->loggerCalls(\dirname(__DIR__, 3).'/src') as [$file, $line, $call]) {
            if (1 === preg_match(self::LEAK, $call)) {
                $leaks[] = \sprintf('%s:%d  %s', $file, $line, preg_replace('/\s+/', ' ', $call));
            }
        }

        self::assertSame([], $leaks, "Log the User id, or EmailFingerprint::of() when there is no User:\n".implode("\n", $leaks));
    }

    /**
     * Nor a client IP: the rate limiters key on it in Redis and the edge access log
     * has it already, and a truncated hash of an IPv4 address is reversed by trying
     * all 2^32 of them, so there is nothing to gain from writing it once more.
     */
    #[Test]
    public function noLoggerCallCarriesAClientIp(): void
    {
        $leaks = [];
        foreach ($this->loggerCalls(\dirname(__DIR__, 3).'/src') as [$file, $line, $call]) {
            if (1 === preg_match('/getClientIp\(\)|[\'"]ip[\'"]\s*=>|\$clientIp\b|\$ip\b/', $call)) {
                $leaks[] = \sprintf('%s:%d  %s', $file, $line, preg_replace('/\s+/', ' ', $call));
            }
        }

        self::assertSame([], $leaks, "Log no client IP:\n".implode("\n", $leaks));
    }

    #[Test]
    public function theGuardCatchesAnAddressInAMultiLineContext(): void
    {
        $call = "\$this->logger->error('Mail failed', [\n    'email' => \$email,\n    'error' => \$e->getMessage(),\n])";

        self::assertMatchesRegularExpression(self::LEAK, $call);
        self::assertDoesNotMatchRegularExpression(self::LEAK, "\$this->logger->debug('Sent', ['emailHash' => EmailFingerprint::of(\$email)])");
    }

    #[Test]
    public function aFingerprintMatchesTheSameAddressHoweverItIsTyped(): void
    {
        self::assertSame(EmailFingerprint::of('rider@example.com'), EmailFingerprint::of(' Rider@Example.com '));
        self::assertNotSame(EmailFingerprint::of('rider@example.com'), EmailFingerprint::of('other@example.com'));
        self::assertStringNotContainsString('rider', EmailFingerprint::of('rider@example.com'));
    }

    /**
     * @return iterable<array{string, int, string}> file, line, the call from `logger->` to its closing parenthesis
     */
    private function loggerCalls(string $srcDir): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/logger->(?:debug|info|notice|warning|error|critical|alert|emergency|log)\(/', $source, $matches, \PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$match, $offset]) {
                $start = $offset + \strlen($match);
                $depth = 1;
                $end = $start;
                while ($end < \strlen($source) && $depth > 0) {
                    $depth += match ($source[$end]) {
                        '(' => 1,
                        ')' => -1,
                        default => 0,
                    };
                    ++$end;
                }

                yield [
                    substr($file->getPathname(), \strlen($srcDir) + 1),
                    substr_count($source, "\n", 0, $offset) + 1,
                    substr($source, $offset, $end - $offset),
                ];
            }
        }
    }
}
