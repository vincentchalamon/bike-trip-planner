<?php

declare(strict_types=1);

namespace App\Tests\Unit\Push;

use App\Push\FcmClient;
use App\Push\FcmCredentials;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
    public function doesNotPruneOnAGeneric404WithoutUnregistered(): void
    {
        // A wrong project_id / disabled API / revoked key surfaces as the same
        // generic 404 NOT_FOUND wrapper. Pruning on it would wipe every user's
        // tokens on a config error, so only the nested UNREGISTERED errorCode
        // may prune — this response must leave the token in place.
        $oauthClient = new MockHttpClient(new MockResponse((string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])));
        $fcmClient = new MockHttpClient([
            new MockResponse((string) json_encode(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.']]), ['http_code' => 404]),
        ]);

        $invalid = $this->client($oauthClient, $fcmClient)->send(['some-token'], 'T', 'B');

        self::assertSame([], $invalid);
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

    private function client(MockHttpClient $oauthClient, MockHttpClient $fcmClient): FcmClient
    {
        return new FcmClient($oauthClient, $fcmClient, $this->credentials(), new NullLogger());
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
