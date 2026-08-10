<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;

/**
 * An events source the standalone {@see EventsRefreshCommand} can refresh out of band
 * (ADR-051 §4): re-download the national feed once, then upsert-and-purge each open zone
 * from the same staging.
 *
 * The feed is fetched and parsed once ({@see stageEventsForRefresh}) and the result is
 * clipped and promoted per zone ({@see promoteEventsForZone}), so opening N zones is one
 * download, not N. The zone-open path (`provision <zone>`) does not go through this
 * interface — it already has the flux in hand and promotes events inline.
 */
interface EventsRefreshSourceInterface
{
    /**
     * A short source label for the command's per-source reporting.
     */
    public function label(): string;

    /**
     * Downloads and parses the national feed once into a dedicated refresh staging schema,
     * returning its name. No zone is involved yet: the clip happens at promotion.
     *
     * @throws ImportFailedException
     */
    public function stageEventsForRefresh(string $workDir): string;

    /**
     * Upserts the staged events covered by one zone into the live table and purges past
     * events, in one transaction (see {@see EventsPromotion}).
     *
     * @param string $today the purge boundary as `YYYY-MM-DD`
     *
     * @throws ImportFailedException
     */
    public function promoteEventsForZone(string $stagingSchema, string $zone, string $today): void;

    /**
     * Drops the refresh staging schema. The live events table is never touched here.
     *
     * @throws ImportFailedException
     */
    public function dropRefreshStaging(string $stagingSchema): void;
}
