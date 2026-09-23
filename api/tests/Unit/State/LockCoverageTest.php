<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\State\TripLockProcessor;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Keeps the trip lock declared in exactly one place.
 *
 * Nine processors used to call `assertNotLocked()` themselves, so the perimeter existed twice:
 * once in those calls, once in whatever claimed to document them — and the second list was
 * empty, since 423 appeared nowhere in the published contract. The flag on the operation is now
 * both what {@see TripLockProcessor} enforces and what
 * {@see \App\Metadata\TripLockMetadataFactory} advertises. This stops the calls coming back.
 */
final class LockCoverageTest extends KernelTestCase
{
    #[Test]
    public function noProcessorAssertsTheLockByHand(): void
    {
        $offenders = [];

        foreach (glob(__DIR__.'/../../../src/State/*.php') ?: [] as $file) {
            if (str_ends_with($file, 'TripLocker.php') || str_ends_with($file, 'TripLockProcessor.php')) {
                continue;
            }

            $source = file_get_contents($file);
            if (false !== $source && str_contains($source, '->assertNotLocked(')) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "The lock is applied by TripLockProcessor, from the operation's own flag. Calling it by hand builds a second perimeter that can drift from the published one:\n- %s",
            implode("\n- ", $offenders),
        ));
    }

    /**
     * The flag only means something on an operation that writes; on a read it would publish a
     * 423 that can never happen.
     */
    #[Test]
    public function onlyMutatingOperationsRefuseALockedTrip(): void
    {
        $spurious = [];

        foreach ($this->operations() as $name => $operation) {
            if (true !== ($operation->getExtraProperties()[TripLockProcessor::EXTRA_PROPERTY] ?? false)) {
                continue;
            }

            if (!\in_array($operation->getMethod(), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
                $spurious[] = \sprintf('%s (%s)', $name, $operation->getMethod());
            }
        }

        self::assertSame([], $spurious, \sprintf(
            "These operations refuse a locked trip but do not write:\n- %s",
            implode("\n- ", $spurious),
        ));
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
            }
        }
    }
}
