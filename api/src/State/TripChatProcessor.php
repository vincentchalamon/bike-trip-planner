<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\Model\GeoPosition;
use App\ApiResource\Model\PoiSuggestionDto;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripChatRequest;
use App\ApiResource\TripChatResponse;
use App\ApiResource\TripRequest;
use App\Entity\TripChatMessage;
use App\Entity\User;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\InRide\InRideAssistant;
use App\InRide\PoiSuggestion;
use App\Llm\ChatActionInterpreter;
use App\Llm\ChatHistoryStore;
use App\Llm\Dto\ChatAction;
use App\Llm\Exception\OllamaUnavailableException;
use App\Llm\LlmClientInterface;
use App\Llm\SystemPromptLoader;
use App\Message\RecalculateStages;
use App\Repository\TripRequestRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Handles `POST /trips/{id}/chat`: orchestrates the LLaMA 3B dialogue assistant.
 *
 * Pipeline:
 * 1. Enforce a per-user rate limit (20 req/min) to protect the local LLM.
 * 2. Verify the trip exists; load minimal context for the dialogue prompt.
 * 3. Build the chat history (last {@see ChatHistoryStore::MAX_MESSAGES} turns)
 *    plus the new user message, and call {@see LlmClientInterface::chat()}.
 * 4. Parse the JSON envelope into a {@see ChatAction} via {@see ChatActionInterpreter}.
 * 5. Flag the response as `dispatched` for actions that require recomputation
 *    (the actual Messenger wiring is delivered by a follow-up issue).
 *
 * When the LLM is disabled or unreachable, the endpoint returns 503 so the
 * frontend can show a clear error instead of a vague fallback message.
 *
 * @implements ProcessorInterface<TripChatRequest, TripChatResponse>
 */
final readonly class TripChatProcessor implements ProcessorInterface
{
    public const string PROMPT_NAME = 'dialogue';

    public const string DEFAULT_MODEL = 'llama3.2:3b';

    public const int MAX_RESPONSE_TOKENS = 600;

    /**
     * Actions that mutate the trip and therefore require backend recomputation.
     *
     * Each non-info, non-change_route action is mapped to a {@see RecalculateStages}
     * dispatch (with `skipAiAnalysis: true`) by {@see self::dispatchRecomputation()}.
     *
     * @var list<string>
     */
    private const array RECOMPUTE_ACTIONS = [
        ChatAction::ACTION_SPLIT_STAGE,
        ChatAction::ACTION_MERGE_STAGES,
        ChatAction::ACTION_ADD_WAYPOINT,
        ChatAction::ACTION_CHANGE_ACCOMMODATION,
        ChatAction::ACTION_ADJUST_DISTANCE,
    ];

    private string $model;

    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
        private LlmClientInterface $llmClient,
        private SystemPromptLoader $promptLoader,
        private ChatActionInterpreter $interpreter,
        private ChatHistoryStore $historyStore,
        private Security $security,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private TripGenerationTrackerInterface $generationTracker,
        private InRideAssistant $inRideAssistant,
        #[Autowire(service: 'limiter.trip_chat')]
        private RateLimiterFactory $tripChatLimiter,
        private ?EntityManagerInterface $entityManager = null,
        #[Autowire(env: 'default::OLLAMA_DIALOGUE_MODEL')]
        ?string $model = null,
    ) {
        $this->model = (null === $model || '' === $model) ? self::DEFAULT_MODEL : $model;
    }

    /**
     * @param TripChatRequest    $data
     * @param Post               $operation
     * @param array{id?: string} $uriVariables
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TripChatResponse
    {
        \assert($data instanceof TripChatRequest);

        $tripId = $uriVariables['id'] ?? '';
        if ('' === $tripId) {
            throw new NotFoundHttpException('Trip not found.');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new HttpException(401, 'Authentication required.');
        }

        $userId = $user->getId()->toRfc4122();

        // Trip existence is guaranteed by TripRequestProvider — wired as the
        // operation's provider, it throws NotFoundHttpException before this
        // processor is entered. Don't re-read the cache here.

        // Feature-flag guard before consuming a rate-limit token, so a user
        // hitting a disabled endpoint doesn't burn their 20 req/min quota.
        if (!$this->llmClient->isEnabled()) {
            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'AI assistant is currently disabled.');
        }

        $limiter = $this->tripChatLimiter->create($userId);
        $rateLimit = $limiter->consume();
        if (!$rateLimit->isAccepted()) {
            $retryAfter = $rateLimit->getRetryAfter();
            $secondsUntilRetry = max(0, $retryAfter->getTimestamp() - new \DateTimeImmutable()->getTimestamp());

            throw new TooManyRequestsHttpException(retryAfter: $secondsUntilRetry, message: 'Chat rate limit reached. Please wait a moment before retrying.');
        }

        // In-ride branch: the rider's GPS position switches the assistant into
        // POI search mode. The planning pipeline (dialogue prompt, action
        // interpreter, Messenger dispatch) is bypassed entirely.
        if ($data->position instanceof GeoPosition) {
            return $this->processInRide($user, $tripId, $userId, $data);
        }

        $systemPrompt = $this->promptLoader->load(self::PROMPT_NAME);
        $userMessage = $this->buildUserMessage($data);
        $history = $this->historyStore->get($tripId, $userId);

        $messages = [...$history, ['role' => 'user', 'content' => $userMessage]];

        try {
            $response = $this->llmClient->chat(
                model: $this->model,
                messages: $messages,
                systemPrompt: $systemPrompt,
                options: [
                    'format' => 'json',
                    'num_predict' => self::MAX_RESPONSE_TOKENS,
                ],
            );
        } catch (OllamaUnavailableException $ollamaUnavailableException) {
            $this->logger->warning('Ollama unreachable — chat endpoint returning 503.', [
                'tripId' => $tripId,
                'error' => $ollamaUnavailableException->getMessage(),
            ]);

            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'AI assistant is temporarily unavailable. Please retry shortly.', previous: $ollamaUnavailableException);
        }

        if (null === $response) {
            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'AI assistant returned an empty response. Please retry.');
        }

        $rawContent = $this->extractText($response);
        if (null === $rawContent) {
            $this->logger->warning('Ollama chat response missing message content.', ['tripId' => $tripId]);

            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'AI assistant returned an invalid response. Please retry.');
        }

        $action = $this->interpreter->interpret($rawContent);

        $this->historyStore->appendMany($tripId, $userId, [
            ['role' => 'user', 'content' => $userMessage],
            ['role' => 'assistant', 'content' => $rawContent],
        ]);

        $this->persistChatTurn($tripId, $user, $userMessage, $rawContent, $action);

        $impactedStageNumbers = $this->resolveImpactedStageNumbers($tripId, $action);
        $requiresFullAnalysis = ChatAction::ACTION_CHANGE_ROUTE === $action->action;
        $dispatched = false;

        if ($requiresFullAnalysis) {
            $this->logger->info('Chat action requires full trip re-analysis (Acte 2).', [
                'tripId' => $tripId,
                'action' => $action->action,
            ]);
        } elseif ([] !== $impactedStageNumbers) {
            $dispatched = $this->dispatchRecomputation($tripId, $impactedStageNumbers);

            if ($dispatched) {
                $this->logger->info('Chat action triggered inline recomputation.', [
                    'tripId' => $tripId,
                    'action' => $action->action,
                    'impactedStageNumbers' => $impactedStageNumbers,
                ]);
            }
        }

        return new TripChatResponse(
            tripId: $tripId,
            action: $action->action,
            params: $action->params,
            response: $action->response,
            dispatched: $dispatched,
            impactedStageNumbers: $impactedStageNumbers,
            requiresFullAnalysis: $requiresFullAnalysis,
        );
    }

    /**
     * Maps a parsed action to the list of 1-indexed day numbers whose
     * recomputation must be triggered.
     *
     * @return list<int>
     */
    private function resolveImpactedStageNumbers(string $tripId, ChatAction $action): array
    {
        if (!\in_array($action->action, self::RECOMPUTE_ACTIONS, true)) {
            return [];
        }

        $stages = $this->tripStateManager->getStages($tripId) ?? [];
        $totalStages = \count($stages);
        if (0 === $totalStages) {
            return [];
        }

        $params = $action->params;

        switch ($action->action) {
            case ChatAction::ACTION_SPLIT_STAGE:
                $stage = $this->extractStageNumber($params['stage'] ?? null);
                if (null === $stage || $stage < 1 || $stage > $totalStages) {
                    return [];
                }

                // Split produces two halves: the original day and the new day immediately
                // after it. When the rider asks to split the last stage, the second half
                // becomes a brand-new day number.
                $second = min($stage + 1, $totalStages + 1);

                return [$stage, $second];

            case ChatAction::ACTION_MERGE_STAGES:
                $raw = $params['stages'] ?? null;
                if (!\is_array($raw)) {
                    return [];
                }

                $values = array_values($raw);
                $first = $this->extractStageNumber($values[0] ?? null);
                $second = $this->extractStageNumber($values[1] ?? null);
                if (null === $first || null === $second) {
                    return [];
                }

                if ($first < 1 || $first > $totalStages || $second < 1 || $second > $totalStages) {
                    return [];
                }

                // The surviving stage after merging two consecutive days is the
                // lower-numbered one. Normalise to min() so a reversed [3, 2]
                // pair from the LLM still shimmers the correct card.
                return [min($first, $second)];

            case ChatAction::ACTION_ADD_WAYPOINT:

            case ChatAction::ACTION_ADJUST_DISTANCE:
                $stage = $this->extractStageNumber($params['stage'] ?? null);
                if (null === $stage || $stage < 1 || $stage > $totalStages) {
                    return [];
                }

                return [$stage];
            case ChatAction::ACTION_CHANGE_ACCOMMODATION:
                $stage = $this->extractStageNumber($params['stage'] ?? null);
                if (null === $stage || $stage < 1 || $stage > $totalStages) {
                    return [];
                }

                // Changing the accommodation moves the arrival point — the next stage
                // departs from the new spot, so recompute it too when it exists.
                $next = $stage + 1;

                return $next <= $totalStages ? [$stage, $next] : [$stage];
            default:
                return [];
        }
    }

    private function extractStageNumber(mixed $value): ?int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }

        if (\is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }

    /**
     * Dispatches a single {@see RecalculateStages} message covering the affected
     * stage indices (0-indexed for the message contract). Returns `true` when a
     * dispatch happened — `false` when the indices map to nothing recomputable.
     *
     * @param list<int> $stageNumbers 1-indexed day numbers
     */
    private function dispatchRecomputation(string $tripId, array $stageNumbers): bool
    {
        $indices = array_values(array_unique(array_map(
            static fn (int $n): int => $n - 1,
            $stageNumbers,
        )));

        if ([] === $indices) {
            return false;
        }

        // Bump generation: chat-driven edits invalidate in-flight computations on
        // the same trip so a stale ScanPois cannot land after the new STAGE_UPDATED.
        $generation = $this->generationTracker->increment($tripId);

        $this->messageBus->dispatch(new RecalculateStages(
            tripId: $tripId,
            affectedIndices: $indices,
            generation: $generation,
            skipAiAnalysis: true,
        ));

        return true;
    }

    /**
     * In-ride mode: delegate POI search to {@see InRideAssistant} and return
     * a response carrying the `find_poi` action together with the structured
     * POI suggestions.
     */
    private function processInRide(User $user, string $tripId, string $userId, TripChatRequest $data): TripChatResponse
    {
        \assert($data->position instanceof GeoPosition);

        // InRideAssistant::assist() already catches OllamaUnavailableException
        // and produces a markdown fallback narrative, so we do not re-wrap it
        // here.
        $response = $this->inRideAssistant->assist(
            message: $data->message,
            position: $data->position,
        );

        $this->historyStore->appendMany($tripId, $userId, [
            ['role' => 'user', 'content' => $data->message],
            ['role' => 'assistant', 'content' => $response->narrative],
        ]);

        $poisPayload = array_map(static fn (PoiSuggestion $p): array => $p->toArray(), $response->pois);

        // In-ride turns must reach PostgreSQL too, otherwise the rider's POI
        // search history disappears on the next page reload and defeats the
        // long-term persistence we wired in #458. We also fill the geo_*
        // columns and the pois JSONB so ChatHistoryLoader can rehydrate the
        // POI cards on a refresh.
        $this->persistChatTurn(
            $tripId,
            $user,
            $data->message,
            $response->narrative,
            new ChatAction(
                action: ChatAction::ACTION_FIND_POI,
                params: ['category' => $response->category],
                response: $response->narrative,
            ),
            position: $data->position,
            pois: $poisPayload,
        );

        return new TripChatResponse(
            tripId: $tripId,
            action: ChatAction::ACTION_FIND_POI,
            params: ['category' => $response->category],
            response: $response->narrative,
            dispatched: false,
            impactedStageNumbers: [],
            requiresFullAnalysis: false,
            // Hydrate the typed wire DTO so API Platform's serializer emits the
            // snake_case keys the PWA Zod schema and PoiCard expect (mirrors
            // PoiSuggestion::toArray() field-for-field).
            pois: array_map(
                PoiSuggestionDto::fromArray(...),
                $poisPayload,
            ),
        );
    }

    /**
     * Writes the user prompt + assistant reply to PostgreSQL alongside the Redis
     * context window (cf. #458). Failures are logged but never bubble up so a
     * transient DB hiccup cannot abort an otherwise successful chat turn — the
     * Redis history remains authoritative for the next LLM call either way.
     *
     * @param list<array{name: string, category: string, lat: float, lon: float, distance_m: int, detour_m: int, opening_hours_today: ?string, closes_at: ?string, phone: ?string, deeplink: string, warning: ?string}>|null $pois optional POI suggestions stored alongside an in-ride assistant turn
     */
    private function persistChatTurn(
        string $tripId,
        User $user,
        string $userMessage,
        string $assistantContent,
        ChatAction $action,
        ?GeoPosition $position = null,
        ?array $pois = null,
    ): void {
        // Pin the entity manager to a local variable so PHPStan keeps the
        // non-null narrowing inside the closure passed to wrapInTransaction
        // (property accesses widen back to `?EntityManagerInterface` across
        // closure boundaries at Level 9).
        $entityManager = $this->entityManager;
        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        try {
            $trip = $entityManager->getReference(TripRequest::class, $tripId);
            if (!$trip instanceof TripRequest) {
                return;
            }

            $geoLat = $position?->lat;
            $geoLon = $position?->lon;

            $userTurn = new TripChatMessage(
                trip: $trip,
                user: $user,
                role: TripChatMessage::ROLE_USER,
                content: $userMessage,
                geoLat: $geoLat,
                geoLon: $geoLon,
            );
            $assistantTurn = new TripChatMessage(
                trip: $trip,
                user: $user,
                role: TripChatMessage::ROLE_ASSISTANT,
                content: $assistantContent,
                action: $action->action,
                geoLat: $geoLat,
                geoLon: $geoLon,
                pois: null !== $pois && [] !== $pois ? $pois : null,
            );

            // `wrapInTransaction` gives us atomicity (both turns persisted or
            // neither) — it does NOT scope the UoW, since Doctrine ORM 3
            // dropped the optional `flush($entity)` argument. In our request
            // lifecycle the chat processor is the last writer (API Platform
            // processors run after input validation, no other entities are
            // dirty by then), so a wider flush is acceptable in practice;
            // the transaction keeps the rollback semantics if the persist or
            // FK validation fails partway through.
            $entityManager->wrapInTransaction(static function () use ($entityManager, $userTurn, $assistantTurn): void {
                $entityManager->persist($userTurn);
                $entityManager->persist($assistantTurn);
                $entityManager->flush();
            });
        } catch (\Throwable $throwable) {
            // Proxy-load failures surface here at `flush()` time, not at `getReference()`;
            // a missing trip yields a foreign-key violation that we log and swallow so the
            // Redis sliding-window context (already updated) remains authoritative for the
            // current request.
            $this->logger->warning('Failed to persist trip chat history to PostgreSQL.', [
                'tripId' => $tripId,
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function buildUserMessage(TripChatRequest $request): string
    {
        $currentStage = $request->context?->currentStage;
        $contextLine = \sprintf(
            "[contexte] currentStage=%s\n",
            null === $currentStage ? 'null' : (string) $currentStage,
        );

        return $contextLine.$request->message;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractText(array $response): ?string
    {
        if (isset($response['message']) && \is_array($response['message'])) {
            $content = $response['message']['content'] ?? null;
            if (\is_string($content)) {
                return $content;
            }
        }

        if (isset($response['response']) && \is_string($response['response'])) {
            return $response['response'];
        }

        return null;
    }
}
