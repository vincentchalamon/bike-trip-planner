<?php

declare(strict_types=1);

namespace App\Engine;

use Psr\Container\ContainerInterface;

interface EngineRegistryAwareInterface
{
    public function setEngineRegistry(ContainerInterface $engineRegistry): void;
}
