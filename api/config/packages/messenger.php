<?php

declare(strict_types=1);

use App\Message\AllEnrichmentsCompleted;
use App\Message\AnalyzeTerrain;
use App\Message\AnalyzeWind;
use App\Message\CheckBikeShops;
use App\Message\CheckBorderCrossing;
use App\Message\CheckCalendar;
use App\Message\CheckCulturalPois;
use App\Message\CheckFerries;
use App\Message\CheckFords;
use App\Message\CheckHealthServices;
use App\Message\CheckRailwayStations;
use App\Message\CheckWaterPoints;
use App\Message\FetchAndParseRoute;
use App\Message\FetchWeather;
use App\Message\GenerateStages;
use App\Message\RecalculateRouteSegment;
use App\Message\RecalculateStages;
use App\Message\ResolveStageLabels;
use App\Message\ScanAccommodations;
use App\Message\ScanEvents;
use App\Message\ScanPois;
use App\Message\SendPushNotification;
use App\Messenger\HandleCorrelationIdMiddleware;
use App\Messenger\SendCorrelationIdMiddleware;
use App\Messenger\StaleMessageMiddleware;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'messenger' => [
            'failure_transport' => 'failed',
            'buses' => [
                'messenger.bus.default' => [
                    'middleware' => [
                        SendCorrelationIdMiddleware::class,
                        HandleCorrelationIdMiddleware::class,
                        // Last of the three, so a discarded message still carries its
                        // correlation id into the log line that records the discard.
                        StaleMessageMiddleware::class,
                    ],
                ],
            ],
            'transports' => [
                'async' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                    'retry_strategy' => [
                        'max_retries' => 3,
                        'delay' => 1000,
                        'multiplier' => 2,
                    ],
                ],
                'failed' => '%env(MESSENGER_FAILED_DSN)%',
            ],
            'routing' => [
                FetchAndParseRoute::class => 'async',
                GenerateStages::class => 'async',
                ScanPois::class => 'async',
                ScanAccommodations::class => 'async',
                AnalyzeTerrain::class => 'async',
                FetchWeather::class => 'async',
                CheckCalendar::class => 'async',
                AnalyzeWind::class => 'async',
                CheckBikeShops::class => 'async',
                CheckBorderCrossing::class => 'async',
                CheckCulturalPois::class => 'async',
                CheckFerries::class => 'async',
                CheckFords::class => 'async',
                CheckHealthServices::class => 'async',
                CheckRailwayStations::class => 'async',
                CheckWaterPoints::class => 'async',
                RecalculateRouteSegment::class => 'async',
                RecalculateStages::class => 'async',
                ResolveStageLabels::class => 'async',
                ScanEvents::class => 'async',
                SendPushNotification::class => 'async',
                AllEnrichmentsCompleted::class => 'async',
            ],
        ],
    ]);
};
