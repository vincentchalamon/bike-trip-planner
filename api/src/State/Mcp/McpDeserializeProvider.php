<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Turns a tool call's arguments into the record its processor expects.
 *
 * What {@see \ApiPlatform\State\Provider\DeserializeProvider} is to an HTTP request body, this is
 * to `tools/call` arguments: the MCP handler defaults `deserialize` to false, so nothing on that
 * transport ever builds an input object out of what the caller sent.
 *
 * **Its position in the chain is the whole design, and it is not interchangeable.** It is wired
 * in `config/services.php` at priority 250, which lands it between `ValidateProvider` (200) and
 * `DeserializeProvider` (300) — exactly where the HTTP transport does this same job. Both
 * neighbours are load-bearing:
 *
 *  - below validation, because the constraints have to judge the merged record. Checked against
 *    the record as it was stored, every `Assert\Range` on `TripRequest` passes while the values
 *    the caller actually sent go in unexamined — and the CI guard that demands `validate: true`
 *    stays green throughout;
 *  - above {@see \ApiPlatform\State\Provider\ReadProvider}, because it publishes `previous_data`
 *    as a clone of whatever it just read. Merge any deeper — in the operation's own provider,
 *    say — and the record "before" the edit becomes a copy of the record after it. Two guards
 *    read that: {@see \App\State\TripLockProcessor} would judge a trip by the very values the
 *    caller just sent, so a tool passing a future `startDate` would unlock the trip it is
 *    editing and never see its 423; and {@see \App\State\TripUpdateProcessor} compares before
 *    with after to decide what to recompute, so it would find nothing changed and recompute
 *    nothing, on every edit.
 *
 * It decorates the main provider chain, which HTTP shares, and returns on the first line for
 * anything that is not a tool call: the id `api_platform.mcp.state_provider` is an alias to that
 * very chain, so decorating *it* wraps the whole thing from outside — past validation, which is
 * the one place this must not be.
 *
 * @implements ProviderInterface<object>
 */
final readonly class McpDeserializeProvider implements ProviderInterface
{
    /**
     * The class the arguments are denormalized into.
     *
     * Deliberately not `input.class`. That one publishes the `inputSchema` a model reads, so it
     * carries the addressing and control arguments too — `tripId`, `version`,
     * `confirmationToken` — none of which belong to the record being written. This names the
     * record. The two coincide for a tool whose arguments happen to be exactly its input DTO,
     * and they cannot coincide for a tool that edits a loaded row: `OBJECT_TO_POPULATE` must be
     * an instance of the class being denormalized.
     */
    public const string INPUT = 'mcp_input';

    /**
     * Arguments this tool reads itself, beyond {@see McpArguments::CONTROL}.
     *
     * `edit_stages` is the case: `action` picks the branch and `stageId` addresses it, and its
     * dispatcher reads both straight from the arguments. They are published in the schema and
     * they are not fields of the record, so they have to be named somewhere — here, rather than
     * inferred from "not a property of the target", which is precisely what
     * {@see \App\Tests\Integration\Mcp\McpToolContractTest} exists to catch.
     */
    public const string CONTROL = 'mcp_control';

    /**
     * @param ProviderInterface<object> $decorated
     */
    public function __construct(
        private ProviderInterface $decorated,
        private DenormalizerInterface $denormalizer,
        private PropertyNameCollectionFactoryInterface $propertyNames,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $data = $this->decorated->provide($operation, $uriVariables, $context);

        if (!$operation instanceof HttpOperation || !McpArguments::isToolCall($context)) {
            return $data;
        }

        $target = $operation->getExtraProperties()[self::INPUT] ?? null;
        if (!\is_string($target)) {
            return $data;
        }

        $arguments = McpArguments::from($context)->arguments;
        $payload = $this->payload($operation, $arguments, array_keys($uriVariables));

        $denormalizationContext = [
            // Belt to the braces above: the payload is already reduced to what the schema
            // publishes, so this can only fire if the two disagree — and then it fires loudly
            // rather than dropping a value the caller believes it sent.
            AbstractObjectNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
        ] + ($operation->getDenormalizationContext() ?? []);

        if ($data instanceof $target) {
            // A copy, not the row. The row is still the managed entity, and leaving it clean is
            // what lets a guard refuse this call — a stale version, a started trip, a missing
            // confirmation token — without the refused edit sitting in the identity map waiting
            // for someone else's flush. `storeRequest()` is built for a detached source.
            $denormalizationContext[AbstractNormalizer::OBJECT_TO_POPULATE] = clone $data;
            $denormalizationContext[AbstractObjectNormalizer::DEEP_OBJECT_TO_POPULATE] = true;
        }

        $merged = $this->denormalizer->denormalize($payload, $target, 'json', $denormalizationContext);
        \assert(\is_object($merged));

        return $merged;
    }

    /**
     * The arguments that describe the record, and a refusal for anything else.
     *
     * A model that invents an argument gets told so by name. Dropping it silently is the worse
     * failure: the call succeeds, the value it asked for is not there, and the model has no
     * reason not to send it again — forever.
     *
     * @param array<string, mixed> $arguments
     * @param list<string>         $addressing
     *
     * @return array<string, mixed>
     */
    private function payload(HttpOperation $operation, array $arguments, array $addressing): array
    {
        $steering = array_merge(McpArguments::CONTROL, $addressing, $this->declaredControl($operation));
        $published = $this->published($operation);

        $unknown = array_diff(array_keys($arguments), $published, $steering);
        if ([] !== $unknown) {
            throw new UnprocessableEntityHttpException(\sprintf('Unknown argument(s): %s. This tool accepts: %s.', implode(', ', array_map(static fn (int|string $name): string => CallerText::quote((string) $name), $unknown)), implode(', ', array_unique(array_merge($published, $steering)))));
        }

        return array_diff_key($arguments, array_flip($steering));
    }

    /**
     * @return list<string>
     */
    private function declaredControl(HttpOperation $operation): array
    {
        $declared = $operation->getExtraProperties()[self::CONTROL] ?? [];
        \assert(\is_array($declared));

        $names = [];
        foreach ($declared as $name) {
            \assert(\is_string($name));
            $names[] = $name;
        }

        return $names;
    }

    /**
     * The property names the tool's `inputSchema` publishes.
     *
     * Read from the input DTO through API Platform's own metadata rather than by reflection, so
     * this and the published schema cannot disagree about what a property is.
     *
     * @return list<string>
     */
    private function published(HttpOperation $operation): array
    {
        $input = $operation->getInput();
        $class = \is_array($input) ? ($input['class'] ?? null) : null;

        if (!\is_string($class)) {
            throw new \LogicException(\sprintf('The MCP tool "%s" declares "%s" but no input class, so nothing says which arguments it accepts.', (string) $operation->getName(), self::INPUT));
        }

        $names = [];
        foreach ($this->propertyNames->create($class) as $name) {
            \assert(\is_string($name));
            $names[] = $name;
        }

        return $names;
    }
}
