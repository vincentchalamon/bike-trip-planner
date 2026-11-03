<?php

declare(strict_types=1);

namespace App\Tests\Unit\Push;

use App\Push\FcmCredentials;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FcmCredentialsTest extends TestCase
{
    #[Test]
    public function failsClosedOnAnEmptyKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('FCM_SERVICE_ACCOUNT_JSON must be configured');

        new FcmCredentials('')->projectId();
    }

    #[Test]
    public function failsClosedOnAMissingField(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('is missing the "client_email" field');

        new FcmCredentials((string) json_encode(['project_id' => 'p', 'private_key' => 'k']))->clientEmail();
    }

    #[Test]
    public function exposesTheDecodedFields(): void
    {
        $credentials = new FcmCredentials((string) json_encode([
            'project_id' => 'demo-project',
            'client_email' => 'sa@demo.iam.gserviceaccount.com',
            'private_key' => '-----BEGIN PRIVATE KEY-----',
        ]));

        self::assertSame('demo-project', $credentials->projectId());
        self::assertSame('sa@demo.iam.gserviceaccount.com', $credentials->clientEmail());
    }
}
