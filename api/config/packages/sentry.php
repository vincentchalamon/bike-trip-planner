<?php

declare(strict_types=1);

use App\Sentry\ExceptionFilter;
use App\Sentry\UserDataEnricher;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/*
 * Sentry / GlitchTip configuration (P1.1, ADR-031).
 *
 * The Sentry bundle is only registered in the `prod` environment (see
 * bundles.php). In dev/test we still wire the `App\Sentry\*` services so they
 * remain unit-testable; they fall back to noop behaviour when the Hub is null.
 *
 * - `traces_sample_rate: 0.05` and `profiles_sample_rate: 0` keep ingestion
 *   well below GlitchTip's free quota.
 * - `before_send` is wired to {@see ExceptionFilter} which drops 4xx noise.
 */
return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    // Always register the filter so unit tests can run it directly.
    $services->set(ExceptionFilter::class)
        ->autowire()
        ->autoconfigure();

    if ('prod' === $containerConfigurator->env()) {
        $services->set(UserDataEnricher::class)
            ->args([
                service(HubInterface::class)->nullOnInvalid(),
                service('security.helper')->nullOnInvalid(),
            ])
            ->autoconfigure();

        $containerConfigurator->extension('sentry', [
            'dsn' => '%env(default::SENTRY_DSN)%',
            'options' => [
                'environment' => '%env(APP_ENV)%',
                'release' => '%env(default::APP_RELEASE)%',
                'traces_sample_rate' => 0.05,
                'profiles_sample_rate' => 0.0,
                'send_default_pii' => false,
                'attach_stacktrace' => true,
                'before_send' => ExceptionFilter::class,
            ],
        ]);

        return;
    }

    // dev/test: register the enricher with a null hub so the kernel.request
    // event listener stays no-op until the SentryBundle is enabled.
    $services->set(UserDataEnricher::class)
        ->args([null, service('security.helper')->nullOnInvalid()])
        ->autoconfigure();
};
