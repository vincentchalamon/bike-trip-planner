<?php

declare(strict_types=1);

namespace App\Tests\Unit\Push;

use App\Push\FcmClient;
use App\Push\FcmCredentials;
use App\Push\FcmSendException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FcmClientTest extends TestCase
{
    #[Test]
    public function sendsOneMessagePerTokenWithABearerFromTheTokenExchange(): void
    {
        $sentBodies = [];
        $oauthClient = new MockHttpClient(new MockResponse((string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])));
        $fcmClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$sentBodies): MockResponse {
            self::assertSame('POST', $method);
            self::assertStringContainsString('/v1/projects/demo-project/messages:send', $url);
            self::assertContains('Authorization: Bearer ya29.test', $options['headers']);
            /** @var array{message: array{token: string, notification: array{title: string, body: string}, data: array<string, string>}} $decoded */
            $decoded = json_decode((string) $options['body'], true, 512, \JSON_THROW_ON_ERROR);
            $sentBodies[] = $decoded;

            return new MockResponse('{}');
        });

        $invalid = $this->client($oauthClient, $fcmClient)->send(['tok-a', 'tok-b'], 'Titre', 'Corps', ['category' => 'safety']);

        self::assertSame([], $invalid);
        self::assertCount(2, $sentBodies);
        self::assertSame('tok-a', $sentBodies[0]['message']['token']);
        self::assertSame('Titre', $sentBodies[0]['message']['notification']['title']);
        self::assertSame('safety', $sentBodies[0]['message']['data']['category']);
    }

    #[Test]
    public function returnsTokensFcmReportsAsUnregisteredForPruning(): void
    {
        $oauthClient = new MockHttpClient(new MockResponse((string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])));
        $fcmClient = new MockHttpClient([
            new MockResponse('{}'),
            new MockResponse((string) json_encode(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]), ['http_code' => 404]),
        ]);

        $invalid = $this->client($oauthClient, $fcmClient)->send(['live-token', 'dead-token'], 'T', 'B');

        self::assertSame(['dead-token'], $invalid);
    }

    #[Test]
    public function carriesTheDeadTokensOnTheExceptionWhenABatchAlsoHasARealFailure(): void
    {
        // Mixed batch: one UNREGISTERED (dead) token + one real 500 failure. The
        // real failure rethrows for Messenger retry, but the dead token must still
        // ride out on the exception so the caller prunes it once instead of
        // rediscovering it on every retry (ADR-058).
        $oauthClient = new MockHttpClient(new MockResponse((string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])));
        $fcmClient = new MockHttpClient([
            new MockResponse((string) json_encode(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]), ['http_code' => 404]),
            new MockResponse((string) json_encode(['error' => ['status' => 'INTERNAL', 'message' => 'backend error']]), ['http_code' => 500]),
        ]);

        try {
            $this->client($oauthClient, $fcmClient)->send(['dead-token', 'live-token'], 'T', 'B');
            self::fail('send() must rethrow on a real failure.');
        } catch (FcmSendException $e) {
            self::assertSame(['dead-token'], $e->invalidTokens);
        }
    }

    #[Test]
    public function rethrowsWithoutPruningOnAGeneric404WithoutUnregistered(): void
    {
        // A wrong project_id / disabled API / revoked key surfaces as the same
        // generic 404 NOT_FOUND wrapper. Pruning on it would wipe every user's
        // tokens on a config error, so only the nested UNREGISTERED errorCode
        // may prune. It is a real send failure, so it must rethrow (Messenger
        // retry) and must never prune the token.
        $oauthClient = new MockHttpClient(new MockResponse((string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])));
        $fcmClient = new MockHttpClient([
            new MockResponse((string) json_encode(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.']]), ['http_code' => 404]),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->client($oauthClient, $fcmClient)->send(['some-token'], 'T', 'B');
    }

    #[Test]
    public function rethrowsAndKeepsTheTokenOnAGenericSendFailure(): void
    {
        // A 5xx / bad-request / quota failure is a real incident (ADR-058: never a
        // silent no-op). It must be logged at error and rethrown so Messenger retries
        // the whole message — a lost security alert is worse than a duplicate push.
        // The token must NOT be pruned (nothing is returned when we rethrow).
        $logger = new class () extends AbstractLogger {
            /** @var list<array{0: mixed, 1: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
        $oauthClient = new MockHttpClient(new MockResponse((string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])));
        $fcmClient = new MockHttpClient([
            new MockResponse((string) json_encode(['error' => ['status' => 'INTERNAL', 'message' => 'backend error']]), ['http_code' => 500]),
        ]);

        $thrown = false;
        try {
            $this->client($oauthClient, $fcmClient, $logger)->send(['tok'], 'T', 'B', ['category' => 'safety']);
        } catch (\RuntimeException) {
            $thrown = true;
        }

        self::assertTrue($thrown, 'send() must rethrow so Messenger retries the message.');
        // The error log is emitted before the rethrow, and the token is not pruned.
        self::assertTrue($this->loggedError($logger->records, 'FCM push send failed'));
    }

    #[Test]
    public function rethrowsAndKeepsTheTokenOnATransportError(): void
    {
        // A transport-level failure (timeout, DNS, TLS reset) is caught, logged at
        // error, then rethrown so Messenger retries the message. The token stays.
        $logger = new class () extends AbstractLogger {
            /** @var list<array{0: mixed, 1: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
        $oauthClient = new MockHttpClient(new MockResponse((string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])));
        $fcmClient = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('connection reset');
        });

        $thrown = false;
        try {
            $this->client($oauthClient, $fcmClient, $logger)->send(['tok'], 'T', 'B');
        } catch (\RuntimeException) {
            $thrown = true;
        }

        self::assertTrue($thrown, 'send() must rethrow so Messenger retries the message.');
        self::assertTrue($this->loggedError($logger->records, 'FCM push transport error'));
    }

    /**
     * Prod's monolog `main` handler is fingers_crossed with action_level: error,
     * so a real FCM failure must be logged at error to cross the threshold.
     *
     * @param list<array{0: mixed, 1: string}> $records
     */
    private function loggedError(array $records, string $needle): bool
    {
        foreach ($records as [$level, $message]) {
            if ('error' === $level && str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    #[Test]
    public function fetchesTheAccessTokenOnlyOnceForABatch(): void
    {
        // One queued OAuth response: a second token exchange would throw, proving
        // the access token is reused across the two sends.
        $oauthClient = new MockHttpClient([new MockResponse((string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600]))]);
        $fcmClient = new MockHttpClient([new MockResponse('{}'), new MockResponse('{}')]);

        $invalid = $this->client($oauthClient, $fcmClient)->send(['a', 'b'], 'T', 'B');

        self::assertSame([], $invalid);
    }

    private function client(MockHttpClient $oauthClient, MockHttpClient $fcmClient, ?LoggerInterface $logger = null): FcmClient
    {
        return new FcmClient($oauthClient, $fcmClient, $this->credentials(), $logger ?? new NullLogger());
    }

    private function credentials(): FcmCredentials
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);

        return new FcmCredentials((string) json_encode([
            'type' => 'service_account',
            'project_id' => 'demo-project',
            'client_email' => 'sa@demo-project.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ]));
    }
}
