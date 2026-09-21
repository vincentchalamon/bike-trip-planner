<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\EnrichmentMessageFactory;
use App\ApiResource\TripRequest;
use App\Message\AnalyzeTerrain;
use App\Message\CheckBikeShops;
use App\Message\CheckBorderCrossing;
use App\Message\CheckCalendar;
use App\Message\CheckCulturalPois;
use App\Message\CheckFerries;
use App\Message\CheckHealthServices;
use App\Message\CheckRailwayStations;
use App\Message\CheckWaterPoints;
use App\Message\FetchWeather;
use App\Message\ResolveStageLabels;
use App\Message\ScanAccommodations;
use App\Message\ScanEvents;
use App\Message\ScanPois;
use App\Service\TripAnalysisDispatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class TripAnalysisDispatcherTest extends TestCase
{
    #[Test]
    public function dispatchSendsEveryEnrichmentMessageOnce(): void
    {
        $tripId = 'trip-1';
        $generation = 3;
        $request = new TripRequest();
        $request->enabledAccommodationTypes = ['hotel', 'camp_site'];

        $expectedMessages = [
            ScanPois::class,
            ScanAccommodations::class,
            AnalyzeTerrain::class,
            FetchWeather::class,
            CheckCalendar::class,
            CheckBikeShops::class,
            CheckWaterPoints::class,
            CheckHealthServices::class,
            CheckCulturalPois::class,
            CheckRailwayStations::class,
            CheckBorderCrossing::class,
            CheckFerries::class,
            ScanEvents::class,
            ResolveStageLabels::class,
        ];

        $dispatched = [];

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus
            ->expects($this->exactly(\count($expectedMessages)))
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            });

        $dispatcher = new TripAnalysisDispatcher($messageBus, new EnrichmentMessageFactory());
        $dispatcher->dispatch($tripId, $request, $generation);

        // Compared as a set: the full pipeline is a fan-out onto an async bus, so which
        // messages go out is the contract and the order they go out in is not. Since
        // ADR-070 that order follows the ComputationName enum rather than a hand-written
        // list, and pinning it would only record an implementation detail.
        $dispatchedClasses = array_map(static fn (object $m): string => $m::class, $dispatched);
        sort($dispatchedClasses);
        sort($expectedMessages);
        $this->assertSame($expectedMessages, $dispatchedClasses);

        foreach ($dispatched as $message) {
            $this->assertObjectHasProperty('tripId', $message);
            $this->assertObjectHasProperty('generation', $message);
            /* @var object{tripId: string, generation: ?int} $message */
            $this->assertSame($tripId, $message->tripId);
            $this->assertSame($generation, $message->generation);
        }
    }

    #[Test]
    public function dispatchPropagatesEnabledAccommodationTypes(): void
    {
        $request = new TripRequest();
        $request->enabledAccommodationTypes = ['hotel', 'hostel'];

        $scanAccommodations = null;

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$scanAccommodations): Envelope {
                if ($message instanceof ScanAccommodations) {
                    $scanAccommodations = $message;
                }

                return new Envelope($message);
            });

        $dispatcher = new TripAnalysisDispatcher($messageBus, new EnrichmentMessageFactory());
        $dispatcher->dispatch('trip-1', $request);

        $this->assertInstanceOf(ScanAccommodations::class, $scanAccommodations);
        $this->assertSame(['hotel', 'hostel'], $scanAccommodations->enabledAccommodationTypes);
        $this->assertNull($scanAccommodations->generation);
    }

    #[Test]
    public function dispatchAcceptsNullGeneration(): void
    {
        $request = new TripRequest();

        $generations = [];

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus
            ->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$generations): Envelope {
                if (property_exists($message, 'generation')) {
                    /* @var object{generation: ?int} $message */
                    $generations[] = $message->generation;
                }

                return new Envelope($message);
            });

        $dispatcher = new TripAnalysisDispatcher($messageBus, new EnrichmentMessageFactory());
        $dispatcher->dispatch('trip-1', $request);

        $this->assertNotEmpty($generations);
        foreach ($generations as $value) {
            $this->assertNull($value);
        }
    }
}
