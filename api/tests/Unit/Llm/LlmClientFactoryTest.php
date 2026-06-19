<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Entity\User;
use App\Llm\AiProvider;
use App\Llm\AiTokenEncryptor;
use App\Llm\LlmClientFactory;
use App\Llm\PlatformLlmClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class LlmClientFactoryTest extends TestCase
{
    private AiTokenEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->encryptor = new AiTokenEncryptor('test-key');
    }

    private function factory(bool $aiEnabled = true): LlmClientFactory
    {
        // MockHttpClient with no queued responses: construction must not hit the wire.
        return new LlmClientFactory(new MockHttpClient(), new MockHttpClient(), new MockHttpClient(), new NullLogger(), $this->encryptor, $aiEnabled);
    }

    /**
     * @return iterable<string, array{AiProvider, string}>
     */
    public static function providers(): iterable
    {
        // OpenAI's bridge validates the key prefix at construction, so use a
        // format-valid fake token per provider.
        yield 'anthropic' => [AiProvider::ANTHROPIC, 'sk-ant-test'];
        yield 'openai' => [AiProvider::OPENAI, 'sk-test'];
        yield 'gemini' => [AiProvider::GEMINI, 'test-key'];
    }

    #[Test]
    #[DataProvider('providers')]
    public function createBuildsAPlatformClientForEachProvider(AiProvider $provider, string $token): void
    {
        self::assertInstanceOf(PlatformLlmClient::class, $this->factory()->create($provider, $token));
    }

    #[Test]
    public function forUserBuildsAClientWhenConfigured(): void
    {
        $user = new User('rider@example.test');
        $user->setAiProvider('anthropic')->setAiToken($this->encryptor->encrypt('sk-ant-secret'));

        $resolved = $this->factory()->forUser($user);

        self::assertNotNull($resolved);
        self::assertInstanceOf(PlatformLlmClient::class, $resolved->client);
        self::assertSame(AiProvider::ANTHROPIC, $resolved->provider);
    }

    #[Test]
    public function forUserReturnsNullWhenTheKillSwitchIsOff(): void
    {
        $user = new User('rider@example.test');
        $user->setAiProvider('anthropic')->setAiToken($this->encryptor->encrypt('sk-ant-secret'));

        self::assertNull($this->factory(aiEnabled: false)->forUser($user));
    }

    #[Test]
    public function forUserReturnsNullWhenNotConfigured(): void
    {
        self::assertNull($this->factory()->forUser(new User('rider@example.test')));
    }

    #[Test]
    public function forUserReturnsNullWhenTokenMissing(): void
    {
        $user = new User('rider@example.test')->setAiProvider('anthropic');

        self::assertNull($this->factory()->forUser($user));
    }

    #[Test]
    public function forUserReturnsNullForAnUnknownProvider(): void
    {
        $user = new User('rider@example.test');
        $user->setAiProvider('ollama')->setAiToken($this->encryptor->encrypt('x'));

        self::assertNull($this->factory()->forUser($user));
    }

    #[Test]
    public function forUserReturnsNullWhenTheTokenCannotBeDecrypted(): void
    {
        // e.g. encryption key rotated since the token was stored: a real blob
        // built under a different key, so decrypt() walks nonce extraction + MAC
        // failure rather than the malformed-base64 early return.
        $user = new User('rider@example.test');
        $user->setAiProvider('anthropic')->setAiToken(new AiTokenEncryptor('different-key')->encrypt('sk-ant-secret'));

        self::assertNull($this->factory()->forUser($user));
    }
}
