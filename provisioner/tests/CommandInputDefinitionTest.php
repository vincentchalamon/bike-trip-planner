<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\EventsRefreshCommand;
use Provisioner\ImportOverrideCommand;
use Provisioner\ProvisionCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Pins the console contract of the three provisioner binaries: `make provision <zone>`,
 * `make provision-override`, the events-refresh schedule and the runbooks type these words.
 */
final class CommandInputDefinitionTest extends TestCase
{
    #[Test]
    public function provision(): void
    {
        $log = sys_get_temp_dir().'/provisioner-definition.log';

        self::assertSame([
            'description' => 'Open one OSM reference zone: download its extract and promote it into the PostGIS reference index',
            'arguments' => [
                // Optional at the console level on purpose (ADR-049): the command validates it
                // itself to list the known zones, which "Not enough arguments" cannot do.
                'zone' => ['optional', null, 'Geofabrik slug or name of the zone to open (e.g. bretagne)'],
            ],
            'options' => [
                'dry-run' => [null, 'none', false, 'Show what would be downloaded and imported without executing'],
                'allow-unrouted-zone' => [null, 'none', false, 'Open the zone even if the routing graph does not cover it (local development; trips there cannot be routed)'],
            ],
        ], $this->definitionOf(new ProvisionCommand(lockFile: $log.'.lock', logFile: $log), 'provision'));
    }

    #[Test]
    public function eventsRefresh(): void
    {
        $log = sys_get_temp_dir().'/events-refresh-definition.log';

        self::assertSame([
            'description' => 'Refresh the events layer for every open zone: re-import the feeds and purge past events',
            'arguments' => [],
            'options' => [
                'zone' => [null, 'required', null, 'Refresh a single open zone instead of all of them'],
                'dry-run' => [null, 'none', false, 'List the open zones and the purge date without importing anything'],
            ],
        ], $this->definitionOf(new EventsRefreshCommand(lockFile: $log.'.lock', logFile: $log), 'events-refresh'));
    }

    #[Test]
    public function importOverride(): void
    {
        self::assertSame([
            'description' => 'Import an operator-supplied override.tsv into the live reference tables',
            'arguments' => [
                'zone' => ['optional', null, 'Geofabrik slug of the zone the corrections belong to'],
                'file' => ['optional', null, 'Path to the override.tsv; defaults to /data/zones/<zone>/override.tsv'],
            ],
            'options' => [],
        ], $this->definitionOf(new ImportOverrideCommand(), 'provision-override'));
    }

    /**
     * @return array{description: string, arguments: array<string, list<mixed>>, options: array<string, list<mixed>>}
     */
    private function definitionOf(callable|Command $command, string $name): array
    {
        $app = new Application();
        $app->addCommand($command);

        $registered = $app->find($name);
        $definition = $registered->getNativeDefinition();

        return [
            'description' => $registered->getDescription(),
            'arguments' => array_map(static fn (InputArgument $argument): array => [
                ($argument->isRequired() ? 'required' : 'optional').($argument->isArray() ? ' array' : ''),
                $argument->getDefault(),
                $argument->getDescription(),
            ], $definition->getArguments()),
            'options' => array_map(static fn (InputOption $option): array => [
                $option->getShortcut(),
                match (true) {
                    $option->isValueRequired() => 'required',
                    $option->isValueOptional() => 'optional',
                    default => 'none',
                }.($option->isArray() ? ' array' : '').($option->isNegatable() ? ' negatable' : ''),
                $option->getDefault(),
                $option->getDescription(),
            ], $definition->getOptions()),
        ];
    }
}
