<?php

declare(strict_types=1);

namespace App\Command;

use App\Notification\ZoneOpeningNotifier;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Announces a newly opened reference zone to opted-in users (#1124).
 *
 * Zone opening happens in the separate provisioner process ({@see make provision}),
 * which only writes `osm.zones`; there is no in-process event to hook. This is the
 * documented trigger: ops runs it right after a zone is promoted. The display name
 * defaults to the one recorded in `osm.zones` for the slug.
 *
 *   app:notifications:zone-opened corse [--name="Corse"]
 */
#[AsCommand(
    name: 'app:notifications:zone-opened',
    description: 'Announce a newly opened reference zone to opted-in users',
)]
final readonly class NotifyZoneOpenedCommand
{
    public function __construct(
        private ZoneOpeningNotifier $notifier,
        private Connection $referenceConnection,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Zone slug as promoted in osm.zones (e.g. corse)')]
        string $slug,
        #[Option('Display name (defaults to the osm.zones name for the slug)')]
        ?string $name = null,
    ): int {
        if (null === $name || '' === $name) {
            $name = $this->lookupZoneName($slug);
        }

        if (null === $name) {
            $io->error(\sprintf('Unknown zone slug "%s" (not found in osm.zones) and no --name given.', $slug));

            return Command::INVALID;
        }

        $count = $this->notifier->notify($slug, $name);
        $io->success(\sprintf('Dispatched %d zone-opening push(es) for "%s".', $count, $name));

        return Command::SUCCESS;
    }

    private function lookupZoneName(string $slug): ?string
    {
        $name = $this->referenceConnection->fetchOne('SELECT name FROM osm.zones WHERE slug = :slug', ['slug' => $slug]);

        return \is_string($name) ? $name : null;
    }
}
