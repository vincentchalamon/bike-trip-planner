<?php

declare(strict_types=1);

namespace Provisioner;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Opens one reference zone: `provision <zone>` (ADR-049 §1).
 *
 * There used to be no such operation. `RegionSelectionStore` kept a **cumulative** list
 * of slugs in `regions.json` and every run re-downloaded, re-merged and re-imported all
 * of them, so opening 13 regions one at a time cost 13 full re-imports of a growing
 * dataset. The zone is now a mandatory argument, the selection file and the interactive
 * selector are gone, and the source of truth for what is open is `osm.zones` in the
 * database.
 *
 * Two consequences visible here:
 *
 * - **No merge.** One zone per run means one extract to filter, so `osmium merge`
 *   disappeared along with the reference staging PBF it produced.
 * - **The containment invariant is checked before anything is downloaded.** ADR-049 §6
 *   requires the routing perimeter to encompass the reference perimeter; nothing
 *   maintains that, so refusing a zone the graph does not cover — with an actionable
 *   message — is the whole user experience of the invariant.
 */
#[AsCommand(
    name: 'provision',
    description: 'Open one OSM reference zone: download its extract and promote it into the PostGIS reference index',
)]
final readonly class ProvisionCommand
{
    public function __construct(
        private ZoneOpening $opening,
        private RoutingPerimeter $routingPerimeter,
        private ProvisionerLog $log,
        private RunLock $lock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        // Optional at the console level, required in fact: validating it here buys the
        // list of zones and the routing hint in the error, which "Not enough arguments"
        // cannot give.
        #[Argument('Geofabrik slug or name of the zone to open (e.g. bretagne)', name: 'zone')]
        ?string $zoneArgument = null,
        #[Option('Show what would be downloaded and imported without executing')]
        bool $dryRun = false,
        // Local development only, and named so that using it in production reads as a
        // mistake. Without it, a dev machine cannot open any zone without first building the
        // national routing graph — hours and ~30 GB — just to work on accommodations. The
        // alternative an operator would otherwise improvise, dropping a fake extract into the
        // routing volume, is worse: `build-routing-graph.sh` skips downloading an extract that
        // is already present, so the next real build would silently build from the fake one.
        #[Option('Open the zone even if the routing graph does not cover it (local development; trips there cannot be routed)')]
        bool $allowUnroutedZone = false,
    ): int {
        $io->title('OSM Reference Zone Provisioner');

        $zone = null !== $zoneArgument ? GeofabrikRegionRegistry::resolve($zoneArgument) : null;

        if (null === $zone) {
            $this->log->fail($io, \sprintf(
                'A zone is required: `make provision <zone>` opens exactly one (e.g. `make provision bretagne`).%s',
                GeofabrikRegionRegistry::unresolvedZoneHint($zoneArgument),
            ));

            return Command::FAILURE;
        }

        if (!$this->assertRoutingCovers($io, $zone['country'], $zone['name'], $allowUnroutedZone)) {
            return Command::FAILURE;
        }

        // Serialise concurrent runs (cron + manual overlap): two provisioners writing the
        // same zone would race on its staging schema (ADR-041).
        if (!$this->lock->acquire($io)) {
            return Command::FAILURE;
        }

        try {
            $outcomes = $this->opening->open($io, $zone, $dryRun);
            $this->log->summarize($io, 'Provisioning summary', $outcomes);

            return \in_array(Command::FAILURE, $outcomes, true) ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $this->lock->release();
        }
    }

    /**
     * Refuses a zone the routing graph does not cover (ADR-049 §6). An *observed* empty
     * perimeter refuses — that is a machine with no graph yet; a perimeter that cannot be
     * observed at all only warns, since a missing volume mount must not become a
     * provisioning outage.
     *
     * @param string $country Geofabrik country slug the zone belongs to
     */
    private function assertRoutingCovers(SymfonyStyle $io, string $country, string $zoneName, bool $allowUnrouted = false): bool
    {
        if ($allowUnrouted && !$this->routingPerimeter->covers($country)) {
            $io->warning(\sprintf(
                'Opening %s without checking the routing graph (--allow-unrouted-zone). Trips in this zone will not be routable; this is for local development only.',
                $zoneName,
            ));

            return true;
        }

        if (!$this->routingPerimeter->isObservable()) {
            $io->warning('The routing volume is not mounted, so the routing perimeter cannot be checked. Opening the zone anyway; verify that the graph covers it.');

            return true;
        }

        if ($this->routingPerimeter->covers($country)) {
            return true;
        }

        $built = $this->routingPerimeter->slugs();
        $this->log->fail($io, \sprintf(
            '%s is in "%s", which the routing graph does not cover, so a trip there could not be routed. Build it first with `make routing-build %s`, then provision again. Routing graph currently built from: %s.',
            $zoneName,
            $country,
            $country,
            [] === $built ? 'nothing' : implode(', ', $built),
        ));

        return false;
    }
}
