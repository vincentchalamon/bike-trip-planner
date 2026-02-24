<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'messenger' => [
            'transports' => [
                'sync' => 'sync://',
                'async' => '%env(MESSENGER_TRANSPORT_DSN)%',
            ],
            'routing' => [
//                FetchAndParseRoute::class => 'async',
//                GenerateStages::class => 'async',
//                GenerateStageGpx::class => 'async',
//                ScanPois::class => 'async',
//                ScanAccommodations::class => 'async',
//                AnalyzeTerrain::class => 'async',
//                FetchWeather::class => 'async',
//                CheckCalendar::class => 'async',
//                AnalyzeWind::class => 'async',
//                CheckResupply::class => 'async',
//                CheckBikeShops::class => 'async',
//                RecalculateStages::class => 'async',
            ],
        ],
    ]);
};
