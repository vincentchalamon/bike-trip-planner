<?php

declare(strict_types=1);

namespace App\Sentry;

use App\Entity\User;
use App\EventListener\RequestIdListener;
use Sentry\State\HubInterface;
use Sentry\UserDataBag;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sentry scope enricher (P1.1).
 *
 * Runs on every main HTTP request and pushes the correlation ID minted by
 * {@see RequestIdListener} into the current Sentry scope, alongside the
 * authenticated user identifier (from the JWT-backed firewall) and the
 * optional `trip_id` / `computation_name` route parameters.
 *
 * Strictly non-PII: only the {@see User::getId()} UUID is forwarded — never
 * the email, JWT, password, or raw GPX payload (see CLAUDE.md security
 * constraints).
 */
final readonly class UserDataEnricher
{
    public function __construct(
        private ?HubInterface $hub = null,
        private ?Security $security = null,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$this->hub instanceof HubInterface) {
            return;
        }

        if (HttpKernelInterface::MAIN_REQUEST !== $event->getRequestType()) {
            return;
        }

        $request = $event->getRequest();
        $this->hub->configureScope(function ($scope) use ($request): void {
            $correlationId = $this->stringAttribute($request, RequestIdListener::ATTRIBUTE);
            if (null !== $correlationId) {
                $scope->setTag('request_id', $correlationId);
            }

            $tripId = $this->stringAttribute($request, 'tripId')
                ?? $this->stringAttribute($request, 'trip_id')
                ?? $this->stringAttribute($request, 'id');
            if (null !== $tripId) {
                $scope->setTag('trip_id', $tripId);
            }

            $computationName = $this->stringAttribute($request, 'computation_name')
                ?? $this->stringAttribute($request, 'computationName');
            if (null !== $computationName) {
                $scope->setTag('computation_name', $computationName);
            }

            $user = $this->security?->getUser();
            if ($user instanceof User) {
                $scope->setUser(new UserDataBag($user->getId()->toRfc4122()));
            }
        });
    }

    private function stringAttribute(Request $request, string $name): ?string
    {
        $value = $request->attributes->get($name);

        if (\is_string($value) && '' !== $value) {
            return $value;
        }

        if (\is_object($value) && method_exists($value, 'toRfc4122')) {
            $rfc = $value->toRfc4122();
            if (\is_string($rfc) && '' !== $rfc) {
                return $rfc;
            }
        }

        if (\is_object($value) && method_exists($value, '__toString')) {
            $str = (string) $value;
            if ('' !== $str) {
                return $str;
            }
        }

        return null;
    }
}
