<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * `PATCH /users/me` — the write ADR-063 had to open.
 *
 * The trip locale now comes from `User::$locale` rather than `Accept-Language`, so
 * that it survives a client that sends no such header (an agent, a cron, the mobile
 * app). That only works if the preference is reachable: before this operation it was
 * writable solely by `bin/console app:create-user --locale`, so every account created
 * with the default would have produced French trips forever.
 */
#[ResetDatabase]
final class AccountUpdateTest extends ApiTestCase
{
    #[\Override]
    protected static ?bool $alwaysBootKernel = false;

    /**
     * @return array<string, string>
     */
    private function patchHeaders(?string $jwt): array
    {
        $headers = [
            'Accept' => 'application/ld+json',
            'Content-Type' => 'application/merge-patch+json',
        ];

        return null === $jwt ? $headers : array_merge($headers, ['Authorization' => 'Bearer '.$jwt]);
    }

    #[Test]
    public function theOwnerChangesTheirLocaleAndReadsItBack(): void
    {
        $jwt = $this->createAuthenticatedUser('switcher@example.com', ['locale' => 'fr'])['jwt'];
        $client = self::createClient();

        $response = $client->request('PATCH', '/users/me', [
            'headers' => $this->patchHeaders($jwt),
            'json' => ['locale' => 'en'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSame('en', $response->toArray()['locale'] ?? null);

        // Persisted, not just echoed back.
        $reread = $client->request('GET', '/users/me', [
            'headers' => ['Authorization' => 'Bearer '.$jwt],
        ]);

        $this->assertSame('en', $reread->toArray()['locale'] ?? null);
    }

    #[Test]
    public function anUnsupportedLocaleIsRejected(): void
    {
        $jwt = $this->createAuthenticatedUser('exotic@example.com', ['locale' => 'fr'])['jwt'];

        self::createClient()->request('PATCH', '/users/me', [
            'headers' => $this->patchHeaders($jwt),
            'json' => ['locale' => 'kl'],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function anOmittedLocaleIsRejectedRatherThanSilentlyIgnored(): void
    {
        $jwt = $this->createAuthenticatedUser('empty@example.com', ['locale' => 'fr'])['jwt'];

        self::createClient()->request('PATCH', '/users/me', [
            'headers' => $this->patchHeaders($jwt),
            'json' => [],
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function unauthenticatedRequestReturns401(): void
    {
        self::createClient()->request('PATCH', '/users/me', [
            'headers' => $this->patchHeaders(null),
            'json' => ['locale' => 'en'],
        ]);

        $this->assertResponseStatusCodeSame(401);
    }
}
