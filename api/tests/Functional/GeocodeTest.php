<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeocodeTest extends ApiTestCase
{
    use JwtAuthTestTrait;

    private Client $client;

    private string $jwtToken;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        ['token' => $this->jwtToken] = $this->createTestUserWithJwt(\sprintf('geocode-%s@test.com', bin2hex(random_bytes(4))));
    }

    #[Test]
    public function searchReturnsResults(): void
    {
        $nominatimPayload = [
            [
                'name' => 'Lyon',
                'display_name' => 'Lyon, Métropole de Lyon, Rhône, France',
                'lat' => '45.7578137',
                'lon' => '4.8320114',
                'type' => 'city',
                'addresstype' => 'city',
            ],
            [
                'name' => 'Lyon',
                'display_name' => 'Lyon County, Kansas, United States',
                'lat' => '38.4558',
                'lon' => '-98.2009',
                'type' => 'administrative',
                'addresstype' => 'county',
            ],
        ];

        $this->mockNominatimClient($nominatimPayload);

        $response = $this->client->request('GET', '/geocode/search?q=Lyon&limit=5', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(2, $data['results']);

        $first = $data['results'][0];
        $this->assertSame('Lyon', $first['name']);
        $this->assertSame('Lyon, Métropole de Lyon, Rhône, France', $first['displayName']);
        $this->assertEqualsWithDelta(45.7578, $first['lat'], 0.001);
        $this->assertEqualsWithDelta(4.8320, $first['lon'], 0.001);
        $this->assertSame('city', $first['type']);
    }

    #[Test]
    public function searchRejectsMissingQuery(): void
    {
        $response = $this->client->request('GET', '/geocode/search', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(400);

        $data = $response->toArray(false);
        $this->assertSame('Missing required parameter: q', $data['error']);
    }

    #[Test]
    public function searchRejectsEmptyQuery(): void
    {
        $response = $this->client->request('GET', '/geocode/search?q=', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(400);

        $data = $response->toArray(false);
        $this->assertSame('Missing required parameter: q', $data['error']);
    }

    #[Test]
    public function searchRespectsLimitParameter(): void
    {
        $nominatimPayload = [
            [
                'name' => 'Lyon',
                'display_name' => 'Lyon, France',
                'lat' => '45.7578137',
                'lon' => '4.8320114',
                'type' => 'city',
                'addresstype' => 'city',
            ],
        ];

        $this->mockNominatimClient($nominatimPayload);

        $response = $this->client->request('GET', '/geocode/search?q=Lyon&limit=1', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray();
        $this->assertCount(1, $data['results']);
    }

    #[Test]
    public function reverseReturnsResult(): void
    {
        $nominatimPayload = [
            'name' => 'Rue de la République',
            'display_name' => 'Rue de la République, Lyon 1er Arrondissement, Lyon, France',
            'lat' => '45.764043',
            'lon' => '4.834277',
            'type' => 'road',
            'addresstype' => 'road',
        ];

        $this->mockNominatimClient($nominatimPayload);

        $response = $this->client->request('GET', '/geocode/reverse?lat=45.764&lon=4.8343', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(1, $data['results']);

        $result = $data['results'][0];
        $this->assertSame('Rue de la République', $result['name']);
        $this->assertEqualsWithDelta(45.764, $result['lat'], 0.001);
        $this->assertSame('road', $result['type']);
    }

    #[Test]
    public function reverseRejectsMissingLat(): void
    {
        $response = $this->client->request('GET', '/geocode/reverse?lon=4.8343', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(400);

        $data = $response->toArray(false);
        $this->assertSame('Missing required parameters: lat, lon', $data['error']);
    }

    #[Test]
    public function reverseRejectsMissingLon(): void
    {
        $response = $this->client->request('GET', '/geocode/reverse?lat=45.764', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(400);

        $data = $response->toArray(false);
        $this->assertSame('Missing required parameters: lat, lon', $data['error']);
    }

    #[Test]
    public function reverseRejectsMissingBothParams(): void
    {
        $response = $this->client->request('GET', '/geocode/reverse', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(400);

        $data = $response->toArray(false);
        $this->assertSame('Missing required parameters: lat, lon', $data['error']);
    }

    #[Test]
    public function reverseReturnsEmptyOnNominatimError(): void
    {
        $this->mockNominatimClient(['error' => 'Unable to geocode']);

        $response = $this->client->request('GET', '/geocode/reverse?lat=0&lon=0', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray();
        $this->assertSame([], $data['results']);
    }

    #[Test]
    public function searchReturns502OnNominatimFailure(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 500]);
        $mockClient = new MockHttpClient($mockResponse);

        self::getContainer()->set('nominatim.client', $mockClient);

        $response = $this->client->request('GET', '/geocode/search?q=Lyon', [
            'headers' => $this->authHeader($this->jwtToken),
        ]);

        $this->assertResponseStatusCodeSame(502);

        $data = $response->toArray(false);
        $this->assertSame('Geocoding service unavailable', $data['error']);
    }

    /**
     * @param array<string|int, mixed> $payload
     */
    private function mockNominatimClient(array $payload): void
    {
        $mockResponse = new MockResponse(
            json_encode($payload, \JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
        $mockClient = new MockHttpClient($mockResponse);

        self::getContainer()->set('nominatim.client', $mockClient);
    }
}
