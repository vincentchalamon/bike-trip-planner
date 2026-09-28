<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `provision-override <zone> [file]` — imports an operator's corrections (#886).
 *
 * A second binary rather than a sub-command of `provision`, because the provisioner's
 * console application runs in single-command mode: `provisioner bretagne` passes `bretagne`
 * as the zone *argument*, and registering a real sub-command would turn that into an
 * unknown-command error and break `make provision <zone>`. One binary per operation keeps
 * the existing invocation intact and reads honestly — this is a distinct, deliberate act,
 * not a flag on the import.
 */
#[AsCommand(
    name: 'provision-override',
    description: 'Import an operator-supplied override.tsv into the live reference tables',
)]
final readonly class ImportOverrideCommand
{
    private const string DEFAULT_ZONES_DIR = '/data/zones';

    private OverrideImporter $importer;

    public function __construct(
        private string $zonesDir = self::DEFAULT_ZONES_DIR,
        ?OverrideImporter $importer = null,
    ) {
        $this->importer = $importer ?? new OverrideImporter();
    }

    public function __invoke(
        SymfonyStyle $io,
        // Optional at the console level, like `provision <zone>` (ADR-049): validating it here
        // buys the list of known zones in the error, which "Not enough arguments" cannot give.
        #[Argument('Geofabrik slug of the zone the corrections belong to', name: 'zone')]
        ?string $zoneArgument = null,
        #[Argument('Path to the override.tsv; defaults to /data/zones/<zone>/override.tsv', name: 'file')]
        ?string $fileArgument = null,
    ): int {
        $io->title('Reference override import');

        $zone = null !== $zoneArgument ? GeofabrikRegionRegistry::resolve($zoneArgument) : null;

        if (null === $zone) {
            $io->error(\sprintf(
                'A zone is required: `make provision-override <zone> [file]`.%s',
                GeofabrikRegionRegistry::unresolvedZoneHint($zoneArgument),
            ));

            return Command::FAILURE;
        }

        $file = null !== $fileArgument && '' !== trim($fileArgument)
            ? $fileArgument
            : \sprintf('%s/%s/override.tsv', $this->zonesDir, $zone['slug']);

        $io->section(\sprintf('Importing %s into the live tables', $file));

        try {
            $rows = $this->importer->import($file, $zone['slug']);
        } catch (ImportFailedException $importFailedException) {
            // Refused whole: the parse runs before any statement, and the insert is one
            // transaction, so there is no partially applied override to undo.
            $io->error($importFailedException->getMessage());
            $io->writeln('  Nothing was inserted.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d correction(s) offered for %s.', $rows, $zone['name']));
        $io->writeln('  Rows the index already held were left untouched (append-only).');
        $io->writeln(\sprintf('  Re-opening %s will not re-analyse them.', $zone['slug']));
        // The one limitation worth repeating at the point of use, not only in the runbook.
        $io->note('Keep this file. Nothing stores it, so a database rebuilt from scratch loses every correction whose file was not kept.');

        return Command::SUCCESS;
    }
}
