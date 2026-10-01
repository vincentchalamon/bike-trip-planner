<?php

declare(strict_types=1);

namespace App\Health;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Mercure hub. Non-required since ADR-065: it is the invalidation channel, not a source of
 * truth. Everything it carries is retrievable by GET, a publish failure no longer fails the
 * work that produced it, and a client that misses an event resynchronises on its next read.
 * An unreachable hub costs latency, not correctness.
 */
#[AsTaggedItem(priority: 20)]
final readonly class MercureCheck extends HttpCheck
{
    public function __construct(
        #[Autowire(service: 'mercure.health.client')]
        HttpClientInterface $mercureClient,
    ) {
        parent::__construct($mercureClient, 'HEAD', '?topic=health');
    }

    public function name(): string
    {
        return 'mercure';
    }

    public function isRequired(): bool
    {
        return false;
    }
}
