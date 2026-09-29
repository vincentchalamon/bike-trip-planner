<?php

declare(strict_types=1);

namespace App\Tests\Functional\AccessRequest;

use App\Tests\ApiTestCase;
use App\Entity\AccessRequest;
use App\Enum\AccessRequestStatus;
use App\Service\AccessRequestHmacService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AccessRequestVerifyTest extends ApiTestCase
{
    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    /** @param non-empty-string $email */
    private function persistRequest(string $email, bool $verified = false): AccessRequest
    {
        $accessRequest = new AccessRequest($email, '127.0.0.1');
        if ($verified) {
            $accessRequest->verify();
        }

        $em = $this->getEntityManager();
        $em->persist($accessRequest);
        $em->flush();

        return $accessRequest;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function verify(array $body): void
    {
        self::createClient()->request('POST', '/access-requests/verify', ['json' => $body]);
    }

    private function statusOf(string $email): AccessRequestStatus
    {
        $em = $this->getEntityManager();
        $em->clear();

        $accessRequest = $em->getRepository(AccessRequest::class)->findOneBy(['email' => $email]);
        $this->assertInstanceOf(AccessRequest::class, $accessRequest);

        return $accessRequest->getStatus();
    }

    /** @return array{id: string, expires: int, signature: string} */
    private function payloadFor(AccessRequest $accessRequest): array
    {
        /** @var AccessRequestHmacService $hmac */
        $hmac = self::getContainer()->get(AccessRequestHmacService::class);

        return $hmac->generatePayload($accessRequest->getId()->toRfc4122());
    }

    #[Test]
    public function aValidSignatureVerifiesTheRequest(): void
    {
        $accessRequest = $this->persistRequest('toverify@example.com');

        $this->verify($this->payloadFor($accessRequest));

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(AccessRequestStatus::VERIFIED, $this->statusOf('toverify@example.com'));
    }

    #[Test]
    public function anInvalidSignatureChangesNothing(): void
    {
        $accessRequest = $this->persistRequest('invalid@example.com');

        $this->verify(['id' => $accessRequest->getId()->toRfc4122(), 'expires' => 9999999999, 'signature' => 'badsignature']);

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(AccessRequestStatus::PENDING_VERIFICATION, $this->statusOf('invalid@example.com'));
    }

    #[Test]
    public function anExpiredSignatureChangesNothing(): void
    {
        $accessRequest = $this->persistRequest('expired@example.com');
        $id = $accessRequest->getId()->toRfc4122();
        $expiredTs = new \DateTimeImmutable('-1 day')->getTimestamp();
        $secret = (string) getenv('ACCESS_REQUEST_HMAC_SECRET');
        \assert('' !== $secret);

        $this->verify(['id' => $id, 'expires' => $expiredTs, 'signature' => hash_hmac('sha256', $id.'|'.$expiredTs, $secret)]);

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(AccessRequestStatus::PENDING_VERIFICATION, $this->statusOf('expired@example.com'));
    }

    /**
     * The id is what the signature covers: a valid signature for one request
     * cannot verify another.
     */
    #[Test]
    public function aSignatureForAnotherRequestChangesNothing(): void
    {
        $signed = $this->persistRequest('signed@example.com');
        $other = $this->persistRequest('other@example.com');

        $this->verify(['id' => $other->getId()->toRfc4122()] + $this->payloadFor($signed));

        $this->assertResponseStatusCodeSame(204);
        $this->assertSame(AccessRequestStatus::PENDING_VERIFICATION, $this->statusOf('other@example.com'));
    }

    #[Test]
    public function anAlreadyVerifiedRequestAnswersTheSame(): void
    {
        $accessRequest = $this->persistRequest('alreadyverified@example.com', verified: true);

        $this->verify($this->payloadFor($accessRequest));

        $this->assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function aSignedIdNoLongerInTheTableAnswersTheSame(): void
    {
        /** @var AccessRequestHmacService $hmac */
        $hmac = self::getContainer()->get(AccessRequestHmacService::class);

        $this->verify($hmac->generatePayload(Uuid::v7()->toRfc4122()));

        $this->assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function missingParametersAnswerTheSame(): void
    {
        $this->verify([]);

        $this->assertResponseStatusCodeSame(204);
    }

    #[Test]
    public function theOldQueryStringLinkIsGone(): void
    {
        self::createClient()->request('GET', '/access-requests/verify?email=a@example.com&expires=1&signature=x');

        $this->assertResponseStatusCodeSame(405);
    }
}
