<?php

declare(strict_types=1);

namespace App\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;

final readonly class RegistryCompilerPass implements CompilerPassInterface
{
    public function __construct(
        private string $tag,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        $services = [];
        foreach ($container->findTaggedServiceIds($this->tag, true) as $serviceId => $tags) {
            foreach ($tags as $tag) {
                if (!isset($tag['id'])) {
                    $tag['id'] = $serviceId;
                }

                $services[$tag['id']] = new Reference($serviceId);
                $container->getDefinition($tag['id'])->setPublic(true);
            }
        }

        $container->register(\sprintf('%s_registry', $this->tag), ServiceLocator::class)
            ->addTag('container.service_locator')
            ->addArgument($services);
    }
}
