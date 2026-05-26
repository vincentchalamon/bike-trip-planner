<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Message\FetchAndParseRoute;
use App\Messenger\CorrelationIdStamp;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * End-to-end verification of the correlation-ID pipeline introduced in
 * issue #485:
 *
 * 1. inbound `X-Request-Id` is preserved on the response;
 * 2. requests without the header receive a freshly minted UUID v7;
 * 3. messages dispatched by the request carry a {@see CorrelationIdStamp}
 *    so workers can rejoin the original trace.
 */
final class RequestIdPropagationTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    private string $jwtToken;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    #[\Override]
    protected function setUp(): void
    {
        ['token' => $this->jwtToken] = $this->createTestUserWithJwt(\sprintf('%s@test.com', bin2hex(random_bytes(8))));
    }

    #[Test]
    public function preservesInboundRequestIdOnResponse(): void
    {
        $expected = '0193e7c1-1234-7000-9000-abcdef000001';

        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(
                [
                    'Content-Type' => 'application/ld+json',
                    'X-Request-Id' => $expected,
                ],
                $this->authHeader($this->jwtToken),
            ),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/tour/987654321',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $headers = $response->getHeaders(false);
        $this->assertArrayHasKey('x-request-id', $headers);
        $this->assertSame([$expected], $headers['x-request-id']);
    }

    #[Test]
    public function mintsRequestIdWhenAbsent(): void
    {
        $response = self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(
                ['Content-Type' => 'application/ld+json'],
                $this->authHeader($this->jwtToken),
            ),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/tour/111222333',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $headers = $response->getHeaders(false);
        $this->assertArrayHasKey('x-request-id', $headers);
        $value = $headers['x-request-id'][0] ?? null;
        $this->assertIsString($value);
        $this->assertNotSame('', $value);
        // UUID-shape sanity check (RFC 4122 canonical form).
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value,
        );
    }

    #[Test]
    public function dispatchedMessengerEnvelopeCarriesCorrelationStamp(): void
    {
        $expected = '0193e7c1-1234-7000-9000-abcdef000099';

        self::createClient()->request('POST', '/trips', [
            'headers' => array_merge(
                [
                    'Content-Type' => 'application/ld+json',
                    'X-Request-Id' => $expected,
                ],
                $this->authHeader($this->jwtToken),
            ),
            'json' => [
                'sourceUrl' => 'https://www.komoot.com/tour/444555666',
            ],
        ]);

        $this->assertResponseStatusCodeSame(202);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        $this->assertNotEmpty($sent, 'Expected at least one async message dispatched.');

        $envelope = $sent[0];
        $this->assertInstanceOf(FetchAndParseRoute::class, $envelope->getMessage());

        $stamp = $envelope->last(CorrelationIdStamp::class);
        $this->assertInstanceOf(CorrelationIdStamp::class, $stamp);
        $this->assertSame($expected, $stamp->correlationId);
    }
}
