<?php

declare(strict_types=1);

use App\Message\AnalyzeTerrain;
use App\Message\AnalyzeWind;
use App\Message\CheckBikeShops;
use App\Message\CheckBorderCrossing;
use App\Message\CheckCalendar;
use App\Message\CheckCulturalPois;
use App\Message\CheckHealthServices;
use App\Message\CheckRailwayStations;
use App\Message\CheckWaterPoints;
use App\Message\FetchAndParseRoute;
use App\Message\FetchWeather;
use App\Message\GenerateStages;
use App\Message\RecalculateRouteSegment;
use App\Message\RecalculateStages;
use App\Message\ScanAccommodations;
use App\Message\ScanAllOsmData;
use App\Message\ScanEvents;
use App\Message\ScanPois;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'messenger' => [
            'failure_transport' => 'failed',
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
                ScanAllOsmData::class => 'async',
                ScanPois::class => 'async',
                ScanAccommodations::class => 'async',
                AnalyzeTerrain::class => 'async',
                FetchWeather::class => 'async',
                CheckCalendar::class => 'async',
                AnalyzeWind::class => 'async',
                CheckBikeShops::class => 'async',
                CheckBorderCrossing::class => 'async',
                CheckCulturalPois::class => 'async',
                CheckHealthServices::class => 'async',
                CheckRailwayStations::class => 'async',
                CheckWaterPoints::class => 'async',
                RecalculateRouteSegment::class => 'async',
                RecalculateStages::class => 'async',
                ScanEvents::class => 'async',
            ],
        ],
    ]);
};
