<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * What an unauthenticated browser gets on `/oauth/authorize`.
 *
 * Everywhere else in this API a missing credential is a 401 with a JSON body, which is the
 * right answer to a client library. Here the caller is a person, sent by an agent, arriving
 * through a top-level navigation: a JSON 401 renders as a blank page with no way forward.
 *
 * The redirect carries NO return target, and that is deliberate on two counts.
 *
 * Security: a login redirect that echoes back a caller-supplied destination is the standard
 * open-redirect shape. There is no parameter to tamper with here because there is no
 * parameter at all.
 *
 * Honesty: nothing downstream could honour one anyway. Authentication is a magic link, so
 * the journey crosses an email and often lands in a different tab; and the PWA's own gate
 * (`pwa/src/app/(app)/layout.tsx`) already does a bare `redirect("/login")` for every deep
 * link into the application. Preserving the authorization request across that would be a
 * change to the sign-in flow, not to this endpoint.
 *
 * The visible consequence, to be written down rather than discovered: a logged-out user
 * whose agent asks for authorization logs in and lands on the home page. The agent's request
 * is lost and has to be made again, from a signed-in browser.
 */
final readonly class LoginRedirectEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        #[Autowire(env: 'FRONTEND_URL')]
        private string $frontendUrl,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): RedirectResponse
    {
        return new RedirectResponse(rtrim($this->frontendUrl, '/').'/login');
    }
}
