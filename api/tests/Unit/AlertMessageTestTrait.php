<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Alert\AlertRenderer;
use App\Alert\ReaderLocale;
use App\ApiResource\Model\Alert;
use App\Format\DecimalFormatter;
use App\Format\DistanceFormatter;
use App\Poi\PoiLabelResolver;
use Symfony\Bundle\SecurityBundle\Security;
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

    /**
     * The real renderer over the real catalogue.
     *
     * Producers no longer build prose (ADR-069), so a test that wants to assert what a
     * rider reads has to render it — with the same collaborators the application wires,
     * or the assertion proves nothing about production.
     */
    private function createAlertRenderer(): AlertRenderer
    {
        $translator = $this->createAlertTranslator();

        return new AlertRenderer(
            $translator,
            $this->createDistanceFormatter(),
            new DecimalFormatter(),
            new PoiLabelResolver($translator),
        );
    }

    /**
     * A reader nobody is signed in as, so the trip's own locale decides — the anonymous
     * share case, and the sane default for a unit test.
     */
    private function createReaderLocale(): ReaderLocale
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        return new ReaderLocale($security);
    }

    /**
     * The sentence a rider would read for this alert, rendered here rather than by the
     * producer that no longer builds one (ADR-069).
     *
     * `$dayNumber` is what `%stage%` and the continuity pair resolve against, so a test
     * asserting "stage 3" has to say which stage carries the alert.
     */
    private function renderMessage(Alert $alert, int $dayNumber = 1, string $locale = 'en'): string
    {
        $rendered = $this->createAlertRenderer()->render([[
            'messageKey' => $alert->messageKey,
            'parameters' => $alert->parameters,
            'parameterFormats' => $alert->parameterFormats,
        ]], $dayNumber, $locale);

        $message = $rendered[0]['message'];
        \assert(\is_string($message));

        return $message;
    }
}
