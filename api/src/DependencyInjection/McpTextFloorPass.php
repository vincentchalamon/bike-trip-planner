<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use App\Serializer\Mcp\McpTextFloor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Hands {@see McpTextFloor} to the one processor that serialises MCP tool answers, and to
 * nothing else.
 *
 * `StructuredContentProcessor` takes its serializer as its first argument. Replacing that
 * argument, rather than decorating `api_platform.serializer`, is the whole point: the floor must
 * not touch REST, and a decorator of the shared serializer would. Declared as a pass because the
 * processor is defined by API Platform's bundle, and a service file can redefine a vendor
 * service but not amend one of its arguments.
 *
 * Absent processor = loud failure. MCP is on in every environment; a floor that silently stopped
 * applying would look exactly like a floor that works.
 */
final class McpTextFloorPass implements CompilerPassInterface
{
    public const string PROCESSOR = 'api_platform.mcp.state_processor';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::PROCESSOR)) {
            throw new \LogicException(\sprintf('"%s" is not defined: MCP tool answers would be serialised without %s.', self::PROCESSOR, McpTextFloor::class));
        }

        $container->getDefinition(self::PROCESSOR)->replaceArgument(0, new Reference(McpTextFloor::class));
    }
}
