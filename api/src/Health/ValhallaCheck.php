<?php

declare(strict_types=1);

namespace App\Health;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The routing engine. Required: without it no route can be computed.
 */
#[AsTaggedItem(priority: 10)]
final readonly class ValhallaCheck extends HttpCheck
{
    public function __construct(
        #[Autowire(service: 'routing.client')]
        HttpClientInterface $valhallaClient,
    ) {
        parent::__construct($valhallaClient, 'GET', '/status');
    }

    public function name(): string
    {
        return 'valhalla';
    }

    public function isRequired(): bool
    {
        return true;
    }
}
