<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where a consent decision lives between the two legs of an authorization request.
 *
 * The browser leaves `/oauth/authorize`, goes to a page in the PWA, and comes back. Nothing
 * carries the decision across that gap on its own: the API has no session, and the flow
 * crosses an origin boundary in the sense that matters — the deciding request is an XHR
 * carrying a Bearer, while the returning one is a top-level navigation carrying a cookie.
 *
 * So the decision is recorded here and nothing secret travels in a URL. The handle in the
 * redirect is not a credential: reading or approving the record requires the Bearer of the
 * very user it was created for. What a leaked handle buys is nothing.
 *
 * The handle is DERIVED from the request rather than random, which is what makes the return
 * leg work without a query parameter of its own: the authorization endpoint recomputes it
 * from the request it is already holding. Two consequences fall out for free — an argument
 * changed between the two legs yields a different handle and therefore no decision, and two
 * concurrent authorizations never collide (PKCE gives each its own code challenge).
 *
 * It is keyed with APP_SECRET so it cannot be computed from public values, and single-use:
 * {@see consume()} deletes as it reads.
 */
final readonly class ConsentStore
{
    public function __construct(
        #[Autowire(service: 'cache.oauth_consent')]
        private CacheItemPoolInterface $pool,
        #[Autowire(param: 'kernel.secret')]
        #[\SensitiveParameter]
        private string $secret,
    ) {
    }

    /**
     * @param list<string> $scopes
     */
    public function handle(
        string $userId,
        string $clientId,
        array $scopes,
        ?string $redirectUri,
        ?string $codeChallenge,
    ): string {
        sort($scopes);

        // Every field here is load-bearing, and each has its own case in ConsentFlowTest.
        // Dropping `redirectUri` is the expensive one: a consent given for one registered
        // address would then complete for another the same client registered, and the code
        // would be delivered there.
        return hash_hmac('sha256', implode("\0", [
            $userId,
            $clientId,
            implode(' ', $scopes),
            $redirectUri ?? '',
            $codeChallenge ?? '',
        ]), $this->secret);
    }

    public function put(string $handle, ConsentRecord $record): void
    {
        $item = $this->pool->getItem($handle);
        $item->set($record);

        $this->pool->save($item);
    }

    public function get(string $handle): ?ConsentRecord
    {
        $value = $this->pool->getItem($handle)->get();

        return $value instanceof ConsentRecord ? $value : null;
    }

    /**
     * Reads and deletes in one go: a decision answers one authorization request and not the
     * next one. Replaying the return leg finds nothing and lands back on the consent screen.
     */
    public function consume(string $handle): ?ConsentRecord
    {
        $record = $this->get($handle);
        $this->pool->deleteItem($handle);

        return $record;
    }
}
