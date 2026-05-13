<?php

declare(strict_types=1);

namespace App\State;

use App\ApiResource\TripRequest;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripChatRequest;
use App\ApiResource\TripChatResponse;
use App\Entity\User;
use App\Llm\ChatActionInterpreter;
use App\Llm\ChatHistoryStore;
use App\Llm\Dto\ChatAction;
use App\Llm\Exception\OllamaUnavailableException;
use App\Llm\LlmClientInterface;
use App\Llm\SystemPromptLoader;
use App\Repository\TripRequestRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
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
     * The actual Messenger dispatch is intentionally deferred to a follow-up
     * issue (#311): the chat endpoint signals the intent via `dispatched=true`
     * so the frontend can show a "computing" state, while the message routing
     * itself remains a single point to extend.
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
        #[Autowire(service: 'limiter.trip_chat')]
        private RateLimiterFactory $tripChatLimiter,
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

        $limiter = $this->tripChatLimiter->create($userId);
        if (!$limiter->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(message: 'Chat rate limit reached. Please wait a moment before retrying.');
        }

        $request = $this->tripStateManager->getRequest($tripId);
        if (!$request instanceof TripRequest) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found or has expired.', $tripId));
        }

        if (!$this->llmClient->isEnabled()) {
            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'AI assistant is currently disabled.');
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
            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'AI assistant is currently disabled.');
        }

        $rawContent = $this->extractText($response);
        if (null === $rawContent) {
            $this->logger->warning('Ollama chat response missing message content.', ['tripId' => $tripId]);

            throw new ServiceUnavailableHttpException(retryAfter: null, message: 'AI assistant returned an invalid response. Please retry.');
        }

        $action = $this->interpreter->interpret($rawContent);

        $this->historyStore->append($tripId, $userId, 'user', $userMessage);
        $this->historyStore->append($tripId, $userId, 'assistant', $rawContent);

        $dispatched = \in_array($action->action, self::RECOMPUTE_ACTIONS, true);

        if ($dispatched) {
            $this->logger->info('Chat action ready for recomputation.', [
                'tripId' => $tripId,
                'action' => $action->action,
                'params' => $action->params,
            ]);
        }

        return new TripChatResponse(
            tripId: $tripId,
            action: $action->action,
            params: $action->params,
            response: $action->response,
            dispatched: $dispatched,
        );
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
