<?php

declare(strict_types=1);

namespace App\Health;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The application's own database (`public` schema), where every trip lives. Required.
 */
#[AsTaggedItem(priority: 70)]
final readonly class PostgresCheck extends ConnectionCheck
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function name(): string
    {
        return 'postgres';
    }

    protected function connection(): Connection
    {
        return $this->connection;
    }

    public function isRequired(): bool
    {
        return true;
    }
}
