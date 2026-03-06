<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\ProvisionCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ProvisionCommandTest extends TestCase
{
    #[Test]
    public function dryRunShowsSelectedRegions(): void
    {
        $app = new Application();
        $app->addCommand(new ProvisionCommand());

        $tester = new CommandTester($app->find('provision'));
        $tester->setInputs([
            'Nord-Pas-de-Calais (223 MB)',
            '',
        ]);
        $tester->execute(['--dry-run' => true]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('Nord-Pas-de-Calais', $output);
        self::assertStringContainsString('Dry run', $output);
        self::assertSame(0, $tester->getStatusCode());
    }

    #[Test]
    public function emptySelectionExitsGracefully(): void
    {
        $app = new Application();
        $app->addCommand(new ProvisionCommand());

        $tester = new CommandTester($app->find('provision'));
        $tester->setInputs(['']);
        $tester->execute([]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('No region selected', $output);
        self::assertSame(0, $tester->getStatusCode());
    }
}
