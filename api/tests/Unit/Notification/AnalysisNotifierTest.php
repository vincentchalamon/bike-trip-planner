<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Enum\NotificationCategory;
use App\Mercure\MercureSubscriptionCheckerInterface;
use App\Notification\AnalysisNotifier;
use App\Notification\NotificationDispatcherInterface;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnalysisNotifierTest extends TestCase
{
    use NotificationTranslatorTrait;

    private const string TRIP_ID = '0192a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string OWNER_ID = '0192a1b2-c3d4-7e5f-8a9b-000000000001';

    #[Test]
    public function doesNotPushWhenAnSseSubscriberIsWatchingTheTrip(): void
    {
        // Guard under test: an active SSE subscriber means the rider already got
        // TRIP_READY, so no push. Removing the guard makes this assertion fail.
        $notifier = new AnalysisNotifier(
            $this->tripRepository(self::OWNER_ID, 'fr'),
            $this->subscriptionChecker(true),
            $dispatcher = $this->createMock(NotificationDispatcherInterface::class),
            $this->notificationTranslator(),
        );

        $dispatcher->expects($this->never())->method('dispatch');

        $notifier->notify(self::TRIP_ID, ['weather' => 'done']);
    }

    #[Test]
    public function pushesTheDoneNotificationWhenNoSubscriberIsWatching(): void
    {
        $notifier = new AnalysisNotifier(
            $this->tripRepository(self::OWNER_ID, 'fr'),
            $this->subscriptionChecker(false),
            $dispatcher = $this->createMock(NotificationDispatcherInterface::class),
            $this->notificationTranslator(),
        );

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                self::OWNER_ID,
                NotificationCategory::ANALYSIS_DONE,
                'Analyse terminée',
                $this->stringContains('prêt'),
                ['tripId' => self::TRIP_ID],
            )
            ->willReturn(true);

        $notifier->notify(self::TRIP_ID, ['weather' => 'done', 'terrain' => 'done']);
    }

    #[Test]
    public function localisesTheCopyToTheTripLocale(): void
    {
        // Proves the trip locale is resolved and routed to the translator: an
        // English trip must get English copy, not the default French.
        $notifier = new AnalysisNotifier(
            $this->tripRepository(self::OWNER_ID, 'en'),
            $this->subscriptionChecker(false),
            $dispatcher = $this->createMock(NotificationDispatcherInterface::class),
            $this->notificationTranslator(),
        );

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(self::OWNER_ID, NotificationCategory::ANALYSIS_DONE, 'Analysis complete', $this->stringContains('ready'), $this->anything())
            ->willReturn(true);

        $notifier->notify(self::TRIP_ID, ['weather' => 'done']);
    }

    #[Test]
    public function pushesTheIncompleteVariantWhenAComputationFailed(): void
    {
        $notifier = new AnalysisNotifier(
            $this->tripRepository(self::OWNER_ID, 'fr'),
            $this->subscriptionChecker(false),
            $dispatcher = $this->createMock(NotificationDispatcherInterface::class),
            $this->notificationTranslator(),
        );

        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(self::OWNER_ID, NotificationCategory::ANALYSIS_DONE, 'Analyse incomplète', $this->anything(), $this->anything())
            ->willReturn(true);

        $notifier->notify(self::TRIP_ID, ['weather' => 'done', 'terrain' => 'failed']);
    }

    #[Test]
    public function doesNothingForAnAnonymousTrip(): void
    {
        $checker = $this->createMock(MercureSubscriptionCheckerInterface::class);
        $checker->expects($this->never())->method('hasActiveSubscriber');

        $notifier = new AnalysisNotifier(
            $this->tripRepository(null, 'fr'),
            $checker,
            $dispatcher = $this->createMock(NotificationDispatcherInterface::class),
            $this->notificationTranslator(),
        );

        $dispatcher->expects($this->never())->method('dispatch');

        $notifier->notify(self::TRIP_ID, ['weather' => 'done']);
    }

    private function tripRepository(?string $ownerId, string $locale): TripRequestRepositoryInterface
    {
        $repository = $this->createStub(TripRequestRepositoryInterface::class);
        $repository->method('getOwnerId')->willReturn($ownerId);
        $repository->method('getLocale')->willReturn($locale);

        return $repository;
    }

    private function subscriptionChecker(bool $hasSubscriber): MercureSubscriptionCheckerInterface
    {
        $checker = $this->createStub(MercureSubscriptionCheckerInterface::class);
        $checker->method('hasActiveSubscriber')->willReturn($hasSubscriber);

        return $checker;
    }
}
