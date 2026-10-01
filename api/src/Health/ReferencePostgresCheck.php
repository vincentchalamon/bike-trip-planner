<?php

declare(strict_types=1);

namespace App\Health;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Connectivity of the shared read-only PG-référence (ADR-060). Non-required: reported so an
 * operator sees the reference DB is unreachable, but it never flips readiness, since the
 * reference index is a feature-enrichment source, not the core trip flow (ADR-040).
 */
#[AsTaggedItem(priority: 60)]
final readonly class ReferencePostgresCheck extends ConnectionCheck
{
    public function __construct(
        // Bound by parameter name in services.php.
        private Connection $referenceConnection,
    ) {
    }

    public function name(): string
    {
        return 'postgres_reference';
    }

    protected function connection(): Connection
    {
        return $this->referenceConnection;
    }

    public function isRequired(): bool
    {
        return false;
    }
}
