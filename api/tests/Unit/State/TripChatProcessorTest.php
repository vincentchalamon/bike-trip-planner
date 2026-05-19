<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\ApiResource\TripChatRequest;
use App\Entity\User;
use App\Llm\ChatActionInterpreter;
use App\Llm\ChatHistoryStore;
use App\Llm\LlmClientInterface;
use App\Llm\SystemPromptLoader;
use App\State\TripChatProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for the rate-limit branch of {@see TripChatProcessor}.
 *
 * The functional layer cannot exercise the cross-request limiter state because
 * the test cache pool (array adapter) is reset between kernel requests. This
 * test wires the processor against an in-memory rate limiter sized so the
 * second `process()` call exhausts the quota and triggers the 429 branch.
 */
#[AllowMockObjectsWithoutExpectations]
final class TripChatProcessorTest extends TestCase
{
    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000099';

    private string $promptFixtureDir = '';

    #[\Override]
    protected function tearDown(): void
    {
        if ('' !== $this->promptFixtureDir && is_dir($this->promptFixtureDir)) {
            @unlink($this->promptFixtureDir.\DIRECTORY_SEPARATOR.'dialogue.txt');
            @rmdir($this->promptFixtureDir);
        }
    }

    #[Test]
    public function processThrows429WhenRateLimitExceeded(): void
    {
        $processor = $this->newProcessor(limit: 1);
        $request = new TripChatRequest('Bonjour');
        $operation = new Post();

        // First call consumes the only token.
        $processor->process($request, $operation, ['id' => self::TRIP_ID]);

        // Second call must hit the limiter and trip the 429 branch.
        $this->expectException(TooManyRequestsHttpException::class);
        $processor->process($request, $operation, ['id' => self::TRIP_ID]);
    }

    private function newProcessor(int $limit): TripChatProcessor
    {
        $llmClient = $this->createStub(LlmClientInterface::class);
        $llmClient->method('isEnabled')->willReturn(true);
        $llmClient->method('chat')->willReturn([
            'message' => [
                'role' => 'assistant',
                'content' => json_encode([
                    'action' => 'info',
                    'params' => [],
                    'response' => 'OK',
                ], \JSON_THROW_ON_ERROR),
            ],
        ]);

        $user = new User('chat@example.com', Uuid::v7());
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $factory = new RateLimiterFactory(
            ['id' => 'trip_chat', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return new TripChatProcessor(
            llmClient: $llmClient,
            promptLoader: new SystemPromptLoader($this->createPromptFixtureDir()),
            interpreter: new ChatActionInterpreter(new NullLogger()),
            historyStore: new ChatHistoryStore(new ArrayAdapter()),
            security: $security,
            logger: new NullLogger(),
            tripChatLimiter: $factory,
        );
    }

    private function createPromptFixtureDir(): string
    {
        $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'trip-chat-prompts-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o775, true);
        file_put_contents($dir.\DIRECTORY_SEPARATOR.'dialogue.txt', 'SYSTEM PROMPT');
        $this->promptFixtureDir = $dir;

        return $dir;
    }
}
