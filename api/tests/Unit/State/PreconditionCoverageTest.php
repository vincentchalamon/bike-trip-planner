<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\State\PreconditionProcessor;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Keeps the declared precondition perimeter and the code that actually moves a trip's
 * structural version in step.
 *
 * The flag on the operation is what {@see PreconditionProcessor} enforces and what the
 * OpenAPI document advertises, but nothing about writing the stage collection forces a
 * developer to set it. This scans the processors for the two calls that move the version —
 * `mutateStages()` and `increment()` — and checks the correspondence both ways, so neither
 * an unguarded new write nor a flag left on an operation that no longer writes can pass.
 *
 * MCP tools are scanned alongside the HTTP operations. The guard is the operation's flag in
 * both cases; only how the caller states the version differs ({@see \App\Concurrency\IfMatch}).
 */
final class PreconditionCoverageTest extends KernelTestCase
{
    /**
     * Operations whose processor moves the version. Derived from the source, not maintained
     * by hand.
     */
    #[Test]
    public function everyVersionMovingOperationRequiresIfMatch(): void
    {
        $writers = $this->versionMovingProcessors();
        self::assertNotEmpty($writers, 'No version-moving processor found — the scan is broken, not the code.');

        $missing = [];
        foreach ($this->operations() as $name => $operation) {
            $processor = $operation->getProcessor();
            if (!\is_string($processor) || !\in_array($processor, $writers, true)) {
                continue;
            }

            if (true !== ($operation->getExtraProperties()[PreconditionProcessor::EXTRA_PROPERTY] ?? false)) {
                $missing[] = \sprintf('%s (%s)', $name, $processor);
            }
        }

        self::assertSame([], $missing, \sprintf(
            "These operations write the stage collection or bump the trip version but declare no If-Match precondition.\nAdd extraProperties: [PreconditionProcessor::EXTRA_PROPERTY => true] to each:\n- %s",
            implode("\n- ", $missing),
        ));
    }

    #[Test]
    public function noOperationRequiresIfMatchWithoutMovingTheVersion(): void
    {
        $writers = $this->versionMovingProcessors();

        $spurious = [];
        foreach ($this->operations() as $name => $operation) {
            if (true !== ($operation->getExtraProperties()[PreconditionProcessor::EXTRA_PROPERTY] ?? false)) {
                continue;
            }

            $processor = $operation->getProcessor();
            if (!\is_string($processor) || !\in_array($processor, $writers, true)) {
                $spurious[] = \sprintf('%s (%s)', $name, \is_string($processor) ? $processor : 'no processor');
            }
        }

        self::assertSame([], $spurious, \sprintf(
            "These operations demand an If-Match precondition but their processor never moves the trip version, so the header only makes them harder to call:\n- %s",
            implode("\n- ", $spurious),
        ));
    }

    /**
     * Fully-qualified names of the processors that move the structural version, read off the
     * source rather than listed here.
     *
     * @return list<string>
     */
    private function versionMovingProcessors(): array
    {
        $found = [];

        foreach (glob(__DIR__.'/../../../src/State/*Processor.php') ?: [] as $file) {
            $source = file_get_contents($file);
            if (false === $source) {
                continue;
            }

            if (str_contains($source, '->mutateStages(') || str_contains($source, '->increment(')) {
                $found[] = 'App\\State\\'.basename($file, '.php');
            }
        }

        sort($found);

        return $found;
    }

    /**
     * @return iterable<string, Operation>
     */
    private function operations(): iterable
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var ResourceMetadataCollectionFactoryInterface $factory */
        $factory = $container->get('api_platform.metadata.resource.metadata_collection_factory');

        /** @var iterable<class-string> $names */
        $names = $container->get('api_platform.metadata.resource.name_collection_factory')->create();

        foreach ($names as $resourceClass) {
            foreach ($factory->create($resourceClass) as $resource) {
                foreach ($resource->getOperations() ?? [] as $name => $operation) {
                    yield $name => $operation;
                }

                // MCP tools live in a bucket of their own: `getOperations()` does not return
                // them. Stopping there would leave unscanned the one transport where the
                // precondition is easiest to forget — nothing about declaring a tool suggests
                // that a header ever stood between it and a lost update.
                foreach ($resource->getMcp() ?? [] as $key => $operation) {
                    yield (\is_string($key) && '' !== $key ? $key : ($operation->getName() ?? '(unnamed tool)')) => $operation;
                }
            }
        }
    }
}
