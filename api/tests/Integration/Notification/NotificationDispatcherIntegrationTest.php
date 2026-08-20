<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Entity\NotificationPreference;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Message\SendPushNotification;
use App\Notification\NotificationDispatcher;
use App\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Wires NotificationDispatcher to the REAL NotificationPreferenceRepository (#1124):
 * the dispatcher's opt-in gate runs the repository's `IDENTITY(np.user) = :userId`
 * lookup against real Postgres, which the stubbed unit test cannot exercise. Guards
 * that a regression in that lookup (e.g. the uuid-binding) would surface here.
 */
final class NotificationDispatcherIntegrationTest extends KernelTestCase
{
    use ResetDatabase;

    private EntityManagerInterface $em;

    private NotificationPreferenceRepository $repository;

    /** @var list<SendPushNotification> */
    private array $dispatched = [];

    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->repository = self::getContainer()->get(NotificationPreferenceRepository::class);

        $this->dispatched = [];
        $record = function (SendPushNotification $message): void {
            $this->dispatched[] = $message;
        };
        $bus = new readonly class ($record) implements MessageBusInterface {
            /** @param \Closure(SendPushNotification): void $record */
            public function __construct(private \Closure $record)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                \assert($message instanceof SendPushNotification);
                ($this->record)($message);

                return new Envelope($message);
            }
        };
        $this->dispatcher = new NotificationDispatcher($bus, $this->repository);
    }

    #[Test]
    public function dispatchesWhenTheStoredPreferenceEnablesTheCategory(): void
    {
        $user = $this->persistUser('on@example.com');
        // ZONE_OPENING defaults to off, so only an explicit opt-in enables it.
        $this->repository->save(new NotificationPreference($user, NotificationCategory::ZONE_OPENING, true));

        $sent = $this->dispatcher->dispatch(
            $user->getId()->toRfc4122(),
            NotificationCategory::ZONE_OPENING,
            'Titre',
            'Corps',
        );

        self::assertTrue($sent);
        self::assertCount(1, $this->dispatched);
        self::assertSame('zoneOpening', $this->dispatched[0]->category);
    }

    #[Test]
    public function doesNotDispatchWhenTheStoredPreferenceDisablesTheCategory(): void
    {
        $user = $this->persistUser('off@example.com');
        $this->repository->save(new NotificationPreference($user, NotificationCategory::WEATHER_SAFETY, false));

        $sent = $this->dispatcher->dispatch(
            $user->getId()->toRfc4122(),
            NotificationCategory::WEATHER_SAFETY,
            'Titre',
            'Corps',
        );

        self::assertFalse($sent);
        self::assertSame([], $this->dispatched);
    }

    /**
     * @param non-empty-string $email
     */
    private function persistUser(string $email): User
    {
        $user = new User($email);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
