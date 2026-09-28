<?php

declare(strict_types=1);

namespace App\Tests\Integration\Sentry;

use App\EventListener\RequestIdListener;
use App\Sentry\UserDataEnricher;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\EventListener\FirewallListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The enricher reads route attributes and the authenticated user, so it has to run after
 * the router has matched and the firewall has authenticated, and after the request id is
 * minted. Registered earlier, every tag but `request_id` was silently empty.
 */
final class UserDataEnricherPriorityTest extends KernelTestCase
{
    #[Test]
    public function runsAfterEverythingItReads(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);

        $required = [UserDataEnricher::class, RequestIdListener::class, RouterListener::class, FirewallListener::class];
        $priorities = [];
        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            $target = \is_array($listener) ? $listener[0] : $listener;
            foreach ($required as $class) {
                // instanceof: in debug the firewall is registered as its traceable subclass.
                if ($target instanceof $class) {
                    $priorities[$class] ??= $dispatcher->getListenerPriority(KernelEvents::REQUEST, $listener);
                }
            }
        }

        foreach ($required as $class) {
            self::assertArrayHasKey($class, $priorities, $class.' is not listening on kernel.request.');
        }

        self::assertLessThan($priorities[RequestIdListener::class], $priorities[UserDataEnricher::class]);
        self::assertLessThan($priorities[RouterListener::class], $priorities[UserDataEnricher::class]);
        self::assertLessThan($priorities[FirewallListener::class], $priorities[UserDataEnricher::class]);
    }
}
