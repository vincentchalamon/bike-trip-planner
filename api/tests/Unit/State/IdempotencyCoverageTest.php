<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\State\TripCreation;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Binds the operations that demand an `Idempotency-Key` to the processors that honour it.
 *
 * The rule is applied in the creating processors rather than in a decorator of the write chain,
 * because no position in that chain is handed the trip that was just created — the innermost one
 * already receives a serialised Response. That makes the flag and the honouring two separate
 * things, and two separate things drift. This is what keeps them together: an operation that
 * demands the header without consulting it would publish a promise nothing keeps, and a
 * processor that consults it on an operation without the flag would demand a header the document
 * never mentions.
 */
final class IdempotencyCoverageTest extends KernelTestCase
{
    #[Test]
    public function everyOperationDemandingTheHeaderHasAProcessorThatConsultsIt(): void
    {
        $honouring = $this->processorsConsultingIdempotency();
        self::assertNotEmpty($honouring, 'No processor consults Idempotency — the scan is broken, not the code.');

        $unenforced = [];
        foreach ($this->operations() as $name => $operation) {
            if (true !== ($operation->getExtraProperties()[TripCreation::REQUIRES_IDEMPOTENCY_KEY] ?? false)) {
                continue;
            }

            $processor = $operation->getProcessor();
            if (!\is_string($processor) || !\in_array($processor, $honouring, true)) {
                $unenforced[] = \sprintf('%s (%s)', $name, \is_string($processor) ? $processor : 'no processor');
            }
        }

        self::assertSame([], $unenforced, \sprintf(
            "These operations advertise an Idempotency-Key their processor never reads, so the header would be required by the document and ignored by the server:\n- %s",
            implode("\n- ", $unenforced),
        ));
    }

    #[Test]
    public function noProcessorConsultsTheHeaderWithoutItsOperationDemandingIt(): void
    {
        $flagged = [];
        foreach ($this->operations() as $operation) {
            $processor = $operation->getProcessor();
            if (\is_string($processor) && true === ($operation->getExtraProperties()[TripCreation::REQUIRES_IDEMPOTENCY_KEY] ?? false)) {
                $flagged[] = $processor;
            }
        }

        $undeclared = array_values(array_diff($this->processorsConsultingIdempotency(), $flagged));

        self::assertSame([], $undeclared, \sprintf(
            "These processors read the Idempotency-Key but no operation declares it, so callers are refused for a header the document never asked them to send:\n- %s",
            implode("\n- ", $undeclared),
        ));
    }

    /**
     * The processors that honour the rule, directly or by delegation.
     *
     * A tool wrapper counts. The MCP transport needs a different answer shape — a `tools/call`
     * result carries one JSON document, so "202 plus a Location header" says nothing there — but
     * it must not need different bookkeeping. Wiring a wrapper to the processor that does the
     * bookkeeping is how that stays true, and reading the wiring here is how this test keeps
     * saying something about it rather than being widened to let it through.
     *
     * @return list<string>
     */
    private function processorsConsultingIdempotency(): array
    {
        $sources = [];
        foreach (['/../../../src/State/*Processor.php', '/../../../src/State/Mcp/*Processor.php'] as $pattern) {
            foreach (glob(__DIR__.$pattern) ?: [] as $file) {
                $source = file_get_contents($file);
                if (false === $source) {
                    continue;
                }

                $namespace = str_contains($file, '/Mcp/') ? 'App\\State\\Mcp\\' : 'App\\State\\';
                $sources[$namespace.basename($file, '.php')] = $source;
            }
        }

        $direct = [];
        foreach ($sources as $class => $source) {
            if (str_contains($source, '$this->idempotency->alreadyCreated(')) {
                $direct[] = $class;
            }
        }

        $found = $direct;
        foreach ($sources as $class => $source) {
            foreach ($direct as $consulting) {
                $shortName = substr((string) strrchr($consulting, '\\'), 1);
                if (!\in_array($class, $found, true) && str_contains($source, $shortName.'::class')) {
                    $found[] = $class;
                }
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

                // Tools live in their own bucket, which `getOperations()` does not return —
                // and they are the transport where the flag matters most, since the key is
                // derived there and a creation tool that skipped the check would make a trip
                // per retry with nothing in the document to say it should not have.
                foreach ($resource->getMcp() ?? [] as $key => $operation) {
                    yield (\is_string($key) && '' !== $key ? $key : ($operation->getName() ?? '(unnamed tool)')) => $operation;
                }
            }
        }
    }
}
