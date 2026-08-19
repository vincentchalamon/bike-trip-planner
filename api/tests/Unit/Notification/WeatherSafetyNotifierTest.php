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
    #[Test]
    public function pushesTheRiddenStageForTheTargetDay(): void
    {
        $day = new \DateTimeImmutable('2026-08-20', new \DateTimeZone('UTC'));
        $trip = $this->trip(new \DateTimeImmutable('2026-08-18', new \DateTimeZone('UTC')));
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
                $this->stringContains('1 alerte'),
                ['tripId' => $tripId, 'dayNumber' => '3'],
            )
            ->willReturn(true);

        $count = new WeatherSafetyNotifier($this->finder([$trip]), $dispatcher)->notify($day);

        self::assertSame(1, $count);
    }

    #[Test]
    public function skipsATripWhoseTargetDayIsARestDay(): void
    {
        $day = new \DateTimeImmutable('2026-08-20', new \DateTimeZone('UTC'));
        $trip = $this->trip(new \DateTimeImmutable('2026-08-18', new \DateTimeZone('UTC')));
        $trip->addStage($this->stage($trip, dayNumber: 3, restDay: true, weather: null, alerts: []));

        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $count = new WeatherSafetyNotifier($this->finder([$trip]), $dispatcher)->notify($day);

        self::assertSame(0, $count);
    }

    private function trip(\DateTimeImmutable $startDate): TripRequest
    {
        $trip = new TripRequest(Uuid::v7());
        $trip->user = new User('rider@example.com');
        $trip->startDate = $startDate;

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
