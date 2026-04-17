<?php

declare(strict_types=1);

namespace App\Tests\Functional\AccessRequest;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\AccessRequest;
use App\Enum\AccessRequestStatus;
use App\Service\AccessRequestHmacService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AccessRequestVerifyTest extends ApiTestCase
{
    use Factories;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function getHmacService(): AccessRequestHmacService
    {
        return self::getContainer()->get(AccessRequestHmacService::class);
    }

    private function buildVerifyUrl(string $email): string
    {
        $payload = $this->getHmacService()->generatePayload($email);

        return \sprintf(
            '/access-requests/verify?email=%s&expires=%d&signature=%s',
            urlencode($payload['email']),
            $payload['expires'],
            $payload['signature'],
        );
    }

    #[Test]
    public function verifyValidSignatureRedirects(): void
    {
        $em = $this->getEntityManager();
        $accessRequest = new AccessRequest('verify@example.com', '127.0.0.1');
        $em->persist($accessRequest);
        $em->flush();

        $url = $this->buildVerifyUrl('verify@example.com');

        $response = self::createClient(['followRedirects' => false])->request('GET', $url);

        $this->assertResponseStatusCodeSame(302);
        $location = $response->getHeaders(false)['location'][0] ?? '';
        $this->assertStringContainsString('access=confirmed', $location);
    }

    #[Test]
    public function verifyValidSignatureUpdatesStatus(): void
    {
        $em = $this->getEntityManager();
        $accessRequest = new AccessRequest('toverify@example.com', '127.0.0.1');
        $em->persist($accessRequest);
        $em->flush();

        $url = $this->buildVerifyUrl('toverify@example.com');

        self::createClient()->request('GET', $url);

        $em->clear();
        $updated = $em->getRepository(AccessRequest::class)->findOneBy(['email' => 'toverify@example.com']);
        $this->assertInstanceOf(AccessRequest::class, $updated);
        $this->assertSame(AccessRequestStatus::VERIFIED, $updated->getStatus());
        $this->assertNotNull($updated->getVerifiedAt());
    }

    #[Test]
    public function verifyInvalidSignatureRedirectsWithGenericMessage(): void
    {
        $em = $this->getEntityManager();
        $accessRequest = new AccessRequest('invalid@example.com', '127.0.0.1');
        $em->persist($accessRequest);
        $em->flush();

        $response = self::createClient(['followRedirects' => false])->request(
            'GET',
            '/access-requests/verify?email=invalid@example.com&expires=9999999999&signature=badsignature',
        );

        $this->assertResponseStatusCodeSame(302);
        $location = $response->getHeaders(false)['location'][0] ?? '';
        $this->assertStringContainsString('access=confirmed', $location);

        // Status must not have changed
        $em->clear();
        $unchanged = $em->getRepository(AccessRequest::class)->findOneBy(['email' => 'invalid@example.com']);
        $this->assertInstanceOf(AccessRequest::class, $unchanged);
        $this->assertSame(AccessRequestStatus::PENDING_VERIFICATION, $unchanged->getStatus());
    }

    #[Test]
    public function verifyExpiredSignatureRedirectsWithGenericMessage(): void
    {
        $em = $this->getEntityManager();
        $accessRequest = new AccessRequest('expired@example.com', '127.0.0.1');
        $em->persist($accessRequest);
        $em->flush();

        // Build an expired signature manually
        $expiredTs = new \DateTimeImmutable('-1 day')->getTimestamp();
        $secret = self::getContainer()->getParameter('kernel.secret');
        \assert(\is_string($secret));
        $expiredSignature = hash_hmac('sha256', 'expired@example.com|'.$expiredTs, $secret);

        $response = self::createClient(['followRedirects' => false])->request(
            'GET',
            \sprintf(
                '/access-requests/verify?email=%s&expires=%d&signature=%s',
                urlencode('expired@example.com'),
                $expiredTs,
                $expiredSignature,
            ),
        );

        $this->assertResponseStatusCodeSame(302);
        $location = $response->getHeaders(false)['location'][0] ?? '';
        $this->assertStringContainsString('access=confirmed', $location);

        // Status must not have changed
        $em->clear();
        $unchanged = $em->getRepository(AccessRequest::class)->findOneBy(['email' => 'expired@example.com']);
        $this->assertInstanceOf(AccessRequest::class, $unchanged);
        $this->assertSame(AccessRequestStatus::PENDING_VERIFICATION, $unchanged->getStatus());
    }

    #[Test]
    public function verifyAlreadyVerifiedRedirectsSilently(): void
    {
        $em = $this->getEntityManager();
        $accessRequest = new AccessRequest('alreadyverified@example.com', '127.0.0.1');
        $accessRequest->verify();

        $em->persist($accessRequest);
        $em->flush();

        $url = $this->buildVerifyUrl('alreadyverified@example.com');

        $response = self::createClient(['followRedirects' => false])->request('GET', $url);

        $this->assertResponseStatusCodeSame(302);
        $location = $response->getHeaders(false)['location'][0] ?? '';
        $this->assertStringContainsString('access=confirmed', $location);
    }

    #[Test]
    public function verifyMissingParametersRedirectsWithGenericMessage(): void
    {
        $response = self::createClient(['followRedirects' => false])->request(
            'GET',
            '/access-requests/verify',
        );

        $this->assertResponseStatusCodeSame(302);
        $location = $response->getHeaders(false)['location'][0] ?? '';
        $this->assertStringContainsString('access=confirmed', $location);
    }

    #[Test]
    public function verifyValidSignatureWithNoExistingRecordCreatesAndVerifies(): void
    {
        $em = $this->getEntityManager();

        // No AccessRequest record in DB before verification (edge case: email sent but persist failed)
        $url = $this->buildVerifyUrl('noexist@example.com');

        $response = self::createClient(['followRedirects' => false])->request('GET', $url);

        $this->assertResponseStatusCodeSame(302);
        $location = $response->getHeaders(false)['location'][0] ?? '';
        $this->assertStringContainsString('access=confirmed', $location);

        // A new verified AccessRequest should have been created
        $em->clear();
        $created = $em->getRepository(AccessRequest::class)->findOneBy(['email' => 'noexist@example.com']);
        $this->assertInstanceOf(AccessRequest::class, $created);
        $this->assertSame(AccessRequestStatus::VERIFIED, $created->getStatus());
        $this->assertNotNull($created->getVerifiedAt());
    }
}
