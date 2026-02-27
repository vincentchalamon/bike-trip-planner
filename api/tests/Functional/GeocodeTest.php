<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GeocodeTest extends ApiTestCase
{
    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
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

        $client = self::createClient();
        $this->mockNominatimClient($nominatimPayload);

        $response = $client->request('GET', '/geocode/search?q=Lyon&limit=5');

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
        $response = self::createClient()->request('GET', '/geocode/search');

        $this->assertResponseStatusCodeSame(400);

        $data = $response->toArray(false);
        $this->assertSame('Missing required parameter: q', $data['error']);
    }

    #[Test]
    public function searchRejectsEmptyQuery(): void
    {
        $response = self::createClient()->request('GET', '/geocode/search?q=');

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

        $client = self::createClient();
        $this->mockNominatimClient($nominatimPayload);

        $response = $client->request('GET', '/geocode/search?q=Lyon&limit=1');

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

        $client = self::createClient();
        $this->mockNominatimClient($nominatimPayload);

        $response = $client->request('GET', '/geocode/reverse?lat=45.764&lon=4.8343');

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
        $response = self::createClient()->request('GET', '/geocode/reverse?lon=4.8343');

        $this->assertResponseStatusCodeSame(400);

        $data = $response->toArray(false);
        $this->assertSame('Missing required parameters: lat, lon', $data['error']);
    }

    #[Test]
    public function reverseRejectsMissingLon(): void
    {
        $response = self::createClient()->request('GET', '/geocode/reverse?lat=45.764');

        $this->assertResponseStatusCodeSame(400);

        $data = $response->toArray(false);
        $this->assertSame('Missing required parameters: lat, lon', $data['error']);
    }

    #[Test]
    public function reverseRejectsMissingBothParams(): void
    {
        $response = self::createClient()->request('GET', '/geocode/reverse');

        $this->assertResponseStatusCodeSame(400);

        $data = $response->toArray(false);
        $this->assertSame('Missing required parameters: lat, lon', $data['error']);
    }

    #[Test]
    public function reverseReturnsEmptyOnNominatimError(): void
    {
        $client = self::createClient();
        $this->mockNominatimClient(['error' => 'Unable to geocode']);

        $response = $client->request('GET', '/geocode/reverse?lat=0&lon=0');

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray();
        $this->assertSame([], $data['results']);
    }

    #[Test]
    public function searchReturns502OnNominatimFailure(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 500]);
        $mockClient = new MockHttpClient($mockResponse);

        $client = self::createClient();
        self::getContainer()->set('nominatim.client', $mockClient);

        $response = $client->request('GET', '/geocode/search?q=Lyon');

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
