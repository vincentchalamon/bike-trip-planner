<?php

declare(strict_types=1);

namespace App\State;

/**
 * Marks the operations that create a trip, and therefore require an `Idempotency-Key`.
 *
 * Its own holder rather than a constant on a processor: three operations create a trip through
 * three different processors, and the flag belongs to none of them in particular.
 *
 * @see Idempotency
 * @see \App\Metadata\IdempotencyMetadataFactory
 * @see \App\Tests\Unit\State\IdempotencyCoverageTest
 */
final class TripCreation
{
    public const string REQUIRES_IDEMPOTENCY_KEY = 'requires_idempotency_key';
}
