<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\AiChatRequest;
use App\ApiResource\AiChatResponse;
use App\ApiResource\Model\AiChatMessage;
use App\Entity\User;
use App\Llm\BriefChatInterpreter;
use App\Llm\Exception\AiFailureReason;
use App\Llm\Exception\AiUnavailableException;
use App\Llm\LlmResponseParser;
use App\Llm\ResolvedLlmClient;
use App\Llm\SystemPromptLoader;
use App\Llm\UserLlmResolverInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Handles `POST /trips/ai-chat` (ADR-045): the stateless trip-brief chat.
 *
 * The client carries the whole conversation on every turn; this processor stores
 * no server-side state. Pipeline:
 * 1. Resolve the user's provider; return 422 `{ error: "ai_not_configured" }`
 *    when none is configured (a discrete signal so the UI can surface the
 *    provider-setup CTA — diverging deliberately from the in-chat hint of the
 *    loaded-trip chat, ADR-042).
 * 2. Enforce a per-user rate limit.
 * 3. Validate strictly that every role is `user` or `assistant` and that the
 *    server-side payload ceilings (message count, per-message length) hold —
 *    rejecting with a 422 BEFORE any LLM call.
 * 4. Build the `brief-chat` system prompt (in the rider's locale), call
 *    `chat()`, and parse the reply leniently into `{reply, readyToGenerate,
 *    collected}` with a safe fallback on parse failure.
 *
 * The endpoint never routes: launching the computation reuses
 * `POST /trips/ai-generate`.
 *
 * @implements ProcessorInterface<AiChatRequest, AiChatResponse|JsonResponse>
 */
final readonly class TripAiChatProcessor implements ProcessorInterface
{
    public const string PROMPT_NAME = 'brief-chat';

    public function __construct(
        private UserLlmResolverInterface $clientFactory,
        private SystemPromptLoader $promptLoader,
        private BriefChatInterpreter $interpreter,
        private LlmResponseParser $responseParser,
        private RequestStack $requestStack,
        private Security $security,
        private LoggerInterface $logger,
        #[Autowire(service: 'limiter.ai_chat')]
        private RateLimiterFactory $aiChatLimiter,
    ) {
    }

    /**
     * @param AiChatRequest $data
     * @param Post          $operation
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AiChatResponse|JsonResponse
    {
        \assert($data instanceof AiChatRequest);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new HttpException(401, 'Authentication required.');
        }

        // Resolve the provider before consuming a rate-limit token. A discrete
        // 422 body lets the UI surface the provider-setup CTA before mounting
        // the chat (diverges from the loaded-trip chat's in-chat hint, ADR-045).
        $resolved = $this->clientFactory->forUser($user);
        if (!$resolved instanceof ResolvedLlmClient) {
            return new JsonResponse(['error' => 'ai_not_configured'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $limiter = $this->aiChatLimiter->create($user->getId()->toRfc4122());
        $rateLimit = $limiter->consume();
        if (!$rateLimit->isAccepted()) {
            $retryAfter = $rateLimit->getRetryAfter();
            $secondsUntilRetry = max(0, $retryAfter->getTimestamp() - new \DateTimeImmutable()->getTimestamp());

            throw new TooManyRequestsHttpException(retryAfter: $secondsUntilRetry, message: 'Chat rate limit reached. Please wait a moment before retrying.');
        }

        $messages = $this->validateAndBuildMessages($data->messages);

        $locale = $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['en', 'fr']) ?? 'en';
        $systemPrompt = $this->promptLoader->load(self::PROMPT_NAME, ['language' => $locale]);

        try {
            $response = $resolved->client->chat(
                model: $resolved->provider->chatModel(),
                messages: $messages,
                systemPrompt: $systemPrompt,
            );
        } catch (AiUnavailableException $aiUnavailableException) {
            $reason = $aiUnavailableException->getReason();

            $this->logger->critical('AI provider call failed — ai-chat endpoint degrading.', [
                'reason' => $reason->value,
                'error' => $aiUnavailableException->getMessage(),
            ]);

            // Propagate the classified reason (ADR-042/045) so the UI can show an
            // actionable message instead of a generic "retry": an exhausted quota
            // or a revoked token are not transient, so retrying is misleading.
            [$status, $error] = match ($reason) {
                AiFailureReason::INVALID_TOKEN => [Response::HTTP_UNPROCESSABLE_ENTITY, 'ai_invalid_token'],
                AiFailureReason::QUOTA_EXCEEDED => [Response::HTTP_UNPROCESSABLE_ENTITY, 'ai_quota_exceeded'],
                AiFailureReason::RATE_LIMITED => [Response::HTTP_TOO_MANY_REQUESTS, 'ai_rate_limited'],
                AiFailureReason::UNAVAILABLE => [Response::HTTP_SERVICE_UNAVAILABLE, 'ai_unavailable'],
            };

            $headers = [];
            if (AiFailureReason::RATE_LIMITED === $reason && null !== $aiUnavailableException->getRetryAfter()) {
                $headers['Retry-After'] = (string) $aiUnavailableException->getRetryAfter();
            }

            return new JsonResponse(['error' => $error], $status, $headers);
        }

        $rawContent = null === $response ? '' : ($this->responseParser->extractText($response) ?? '');
        $reply = $this->interpreter->interpret($rawContent);

        return new AiChatResponse(
            reply: $reply->reply,
            readyToGenerate: $reply->readyToGenerate,
            collected: $reply->collected,
        );
    }

    /**
     * Server-side guard rails enforced before any LLM call (ADR-045): strict
     * role validation and the payload ceilings. The validation layer already
     * checks these for HTTP traffic, but re-checking here keeps the processor
     * correct for any direct caller and matches the ADR's "reject before the
     * LLM" contract.
     *
     * @param list<AiChatMessage> $messages
     *
     * @return list<array{role: string, content: string}>
     */
    private function validateAndBuildMessages(array $messages): array
    {
        if ([] === $messages) {
            throw new UnprocessableEntityHttpException('At least one message is required.');
        }

        if (\count($messages) > AiChatRequest::MAX_MESSAGES) {
            throw new UnprocessableEntityHttpException(\sprintf('Too many messages: at most %d are allowed.', AiChatRequest::MAX_MESSAGES));
        }

        $built = [];
        foreach ($messages as $message) {
            if (!\in_array($message->role, AiChatMessage::ALLOWED_ROLES, true)) {
                throw new UnprocessableEntityHttpException(\sprintf('Invalid message role "%s": only "user" and "assistant" are allowed.', $message->role));
            }

            if (mb_strlen($message->content) > AiChatMessage::MAX_CONTENT_LENGTH) {
                throw new UnprocessableEntityHttpException(\sprintf('Message too long: at most %d characters are allowed.', AiChatMessage::MAX_CONTENT_LENGTH));
            }

            $built[] = ['role' => $message->role, 'content' => $message->content];
        }

        return $built;
    }
}
