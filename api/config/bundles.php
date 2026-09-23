<?php

declare(strict_types=1);

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Lexik\Bundle\JWTAuthenticationBundle\LexikJWTAuthenticationBundle;
use Nelmio\CorsBundle\NelmioCorsBundle;
use Sentry\SentryBundle\SentryBundle;
use Symfony\AI\McpBundle\McpBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MercureBundle\MercureBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

return [
    FrameworkBundle::class => ['all' => true],
    TwigBundle::class => ['all' => true],
    NelmioCorsBundle::class => ['all' => true],
    ApiPlatformBundle::class => ['all' => true],
    MonologBundle::class => ['all' => true],
    WebProfilerBundle::class => ['dev' => true, 'test' => true],
    MercureBundle::class => ['all' => true],
    DoctrineBundle::class => ['all' => true],
    DoctrineMigrationsBundle::class => ['all' => true],
    SecurityBundle::class => ['all' => true],
    LexikJWTAuthenticationBundle::class => ['all' => true],
    ZenstruckFoundryBundle::class => ['dev' => true, 'test' => true],
    SentryBundle::class => ['prod' => true],
    McpBundle::class => ['all' => true],
];
