<?php

declare(strict_types=1);

namespace App\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Postgres logs every statement slower than 100 ms; without these flags the line
 * carries the bound values in full (an address, a token hash, a position).
 */
final class DatabaseLogParametersTest extends TestCase
{
    #[Test]
    public function slowStatementLogsCarryNoBoundValue(): void
    {
        $compose = Yaml::parseFile(\dirname(__DIR__, 4).'/compose.yaml');
        \assert(\is_array($compose) && \is_array($compose['services'] ?? null) && \is_array($compose['services']['database'] ?? null));

        $command = $compose['services']['database']['command'] ?? null;
        self::assertIsArray($command);
        self::assertContains('log_min_duration_statement=100', $command, 'the slow-statement log is gone: revisit this test');
        self::assertContains('log_parameter_max_length=0', $command);
        self::assertContains('log_parameter_max_length_on_error=0', $command);
    }
}
