<?php

declare(strict_types=1);

use App\Entity\User;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $containerConfigurator->extension('framework', [
        'default_locale' => User::FALLBACK_LOCALE,
        'translator' => [
            'default_path' => '%kernel.project_dir%/translations',
            'providers' => null,
        ],
    ]);
};
