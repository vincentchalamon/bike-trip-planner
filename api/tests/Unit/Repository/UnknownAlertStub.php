<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\ApiResource\Model\Alert;

/**
 * Test stub: an Alert subclass not registered in DoctrineTripRequestRepository::alertToArray().
 * Used to verify the LogicException safety net for unknown Alert subclasses.
 */
readonly class UnknownAlertStub extends Alert
{
}
