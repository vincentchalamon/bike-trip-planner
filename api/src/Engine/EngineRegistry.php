<?php

declare(strict_types=1);

namespace App\Engine;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Contracts\Service\ServiceCollectionInterface;

#[AsDecorator('app.engine_registry')]
final readonly class EngineRegistry implements ContainerInterface
{
    /**
     * @param ServiceCollectionInterface<EngineInterface> $engineRegistry
     */
    public function __construct(
        #[AutowireDecorated]
        private ServiceCollectionInterface $engineRegistry,
    ) {
        foreach ($this->engineRegistry as $engine) {
            if ($engine instanceof EngineRegistryAwareInterface) {
                $engine->setEngineRegistry($this);
            }
        }
    }

    /**
     * @template T of EngineInterface
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    public function get(string $id): EngineInterface
    {
        return $this->engineRegistry->get($id);
    }

    /**
     * @template T of EngineInterface
     *
     * @param class-string<T> $id
     */
    public function has(string $id): bool
    {
        return $this->engineRegistry->has($id);
    }
}
