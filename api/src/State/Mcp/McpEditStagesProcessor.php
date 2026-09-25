<?php

declare(strict_types=1);

namespace App\State\Mcp;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Mcp\WriteAcknowledgement;
use App\ApiResource\StageRequest;
use App\Concurrency\TripVersionEtag;
use App\State\RestDayInsertProcessor;
use App\State\StageCreateProcessor;
use App\State\StageDeleteProcessor;
use App\State\StageMoveProcessor;
use App\State\StageUpdateProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Restructures the days of a trip: one tool, five ways to do it.
 *
 * The five HTTP operations behind this are one concept — recutting the day split — and they show
 * it: the same authorization expression, the same critical section in `mutateStages()`, the same
 * version bump, the same two guard flags. What differs between them is a verb and a URL, which
 * are artefacts of REST and mean nothing to a model. `add_waypoint` is deliberately NOT among
 * them: it lives under the same URL prefix by accident, moves no version, and its HTTP twin
 * requires no precondition — folding it in would force `version` to be optional here and make
 * the description lie.
 *
 * The five processors are injected and called directly rather than dispatched. None of them
 * reads `$operation`; they read `$uriVariables` and `$context`, and the context is what carries
 * the precondition — a message bus would drop it and turn a refusal into an asynchronous
 * surprise.
 *
 * @implements ProcessorInterface<StageRequest, WriteAcknowledgement>
 */
final readonly class McpEditStagesProcessor implements ProcessorInterface
{
    private const string ADD = 'add';

    private const string UPDATE = 'update';

    private const string MOVE = 'move';

    private const string DELETE = 'delete';

    private const string REST_DAY = 'rest_day';

    /** @var list<string> */
    private const array ACTIONS = [self::ADD, self::UPDATE, self::MOVE, self::DELETE, self::REST_DAY];

    /**
     * @param ProcessorInterface<StageRequest, mixed> $creates
     * @param ProcessorInterface<StageRequest, mixed> $updates
     * @param ProcessorInterface<StageRequest, mixed> $moves
     * @param ProcessorInterface<null, void>          $deletes
     * @param ProcessorInterface<null, mixed>         $restDays
     */
    public function __construct(
        #[Autowire(service: StageCreateProcessor::class)]
        private ProcessorInterface $creates,
        #[Autowire(service: StageUpdateProcessor::class)]
        private ProcessorInterface $updates,
        #[Autowire(service: StageMoveProcessor::class)]
        private ProcessorInterface $moves,
        #[Autowire(service: StageDeleteProcessor::class)]
        private ProcessorInterface $deletes,
        #[Autowire(service: RestDayInsertProcessor::class)]
        private ProcessorInterface $restDays,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WriteAcknowledgement
    {
        $arguments = McpArguments::from($context)->arguments;
        $action = $arguments['action'] ?? null;
        $tripId = $uriVariables['tripId'] ?? '';
        \assert(\is_string($tripId));

        // The action is judged first, and the order is not cosmetic: asked to `split` a day, a
        // model told "this action needs a stageId" would send one and be refused again, having
        // learnt nothing. It has to hear that the action itself does not exist.
        if (!\in_array($action, self::ACTIONS, true)) {
            throw new UnprocessableEntityHttpException(\sprintf('Unknown "action": %s. Expected one of: %s.', \is_string($action) && '' !== $action ? CallerText::quote($action) : 'none given', implode(', ', self::ACTIONS)));
        }

        $addressed = ['tripId' => $tripId, 'stageId' => $this->stageId($arguments, $action)];

        match ($action) {
            self::ADD => $this->creates->process($data, $operation, ['tripId' => $tripId], $context),
            self::UPDATE => $this->updates->process($data, $operation, $addressed, $context),
            self::MOVE => $this->moves->process($data, $operation, $addressed, $context),
            // Both take no body on HTTP, and passing one would be a lie about what they read.
            self::DELETE => $this->deletes->process(null, $operation, $addressed, $context),
            // No default arm: the check above already narrowed the action to these five, and
            // adding one would only hide a sixth being forgotten here.
            self::REST_DAY => $this->restDays->process(null, $operation, $addressed, $context),
        };

        return new WriteAcknowledgement(
            result: $this->wording($action),
            version: $this->version($context),
            nextAction: 'The days are being recomputed. Pass the version above to the next edit — there is no need to read the trip again first.',
        );
    }

    /**
     * The day being acted on, or a refusal that names what is missing.
     *
     * The published schema cannot say that four of the five actions need this and one does not,
     * so the message has to. A model that guesses is told once and gets it right next time; a
     * silent failure here would be a call that edits nothing and reports success.
     *
     * @param array<string, mixed> $arguments
     */
    private function stageId(array $arguments, mixed $action): string
    {
        $stageId = $arguments['stageId'] ?? null;

        if (self::ADD === $action) {
            return '';
        }

        if (!\is_string($stageId) || '' === $stageId) {
            throw new UnprocessableEntityHttpException(\sprintf('The %s action needs a "stageId": the identifier of the day to act on, as published by `get_trip`.', CallerText::quote(\is_string($action) ? $action : '')));
        }

        return $stageId;
    }

    private function wording(mixed $action): string
    {
        return match ($action) {
            self::ADD => 'A day has been inserted. The days after it have shifted, and their dates with them.',
            self::UPDATE => 'The day has been changed. Its route is being recalculated, and the next day now starts where this one ends.',
            self::MOVE => 'The day has been moved. Every day between its old and new positions has been renumbered.',
            self::DELETE => 'The day has been removed and merged with the one beside it.',
            self::REST_DAY => 'A rest day has been inserted. The following day starts where it did, and every date after it has shifted by one.',
            default => 'The trip has been restructured.',
        };
    }

    /**
     * The version the write produced, stamped from inside the locked section.
     *
     * Re-reading it here would hand back whichever version won a race after the lock was
     * released, which is the whole reason the processors stamp it rather than let a caller look
     * it up.
     *
     * @param array<string, mixed> $context
     */
    private function version(array $context): ?int
    {
        $request = $context['request'] ?? null;
        $stamped = $request instanceof Request ? $request->attributes->get(TripVersionEtag::ATTRIBUTE) : null;

        return \is_int($stamped) ? $stamped : null;
    }
}
