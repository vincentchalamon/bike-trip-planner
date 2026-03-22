<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Checks whether a trip is locked (startDate <= today) and throws 423 Locked if so.
 *
 * RFC 4918 §11.3 — 423 Locked: the source or destination resource of a method
 * is locked. This is semantically correct for a trip whose start date has passed:
 * the resource is locked by its temporal state, not by access permissions.
 */
final class TripLocker
{
    public function assertNotLocked(TripRequest $request): void
    {
        if ($this->isLocked($request)) {
            throw new HttpException(423, 'This trip is locked: its start date is today or in the past.');
        }
    }

    public function isLocked(TripRequest $request): bool
    {
        if (null === $request->startDate) {
            return false;
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        return $request->startDate <= $today;
    }
}
