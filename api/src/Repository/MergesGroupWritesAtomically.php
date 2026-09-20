<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * Declares that this implementation merges a group write into the stored alerts itself,
 * without a read-modify-write of the whole collection.
 *
 * {@see LockingTripRequestRepository} reads it to decide whether the enrichment writes need
 * the per-trip lock. An implementation that does a single `jsonb_set` UPDATE does not: the
 * merge is the database's, so a dozen producers finishing at once all survive (ADR-068) and
 * serialising them behind a 3-second bounded acquire would only turn a burst into failed
 * computations. One that stores the collection as a single blob does need it, or a write
 * lands between another's read and write and is silently reverted (recette #649).
 *
 * A marker rather than a behaviour: the property belongs to the storage engine, and the
 * decorator wraps the interface precisely so it does not have to know which one it holds.
 */
interface MergesGroupWritesAtomically
{
}
