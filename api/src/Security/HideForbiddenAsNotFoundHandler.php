<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Surfaces object-level authorization failures as 404 instead of 403.
 *
 * All `security:` expressions in this app gate access by trip ownership
 * (`TRIP_VIEW`/`TRIP_EDIT`/`TRIP_DELETE`). Returning 403 for a trip the caller does
 * not own confirms the trip exists, letting an authenticated attacker enumerate
 * other users' trips by probing UUIDs (403 = exists / 404 = absent). Mapping these
 * denials to 404 makes a foreign trip indistinguishable from a non-existent one
 * (RFC 7231 §6.5.4 explicitly allows 404 to hide a forbidden resource; same
 * contract as GitHub on private repos). See ADR-038.
 *
 * Scope: the firewall invokes an access-denied handler only for *authenticated*
 * requests, so anonymous calls still become 401 via the entry point. State-based
 * denials on a resource the user *does* own (e.g. editing a locked trip) throw
 * `HttpException(423)` and never reach here, so they keep their 4xx semantics.
 */
final class HideForbiddenAsNotFoundHandler implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        throw new NotFoundHttpException(previous: $accessDeniedException);
    }
}
