<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds a translator backed by the real `notifications` catalogues so the notifier
 * unit tests assert the actual localised copy (and catch a wrong/missing locale).
 */
trait NotificationTranslatorTrait
{
    private function notificationTranslator(): TranslatorInterface
    {
        $dir = \dirname(__DIR__, 3).'/translations';

        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $dir.'/notifications.en.yaml', 'en', 'notifications');
        $translator->addResource('yaml', $dir.'/notifications.fr.yaml', 'fr', 'notifications');

        return $translator;
    }
}
