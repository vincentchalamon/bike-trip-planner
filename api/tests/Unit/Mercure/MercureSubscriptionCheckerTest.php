<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mercure;

use App\Mercure\MercureSubscriptionChecker;
use App\Mercure\MercureTokenIssuer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class MercureSubscriptionCheckerTest extends TestCase
{
    private const string TRIP_ID = '0192a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string MERCURE_URL = 'http://php/.well-known/mercure';

    #[Test]
    public function reportsAnActiveSubscriberAndQueriesTheTopicEndpointWithABearer(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse((string) json_encode(['subscriptions' => [['active' => false], ['active' => true]]]));
        });

        self::assertTrue($this->checker($client)->hasActiveSubscriber(self::TRIP_ID));

        self::assertNotNull($captured);
        self::assertSame('GET', $captured['method']);
        self::assertSame(
            self::MERCURE_URL.'/subscriptions/'.rawurlencode('/trips/'.self::TRIP_ID),
            $captured['url'],
        );
        $authHeaders = array_filter(
            $captured['options']['headers'],
            static fn (string $header): bool => str_starts_with($header, 'Authorization: Bearer '),
        );
        self::assertCount(1, $authHeaders, 'the subscription request must carry a bearer JWT');
    }

    #[Test]
    public function reportsNoSubscriberWhenTheListIsEmpty(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode(['subscriptions' => []])));

        self::assertFalse($this->checker($client)->hasActiveSubscriber(self::TRIP_ID));
    }

    #[Test]
    public function failsOpenOnAHubError(): void
    {
        $client = new MockHttpClient(new MockResponse('nope', ['http_code' => 503]));

        self::assertFalse($this->checker($client)->hasActiveSubscriber(self::TRIP_ID));
    }

    #[Test]
    public function failsOpenOnATransportError(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new class () extends \RuntimeException implements TransportExceptionInterface {};
        });

        self::assertFalse($this->checker($client)->hasActiveSubscriber(self::TRIP_ID));
    }

    private function checker(MockHttpClient $client): MercureSubscriptionChecker
    {
        return new MercureSubscriptionChecker($client, $this->tokenIssuer(), self::MERCURE_URL, new NullLogger());
    }

    private function tokenIssuer(): MercureTokenIssuer
    {
        return new MercureTokenIssuer('a-test-mercure-secret-that-is-long-enough');
    }
}
