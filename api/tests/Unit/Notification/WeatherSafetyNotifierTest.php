<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\ApiResource\TripRequest;
use App\Entity\Stage;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Notification\NotificationDispatcherInterface;
use App\Notification\WeatherSafetyNotifier;
use App\Repository\OwnedTripFinderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WeatherSafetyNotifierTest extends TestCase
{
    use NotificationTranslatorTrait;

    #[Test]
    public function pushesTheRiddenStageForTheTargetDay(): void
    {
        $day = new \DateTimeImmutable('2026-08-20', new \DateTimeZone('UTC'));
        $trip = $this->trip(new \DateTimeImmutable('2026-08-18', new \DateTimeZone('UTC')), 'fr');
        $ownerId = $trip->user?->getId()->toRfc4122();
        $tripId = $trip->id?->toRfc4122();

        // Day 3 = 2026-08-20 (start 08-18 + 2). One weather + one alert.
        $trip->addStage($this->stage($trip, dayNumber: 3, restDay: false, weather: ['description' => 'Ensoleillé', 'tempMin' => 12.0, 'tempMax' => 24.0], alerts: [['code' => 'wind_headwind']]));

        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $ownerId,
                NotificationCategory::WEATHER_SAFETY,
                $this->stringContains('Étape J3'),
                $this->stringContains('1 alerte de sécurité'),
                ['tripId' => $tripId, 'dayNumber' => '3'],
            )
            ->willReturn(true);

        $count = new WeatherSafetyNotifier($this->finder([$trip]), $dispatcher, $this->notificationTranslator())->notify($day);

        self::assertSame(1, $count);
    }

    #[Test]
    public function localisesTheCopyToTheTripLocale(): void
    {
        // Proves the trip locale reaches the translator: an English trip must get
        // English copy ("Day 3 — ...", "2 safety alerts"), not the default French.
        $day = new \DateTimeImmutable('2026-08-20', new \DateTimeZone('UTC'));
        $trip = $this->trip(new \DateTimeImmutable('2026-08-18', new \DateTimeZone('UTC')), 'en');
        $trip->addStage($this->stage($trip, dayNumber: 3, restDay: false, weather: ['description' => 'Sunny', 'tempMin' => 12.0, 'tempMax' => 24.0], alerts: [['code' => 'a'], ['code' => 'b']]));

        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->anything(),
                NotificationCategory::WEATHER_SAFETY,
                $this->stringContains('Day 3'),
                $this->stringContains('2 safety alerts'),
                $this->anything(),
            )
            ->willReturn(true);

        new WeatherSafetyNotifier($this->finder([$trip]), $dispatcher, $this->notificationTranslator())->notify($day);
    }

    #[Test]
    public function skipsATripWhoseTargetDayIsARestDay(): void
    {
        $day = new \DateTimeImmutable('2026-08-20', new \DateTimeZone('UTC'));
        $trip = $this->trip(new \DateTimeImmutable('2026-08-18', new \DateTimeZone('UTC')), 'fr');
        $trip->addStage($this->stage($trip, dayNumber: 3, restDay: true, weather: null, alerts: []));

        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $count = new WeatherSafetyNotifier($this->finder([$trip]), $dispatcher, $this->notificationTranslator())->notify($day);

        self::assertSame(0, $count);
    }

    private function trip(\DateTimeImmutable $startDate, string $locale): TripRequest
    {
        $trip = new TripRequest(Uuid::v7());
        $trip->user = new User('rider@example.com');
        $trip->startDate = $startDate;
        $trip->locale = $locale;

        return $trip;
    }

    /**
     * @param array<string, mixed>|null  $weather
     * @param list<array<string, mixed>> $alerts
     */
    private function stage(TripRequest $trip, int $dayNumber, bool $restDay, ?array $weather, array $alerts): Stage
    {
        $stage = new Stage($trip);
        $stage->setDayNumber($dayNumber)->setIsRestDay($restDay)->setWeather($weather)->setAlerts($alerts);

        return $stage;
    }

    /**
     * @param list<TripRequest> $trips
     */
    private function finder(array $trips): OwnedTripFinderInterface
    {
        $finder = $this->createStub(OwnedTripFinderInterface::class);
        $finder->method('findOwnedTripsCoveringDate')->willReturn($trips);

        return $finder;
    }
}
