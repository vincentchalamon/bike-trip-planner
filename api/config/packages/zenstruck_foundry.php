<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    if (\in_array($containerConfigurator->env(), ['dev', 'test'], true)) {
        $containerConfigurator->extension('zenstruck_foundry', [
            'persistence' => [
                'flush_once' => true,
            ],
            // Forced to true in Foundry 3.0; set explicitly so the 2.7 deprecation
            // does not fail the suite under failOnDeprecation.
            'enable_auto_refresh_with_lazy_objects' => true,
            'orm' => [
                'reset' => [
                    // The reference migration is registered into the default history
                    // in dev/test (see doctrine_migrations.php), so this single
                    // `migrate` reset creates the osm/tourism tables the functional
                    // tests read (HealthControllerTest) on the one test database that
                    // backs both connections (ADR-060).
                    'mode' => 'migrate',
                ],
            ],
        ]);
    }
};
