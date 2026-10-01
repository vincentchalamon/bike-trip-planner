<?php

declare(strict_types=1);

namespace App\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The VM's Docker daemon caps every container's logs: without it they grow, and
 * are kept, forever (docs/deployment.md, "Logs").
 */
final class ContainerLogRotationTest extends TestCase
{
    #[Test]
    public function theDockerRoleCapsContainerLogs(): void
    {
        $root = \dirname(__DIR__, 4).'/ansible';
        $tasks = Yaml::parseFile($root.'/roles/docker/tasks/main.yml');
        $vars = Yaml::parseFile($root.'/group_vars/all.yml');
        \assert(\is_array($tasks) && \is_array($vars));

        $daemon = null;
        foreach ($tasks as $task) {
            $copy = \is_array($task) ? ($task['ansible.builtin.copy'] ?? null) : null;
            if (\is_array($copy) && '/etc/docker/daemon.json' === ($copy['dest'] ?? null)) {
                $daemon = $copy['content'] ?? null;
            }
        }

        self::assertIsString($daemon, 'no task writes /etc/docker/daemon.json');
        $rendered = str_replace(
            ['{{ docker_log_max_size }}', '{{ docker_log_max_file }}'],
            [$this->scalar($vars['docker_log_max_size'] ?? null), $this->scalar($vars['docker_log_max_file'] ?? null)],
            $daemon,
        );

        self::assertSame(
            ['log-driver' => 'json-file', 'log-opts' => ['max-size' => '20m', 'max-file' => '5']],
            json_decode($rendered, true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    private function scalar(mixed $value): string
    {
        self::assertTrue(\is_string($value) || \is_int($value));

        return (string) $value;
    }
}
