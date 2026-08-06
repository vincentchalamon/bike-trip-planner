<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
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
final class ImportOverrideCommand extends Command
{
    private const string DEFAULT_ZONES_DIR = '/data/zones';

    private readonly OverrideImporter $importer;

    public function __construct(
        private readonly string $zonesDir = self::DEFAULT_ZONES_DIR,
        ?OverrideImporter $importer = null,
    ) {
        parent::__construct();

        $this->importer = $importer ?? new OverrideImporter();
    }

    protected function configure(): void
    {
        $this->addArgument('zone', InputArgument::OPTIONAL, 'Geofabrik slug of the zone the corrections belong to');
        $this->addArgument('file', InputArgument::OPTIONAL, 'Path to the override.tsv; defaults to /data/zones/<zone>/override.tsv');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Reference override import');

        $zoneArgument = $input->getArgument('zone');
        $zone = \is_string($zoneArgument) ? GeofabrikRegionRegistry::resolve($zoneArgument) : null;

        if (null === $zone) {
            $io->error(\sprintf(
                'A zone is required: `make provision-override <zone> [file]`.%s%sKnown zones: %s',
                \is_string($zoneArgument) && '' !== trim($zoneArgument) ? \sprintf(' "%s" is not a known zone.', $zoneArgument) : '',
                \PHP_EOL,
                implode(', ', GeofabrikRegionRegistry::slugs()),
            ));

            return Command::FAILURE;
        }

        $fileArgument = $input->getArgument('file');
        $file = \is_string($fileArgument) && '' !== trim($fileArgument)
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
