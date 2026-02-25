<?php

declare(strict_types=1);

namespace App\Engine;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

trait EngineRegistryAwareTrait
{
    private ContainerInterface $engineRegistry;

    public function setEngineRegistry(ContainerInterface $engineRegistry): void
    {
        $this->engineRegistry = $engineRegistry;
    }

    /**
     * @template T of EngineInterface
     *
     * @param class-string<T> $id
     *
     * @return T
     *
     * @throws NotFoundExceptionInterface  no entry was found for **this** identifier
     * @throws ContainerExceptionInterface error while retrieving the entry
     */
    private function getEngine(string $id): EngineInterface
    {
        return $this->engineRegistry->get($id);
    }
}
