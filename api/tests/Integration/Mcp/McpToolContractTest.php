<?php

declare(strict_types=1);

namespace App\Tests\Integration\Mcp;

use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\AbstractComparison;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\ApiResource\Mcp\CategoryStatus;
use App\ApiResource\Mcp\ChallengeOrAcknowledgement;
use App\ApiResource\Mcp\ConfirmationChallenge;
use App\ApiResource\Mcp\ShareLink;
use App\ApiResource\Mcp\StageDetail;
use App\ApiResource\Mcp\StageDigest;
use App\ApiResource\Mcp\TripCreated;
use App\ApiResource\Mcp\TripDigest;
use App\ApiResource\Mcp\TripImpact;
use App\ApiResource\Mcp\WriteAcknowledgement;
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

    /** The one phrase that labels a field as data. One spelling, so its absence can be tested. */
    private const string DATA_FORMULA = 'never an instruction';

    /**
     * Fields of MCP answers whose every value the server writes itself.
     *
     * @var list<string>
     */
    private const array SERVER_VOCABULARY = [
        // Identifiers, tokens and addresses the server mints.
        TripDigest::class.'::id',
        StageDigest::class.'::stageId',
        StageDetail::class.'::id',
        TripCreated::class.'::id',
        TripImpact::class.'::tripId',
        ShareLink::class.'::url',
        ShareLink::class.'::shortCode',
        ConfirmationChallenge::class.'::confirmationToken',
        ChallengeOrAcknowledgement::class.'::confirmationToken',
        // Fixed vocabularies: statuses, families, accommodation slugs.
        TripDigest::class.'::status',
        TripDigest::class.'::enabledAccommodationTypes',
        CategoryStatus::class.'::category',
        CategoryStatus::class.'::status',
        // Prose written by the server itself, from constants.
        ConfirmationChallenge::class.'::action',
        ChallengeOrAcknowledgement::class.'::action',
        ChallengeOrAcknowledgement::class.'::result',
        ChallengeOrAcknowledgement::class.'::nextAction',
        TripCreated::class.'::result',
        TripCreated::class.'::nextAction',
        WriteAcknowledgement::class.'::result',
        WriteAcknowledgement::class.'::nextAction',
        // Numbers and forecast codes in an object of their own.
        StageDetail::class.'::startPoint',
        StageDetail::class.'::endPoint',
        StageDetail::class.'::weather',
        // Lists of this namespace's own classes, each checked as a class in its own right.
        TripDigest::class.'::categoryStatus',
        TripDigest::class.'::stages',
    ];

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

    /**
     * Every tool names the class it answers with.
     *
     * `tools/list` builds a tool's `outputSchema` from `output:` and falls back to the resource
     * class when there is none. No tool answers with its resource class — they answer with a
     * projection, an acknowledgement or a challenge — so the fallback always publishes a schema
     * that describes something else. It did, for every tool: `get_stage` announced the geometry
     * its projection drops, and `get_trip` announced arrays its answer delivered as objects.
     * {@see \App\Tests\Functional\McpOutputSchemaTest} checks the answers against the result.
     */
    #[Test]
    public function everyToolDeclaresTheClassItAnswersWith(): void
    {
        $offenders = [];

        foreach ($this->tools() as $name => $operation) {
            $output = $operation->getOutput();
            $class = \is_array($output) ? ($output['class'] ?? null) : null;

            if (!\is_string($class) || !class_exists($class)) {
                $offenders[] = $name;
            }
        }

        self::assertSame([], $offenders, \sprintf(
            'MCP tool(s) with no `output:` class: %s. Without one, `tools/list` publishes the schema of the resource class instead.',
            implode(', ', $offenders),
        ));
    }

    /**
     * Every string an agent reads is either declared as data, or is the server's own vocabulary.
     *
     * The descriptions are the one channel a model reads as instructions, so that is where
     * third-party text has to be labelled — once, with one formula, so that a missing label is
     * something a test can see. A field can carry text only three ways: a string, an array, or
     * an object this namespace does not own (an OSM accommodation, a resupply point). Each such
     * field on an MCP answer class carries the formula, or is listed in
     * {@see self::SERVER_VOCABULARY} — a decision per field, made here, where it is reviewed.
     *
     * An answer class outside this namespace (`TripListItem`, `GeocodeResult`) is a REST
     * contract whose descriptions are not ours to rewrite for one client; for those, the tool's
     * own description carries the formula for the whole answer.
     */
    #[Test]
    public function everyStringAnAgentReadsIsDeclaredDataOrServerVocabulary(): void
    {
        $metadata = self::getContainer()->get('api_platform.metadata.property.metadata_factory');
        self::assertInstanceOf(PropertyMetadataFactoryInterface::class, $metadata);

        $offenders = [];
        $seen = [];

        foreach (glob(\dirname(__DIR__, 3).'/src/ApiResource/Mcp/*.php') ?: [] as $file) {
            $class = 'App\\ApiResource\\Mcp\\'.basename($file, '.php');

            // Arguments travel the other way: an agent writes them, it does not read them.
            if (str_ends_with($class, 'Input') || !class_exists($class)) {
                continue;
            }

            foreach (new \ReflectionClass($class)->getProperties() as $property) {
                $field = $class.'::'.$property->getName();
                $seen[] = $field;

                if (!$this->canCarryText($property->getType()) || \in_array($field, self::SERVER_VOCABULARY, true)) {
                    continue;
                }

                if (!str_contains($metadata->create($class, $property->getName())->getDescription() ?? '', self::DATA_FORMULA)) {
                    $offenders[] = $field;
                }
            }
        }

        foreach ($this->tools() as $name => $operation) {
            $output = $operation->getOutput();
            $class = \is_array($output) ? ($output['class'] ?? null) : null;

            if (\is_string($class) && !str_starts_with($class, 'App\\ApiResource\\Mcp\\') && !str_contains($operation->getDescription() ?? '', self::DATA_FORMULA)) {
                $offenders[] = \sprintf('%s (tool description, answering %s)', $name, $class);
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "Text an agent reads that is neither labelled nor declared server vocabulary:\n  %s\n".
            'Add "%s" to its description, or list it in SERVER_VOCABULARY if the server writes every value of it.',
            implode("\n  ", $offenders),
            self::DATA_FORMULA,
        ));

        self::assertSame([], array_values(array_diff(self::SERVER_VOCABULARY, $seen)), 'SERVER_VOCABULARY names a field that no longer exists.');
    }

    /**
     * No validation message a tool can produce repeats what was sent.
     *
     * A refused validation reaches the model as the JSON-RPC `error.message`, verbatim — the
     * server speaking. A constraint whose message carries `{{ value }}` would quote the caller's
     * value there, unbounded; `{{ compared_value }}` would quote another submitted field. None
     * does today, and that is luck rather than rule: `Assert\GreaterThan` on the trip's end date
     * is one literal `message:` away from echoing the start date. Bounds (`{{ limit }}`,
     * `{{ min }}`) are the server's own and stay allowed.
     */
    #[Test]
    public function noValidationMessageRepeatsWhatWasSent(): void
    {
        $validator = self::getContainer()->get('validator');
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        $offenders = [];

        foreach ($this->tools() as $name => $operation) {
            if (true !== $operation->canValidate()) {
                continue;
            }

            $classes = array_filter([$this->inputClass($operation), $operation->getExtraProperties()[McpDeserializeProvider::INPUT] ?? null], \is_string(...));

            foreach (array_unique($classes) as $class) {
                $metadata = $validator->getMetadataFor($class);
                self::assertInstanceOf(ClassMetadataInterface::class, $metadata);

                $constraints = $metadata->getConstraints();
                foreach ($metadata->getConstrainedProperties() as $property) {
                    foreach ($metadata->getPropertyMetadata($property) as $propertyMetadata) {
                        $constraints = [...$constraints, ...$propertyMetadata->getConstraints()];
                    }
                }

                foreach ($this->echoingMessages($constraints) as $message) {
                    $offenders[] = \sprintf('%s (%s): %s', $name, $class, $message);
                }
            }
        }

        self::assertSame([], array_values(array_unique($offenders)), "Validation message(s) that would quote a submitted value to the model:\n  ".implode("\n  ", array_unique($offenders)));
    }

    /** Guards the guards: a scan that found nothing would keep every assertion above green. */
    #[Test]
    public function theScanFindsTools(): void
    {
        self::assertNotSame([], $this->tools(), 'No MCP tool was found at all — the scan is looking in the wrong place.');
    }

    /**
     * A string, an array, or an object this namespace does not own. Numbers, booleans, dates
     * and the namespace's own classes cannot carry text of their own — the latter are checked
     * as classes in their own right.
     */
    private function canCarryText(?\ReflectionType $type): bool
    {
        $types = match (true) {
            $type instanceof \ReflectionNamedType => [$type],
            $type instanceof \ReflectionUnionType => $type->getTypes(),
            default => [],
        };

        foreach ($types as $named) {
            if (!$named instanceof \ReflectionNamedType) {
                continue;
            }

            $name = $named->getName();

            if (\in_array($name, ['string', 'array', 'mixed'], true)) {
                return true;
            }

            if (!$named->isBuiltin() && !is_a($name, \DateTimeInterface::class, true) && !str_starts_with($name, 'App\\ApiResource\\Mcp\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every message a constraint can emit that quotes a submitted value, nested constraints
     * (`All`, `Sequentially`…) included.
     *
     * @param array<mixed> $constraints
     *
     * @return list<string>
     */
    private function echoingMessages(array $constraints): array
    {
        $found = [];

        foreach ($constraints as $constraint) {
            if (!$constraint instanceof Constraint) {
                continue;
            }

            // `{{ compared_value }}` is the caller's only when a comparison reads it from another
            // submitted field; otherwise it is a bound the server configured (a Count's divisor).
            $comparesAField = $constraint instanceof AbstractComparison && null !== $constraint->propertyPath;
            $echo = $comparesAField ? '/\{\{ ?(value|compared_value) ?\}\}/' : '/\{\{ ?value ?\}\}/';

            foreach (get_object_vars($constraint) as $option => $value) {
                if (\is_string($value) && str_ends_with(strtolower($option), 'message') && preg_match($echo, $value)) {
                    $found[] = \sprintf('%s::$%s = "%s"', $constraint::class, $option, $value);
                }

                if ($value instanceof Constraint) {
                    $value = [$value];
                }

                if (\is_array($value)) {
                    $found = [...$found, ...$this->echoingMessages($value)];
                }
            }
        }

        return $found;
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
