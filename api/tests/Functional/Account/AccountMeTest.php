<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AccountMeTest extends ApiTestCase
{
    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    #[Test]
    public function meReturnsTheCurrentUserProfile(): void
    {
        $jwt = $this->createAuthenticatedUser('me@example.com', ['locale' => 'en'])['jwt'];

        $response = self::createClient()->request('GET', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$jwt],
        ]);

        $this->assertResponseIsSuccessful();

        $data = $response->toArray();

        $this->assertSame('me@example.com', $data['email']);
        $this->assertSame('en', $data['locale']);
        $this->assertArrayHasKey('userId', $data);
        $this->assertNotSame('', $data['userId']);
    }

    #[Test]
    public function meWithoutAuthenticationReturns401(): void
    {
        self::createClient()->request('GET', '/users/me');

        $this->assertResponseStatusCodeSame(401);
    }
}
