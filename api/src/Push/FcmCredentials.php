<?php

declare(strict_types=1);

namespace App\Push;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * FCM service-account credentials, read from FCM_SERVICE_ACCOUNT_JSON (epic #1051).
 *
 * Fail-closed like AccessRequestHmacService: production MUST provide the JSON key
 * of a Firebase service account. The value is parsed lazily (on first access), so
 * an empty env only breaks an actual push attempt — the container still boots and
 * unrelated endpoints (device-token registration) keep working with no secret set.
 * A missing or malformed key raises a clear error the Messenger worker surfaces,
 * never a silent no-op.
 */
final class FcmCredentials
{
    /** @var array{project_id: string, client_email: string, private_key: string}|null */
    private ?array $decoded = null;

    public function __construct(
        #[Autowire(env: 'FCM_SERVICE_ACCOUNT_JSON')]
        private readonly string $serviceAccountJson,
    ) {
    }

    public function projectId(): string
    {
        return $this->decode()['project_id'];
    }

    public function clientEmail(): string
    {
        return $this->decode()['client_email'];
    }

    public function privateKey(): string
    {
        return $this->decode()['private_key'];
    }

    /**
     * @return array{project_id: string, client_email: string, private_key: string}
     */
    private function decode(): array
    {
        if (null !== $this->decoded) {
            return $this->decoded;
        }

        if ('' === trim($this->serviceAccountJson)) {
            throw new \RuntimeException('FCM_SERVICE_ACCOUNT_JSON must be configured to send push notifications.');
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($this->serviceAccountJson, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \RuntimeException('FCM_SERVICE_ACCOUNT_JSON is not valid JSON.', 0, $jsonException);
        }

        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (!isset($data[$key]) || !\is_string($data[$key]) || '' === $data[$key]) {
                throw new \RuntimeException(\sprintf('FCM_SERVICE_ACCOUNT_JSON is missing the "%s" field.', $key));
            }
        }

        return $this->decoded = [
            'project_id' => $data['project_id'],
            'client_email' => $data['client_email'],
            'private_key' => $data['private_key'],
        ];
    }
}
