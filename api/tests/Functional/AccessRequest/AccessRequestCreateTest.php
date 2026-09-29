<?php

declare(strict_types=1);

namespace App\Tests\Functional\AccessRequest;

use App\Tests\ApiTestCase;
use App\Tests\Functional\FailingMailerTrait;
use App\Entity\AccessRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AccessRequestCreateTest extends ApiTestCase
{
    use FailingMailerTrait;
    use MailerAssertionsTrait;

    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    #[Test]
    public function postAccessRequestReturns202(): void
    {
        $response = self::createClient()->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'newuser@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('message', $data);
        $this->assertNotEmpty($data['message']);
    }

    #[Test]
    public function postAccessRequestPersistsRecord(): void
    {
        self::createClient()->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'persisted@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(202);

        $em = $this->getEntityManager();
        $accessRequest = $em->getRepository(AccessRequest::class)->findOneBy(['email' => 'persisted@example.com']);
        $this->assertInstanceOf(AccessRequest::class, $accessRequest);
        $this->assertSame('pending_verification', $accessRequest->getStatus()->value);
    }

    /**
     * The link names the request by id, in the fragment: the address never
     * appears in a URL, and nothing of the link reaches an access log.
     */
    #[Test]
    public function verificationLinkCarriesNoAddressAndNothingTheServerSees(): void
    {
        self::createClient()->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'linked@example.com'],
        ]);

        $accessRequest = $this->getEntityManager()->getRepository(AccessRequest::class)->findOneBy(['email' => 'linked@example.com']);
        $this->assertInstanceOf(AccessRequest::class, $accessRequest);

        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyContains($email, '/access-requests/verify#id='.$accessRequest->getId()->toRfc4122().'&amp;expires=');
        self::assertEmailHtmlBodyNotContains($email, 'linked%40example.com');
        self::assertEmailHtmlBodyNotContains($email, 'verify?');
    }

    #[Test]
    public function postAccessRequestWithExistingUserReturns202Silently(): void
    {
        $em = $this->getEntityManager();
        $user = new User('existing@example.com');
        $em->persist($user);
        $em->flush();

        $response = self::createClient()->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'existing@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('message', $data);

        // Verify no AccessRequest was created
        $accessRequest = $em->getRepository(AccessRequest::class)->findOneBy(['email' => 'existing@example.com']);
        $this->assertNull($accessRequest);
    }

    #[Test]
    public function postAccessRequestWithExistingAccessRequestReturns202Silently(): void
    {
        $em = $this->getEntityManager();
        $accessRequest = new AccessRequest('duplicate@example.com', '127.0.0.1');
        $em->persist($accessRequest);
        $em->flush();

        $response = self::createClient()->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'duplicate@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(202);
        $data = $response->toArray(false);
        $this->assertArrayHasKey('message', $data);
    }

    #[Test]
    public function antiEnumerationResponseIsIdenticalForAllCases(): void
    {
        $em = $this->getEntityManager();
        $user = new User('known@example.com');
        $em->persist($user);
        $em->flush();

        $client = self::createClient();

        $knownResponse = $client->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'known@example.com'],
        ]);
        $knownData = $knownResponse->toArray(false);

        $unknownResponse = $client->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'ghost@example.com'],
        ]);
        $unknownData = $unknownResponse->toArray(false);

        $this->assertSame(202, $knownResponse->getStatusCode());
        $this->assertSame(202, $unknownResponse->getStatusCode());
        $this->assertSame($knownData['message'], $unknownData['message']);
    }

    #[Test]
    public function postAccessRequestWithInvalidEmailReturns422(): void
    {
        self::createClient()->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'not-an-email'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function postAccessRequestWithEmptyEmailReturns422(): void
    {
        self::createClient()->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => ''],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function postAccessRequestWithMissingEmailReturns422(): void
    {
        self::createClient()->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => new \stdClass(),
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    /**
     * The record is removed so the requester can try again, and told to (503),
     * without the SMTP text, which quotes the recipient.
     */
    #[Test]
    public function aMailFailureRemovesTheRecordAndAnswers503WithoutTheSmtpMessage(): void
    {
        $client = self::createClient();
        $this->failTheMailer();
        $response = $client->request('POST', '/access-requests', [
            'headers' => ['Content-Type' => 'application/ld+json'],
            'json' => ['email' => 'rider@example.com'],
        ]);

        $this->assertResponseStatusCodeSame(503);
        $this->assertStringNotContainsString('550', $response->getContent(false));
        $this->assertNull($this->getEntityManager()->getRepository(AccessRequest::class)->findOneBy(['email' => 'rider@example.com']));
    }
}
