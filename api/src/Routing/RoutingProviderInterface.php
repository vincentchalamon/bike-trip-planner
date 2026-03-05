<?php

declare(strict_types=1);

namespace App\Routing;

use App\ApiResource\Model\Coordinate;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.routing_provider')]
interface RoutingProviderInterface
{
    /**
     * @param list<Coordinate> $via
     */
    public function calculateRoute(Coordinate $from, Coordinate $to, array $via = []): RoutingResult;
}
