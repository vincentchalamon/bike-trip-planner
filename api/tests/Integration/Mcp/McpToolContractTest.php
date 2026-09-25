<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\State\Mcp\McpArguments;
use App\State\Mcp\McpDeserializeProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What every MCP tool must declare, because the transport's defaults are the wrong ones.
 *
 * An `McpTool` is a separate operation that happens to share a provider with the HTTP one
 * beside it. It inherits none of its neighbour's settings, and
 * {@see \ApiPlatform\Mcp\Server\Handler} then applies defaults of its own that differ from the
 * HTTP pipeline's. Each assertion below pins one place where accepting a default is wrong, and
 * each failure mode is silent — which is why these are tests rather than review notes.
 *
 * The scope is checked separately, by
 * {@see \App\Tests\Integration\Security\OAuth\McpToolScopeCoverageTest}.
 */
final class McpToolContractTest extends KernelTestCase
{
    /**
     * The only keys `Mcp\Schema\ToolAnnotations::fromArray()` reads.
     *
     * It validates the type of the ones it knows and **silently ignores everything else**, so
     * `readonlyHint` for `readOnlyHint` raises nothing at all: the tool ships announcing no
     * hints, and a client that would have rendered a confirmation prompt does not.
     */
    private const array KNOWN_ANNOTATIONS = ['title', 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'];

    /**
     * Authorizing through `object` reopens the UUID oracle that ADR-038 closed.
     *
     * The object form only resolves once the provider has run, and a provider reports a
     * missing record by throwing — so an unknown id answers "not found" while someone else's
     * id answers "access denied", and the two are told apart. On HTTP that is invisible
     * because `HideForbiddenAsNotFoundListener` masks the second as the first; on this
     * transport the MCP SDK catches the exception itself and `kernel.exception` never runs.
     *
     * Naming the URI variable evaluates at `pre_read`, before anything can report absence, and
     * `TripVoter` refuses an unknown trip exactly as it refuses someone else's. That is the
     * whole reason both answers come out identical, as
     * {@see \App\Tests\Functional\McpToolCallTest::anotherUsersTripIsIndistinguishableFromNoTripAtAll}
     * asserts.
     *
     * The HTTP operations keep the object form. They are covered.
     */
    #[Test]
    public function noToolAuthorizesThroughTheLoadedObject(): void
    {
        $offenders = [];

        foreach ($this->tools() as $name => $operation) {
            $expression = $operation->getSecurity();

            if (\is_string($expression) && preg_match('/\bobject\b/', $expression)) {
                $offenders[] = \sprintf('%s: %s', $name, $expression);
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "MCP tool(s) authorizing through the loaded object:\n  %s\n".
            'Name the URI variable instead, e.g. '."is_granted('TRIP_EDIT', tripId)".
            ', so the check runs before a provider can report that the record is missing.',
            implode("\n  ", $offenders),
        ));
    }

    /**
     * Every tool authorizes at all.
     *
     * A tool with no expression is reachable by any token the firewall lets through, whatever
     * trip it names.
     */
    #[Test]
    public function everyToolAuthorizes(): void
    {
        $offenders = [];

        foreach ($this->tools() as $name => $operation) {
            if (!\is_string($operation->getSecurity()) || '' === trim($operation->getSecurity())) {
                $offenders[] = $name;
            }
        }

        self::assertSame([], $offenders, \sprintf('MCP tool(s) with no security expression: %s', implode(', ', $offenders)));
    }

    /**
     * A tool that writes contradicts the transport's `validate: false` default.
     *
     * `Handler` turns validation off unless the operation says otherwise, so every
     * `Assert\*` constraint on the input is skipped. The damage is not uniform and not always
     * loud: a creation would accept a `sourceUrl` that is null or plain HTTP and only fail
     * three messages later inside a worker, and a batch recompute with no modifications would
     * traverse, dispatch nothing, and answer 202 — telling an agent its work restarted when
     * nothing did.
     *
     * Keyed on the declared scope rather than on the HTTP method, because the scope is the
     * project's own statement about what the tool does.
     */
    #[Test]
    public function everyWritingToolValidatesItsInput(): void
    {
        $offenders = [];

        foreach ($this->tools() as $name => $operation) {
            if ('trips:write' !== ($operation->getExtraProperties()['mcp_scope'] ?? null)) {
                continue;
            }

            if (true !== $operation->canValidate()) {
                $offenders[] = $name;
            }
        }

        self::assertSame([], $offenders, \sprintf(
            'MCP tool(s) that write without declaring `validate: true`: %s. '.
            "The MCP handler defaults it to false, so none of the input's constraints run.",
            implode(', ', $offenders),
        ));
    }

    #[Test]
    public function annotationsUseKeysTheSdkActuallyReads(): void
    {
        $offenders = [];

        foreach ($this->tools() as $name => $operation) {
            $annotations = $operation instanceof McpTool ? $operation->getAnnotations() : null;

            if (null === $annotations) {
                continue;
            }

            self::assertIsArray($annotations, \sprintf('Tool "%s" declares non-array annotations.', $name));

            foreach (array_keys($annotations) as $key) {
                if (!\in_array($key, self::KNOWN_ANNOTATIONS, true)) {
                    $offenders[] = \sprintf('%s: %s', $name, (string) $key);
                }
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "Unknown annotation key(s):\n  %s\nToolAnnotations::fromArray() ignores what it does not know, ".
            'so a misspelt hint ships as no hint at all. Known keys: %s.',
            implode("\n  ", $offenders),
            implode(', ', self::KNOWN_ANNOTATIONS),
        ));
    }

    /**
     * A tool that carries a body says which record it fills, and publishes a schema for it.
     *
     * `mcp_input` names the class the arguments are denormalized into; `input` names the class
     * whose properties become the published `inputSchema`. They are different jobs and usually
     * different classes — the schema also carries the addressing and control arguments, which
     * are not fields of the record. Without the first, {@see McpDeserializeProvider} has nothing
     * to build; without the second, it has no list of accepted argument names.
     */
    #[Test]
    public function everyToolWithABodyNamesBothItsSchemaAndItsRecord(): void
    {
        $offenders = [];

        foreach ($this->tools() as $name => $operation) {
            $target = $operation->getExtraProperties()[McpDeserializeProvider::INPUT] ?? null;

            if (null === $target) {
                continue;
            }

            if (!\is_string($target) || !class_exists($target)) {
                $offenders[] = \sprintf('%s: `mcp_input` is not a class', $name);
                continue;
            }

            if (null === $this->inputClass($operation)) {
                $offenders[] = \sprintf('%s: declares `mcp_input` but no input class', $name);
            }
        }

        self::assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /**
     * What the schema publishes is exactly what can be written.
     *
     * A published property that the record does not accept is the worst kind of wrong, because
     * nothing reports it: the model reads the schema, sends the argument, the call succeeds, and
     * the value is nowhere. The model has no reason not to send it again — so it does, forever.
     *
     * Three kinds of published property are exempt, and all three are declared rather than
     * guessed: {@see McpArguments::CONTROL} steers the call, the operation's URI variables
     * address it, and `mcp_control` names whatever else the tool's own processor reads (the
     * branch discriminator of `edit_stages`). Guessing the third from "not a property of the
     * record" would exempt precisely the mistake this test exists to catch.
     */
    #[Test]
    public function everyPublishedArgumentCanBeWrittenToTheRecord(): void
    {
        $names = self::getContainer()->get('api_platform.metadata.property.name_collection_factory');
        self::assertInstanceOf(PropertyNameCollectionFactoryInterface::class, $names);
        $metadata = self::getContainer()->get('api_platform.metadata.property.metadata_factory');
        self::assertInstanceOf(PropertyMetadataFactoryInterface::class, $metadata);

        $offenders = [];

        foreach ($this->tools() as $name => $operation) {
            $target = $operation->getExtraProperties()[McpDeserializeProvider::INPUT] ?? null;
            $input = $this->inputClass($operation);

            if (!\is_string($target) || !$operation instanceof HttpOperation || null === $input) {
                continue;
            }

            $declared = $this->stringList($operation->getExtraProperties()[McpDeserializeProvider::CONTROL] ?? []);
            $steering = array_merge(McpArguments::CONTROL, array_keys($operation->getUriVariables() ?? []), $declared);
            $published = $this->stringList(iterator_to_array($names->create($input)));
            $writable = $this->stringList(iterator_to_array($names->create($target)));

            foreach (array_diff($declared, $published) as $stray) {
                $offenders[] = \sprintf('%s: `mcp_control` names "%s", which the input schema does not publish', $name, $stray);
            }

            foreach (array_diff($published, $steering) as $property) {
                if (!\in_array($property, $writable, true)) {
                    $offenders[] = \sprintf('%s: publishes "%s", absent from %s', $name, $property, $target);
                    continue;
                }

                if (false === $metadata->create($target, $property)->isWritable()) {
                    $offenders[] = \sprintf('%s: publishes "%s", which %s refuses to write', $name, $property, $target);
                }
            }
        }

        self::assertSame([], $offenders, "Published argument(s) that cannot land:\n  ".implode("\n  ", $offenders));
    }

    /** Guards the guards: a scan that found nothing would keep every assertion above green. */
    #[Test]
    public function theScanFindsTools(): void
    {
        self::assertNotSame([], $this->tools(), 'No MCP tool was found at all — the scan is looking in the wrong place.');
    }

    private function inputClass(Operation $operation): ?string
    {
        $input = $operation->getInput();
        $class = \is_array($input) ? ($input['class'] ?? null) : null;

        return \is_string($class) ? $class : null;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            self::assertIsString($value);
            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @return array<string, Operation>
     */
    private function tools(): array
    {
        self::bootKernel();

        /** @var ResourceNameCollectionFactoryInterface $names */
        $names = self::getContainer()->get('api_platform.metadata.resource.name_collection_factory');
        /** @var ResourceMetadataCollectionFactoryInterface $metadata */
        $metadata = self::getContainer()->get('api_platform.metadata.resource.metadata_collection_factory');

        $tools = [];

        foreach ($names->create() as $resourceClass) {
            foreach ($metadata->create($resourceClass) as $resource) {
                foreach ($resource->getMcp() ?? [] as $key => $operation) {
                    $tools[\is_string($key) && '' !== $key ? $key : ($operation->getName() ?? '(unnamed)')] = $operation;
                }
            }
        }

        return $tools;
    }
}
