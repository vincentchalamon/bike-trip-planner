<?php

declare(strict_types=1);

namespace App\Tests;

use ApiPlatform\Test\ApiTestCase as BaseApiTestCase;

/**
 * Base class for every functional test.
 *
 * It exists for one reason: API Platform 5.0 flipped
 * {@see BaseApiTestCase::$alwaysBootKernel} from `null` (boot on every
 * `createClient()`) to `false` (reuse an already-booted kernel). Reusing the
 * kernel keeps its Doctrine connection open across tests, and Foundry's
 * per-test `migrate` reset then has to terminate that live connection to drop
 * the database — which kills the migration run itself ("terminating connection
 * due to administrator command") and leaves every table missing.
 *
 * Restoring the 4.x behaviour here rather than on each test class keeps the
 * setting in one place; before 5.0 there was no shared base at all.
 */
abstract class ApiTestCase extends BaseApiTestCase
{
    #[\Override]
    protected static ?bool $alwaysBootKernel = true;
}
