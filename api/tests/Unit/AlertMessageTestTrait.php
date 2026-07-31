<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Format\DecimalFormatter;
use App\Format\DistanceFormatter;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the real translator over `translations/alerts.*.yaml` so a test can
 * assert the message a rider actually reads, in both supported locales.
 */
trait AlertMessageTestTrait
{
    private function createAlertTranslator(): TranslatorInterface
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());

        foreach (['en', 'fr'] as $locale) {
            $translator->addResource('yaml', __DIR__.'/../../translations/alerts.'.$locale.'.yaml', $locale, 'alerts');
        }

        return $translator;
    }

    private function createDistanceFormatter(): DistanceFormatter
    {
        return new DistanceFormatter(new DecimalFormatter());
    }
}
