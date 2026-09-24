<?php

declare(strict_types=1);

namespace App\State\Mcp;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The tokens a destructive tool hands out, and takes back exactly once.
 *
 * Random, not derived — and that is the difference with {@see \App\Security\OAuth\ConsentStore},
 * which builds its handle by HMAC of the request it belongs to. That handle is not a secret:
 * reading the record behind it needs the Bearer of the very user it was made for, so the second
 * leg recomputes it and nothing has to travel. Here the token IS the only factor. Derived from
 * the arguments, an agent would compute it from what it was about to send and never make the
 * first call at all, which is the one call that produces the impact summary. The pattern from
 * ADR-079 does not transpose, and that is written in ADR-080 rather than left to be rediscovered.
 *
 * What a token proves is narrow and worth stating plainly: that a summary of the impact was
 * produced and put in the transcript a human can read back, and that the arguments did not move
 * between the two calls. It is not a human's agreement — the same agent makes both calls. It
 * defends against a mis-parameterised mutation, not against a hostile agent.
 */
final readonly class ConfirmationStore
{
    private const string PREFIX = 'mcp_confirmation.';

    public function __construct(
        #[Autowire(service: 'cache.mcp_confirmation')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    /** Mints a token for one call and one call only. */
    public function issue(string $binding): string
    {
        $token = bin2hex(random_bytes(16));

        $item = $this->pool->getItem(self::PREFIX.$token);
        $item->set($binding);

        $this->pool->save($item);

        return $token;
    }

    /**
     * True when this token was minted for this exact call, and never true twice.
     *
     * Spent whether or not it matched. A token offered for a different call is either an agent
     * that changed its mind between the two — in which case the impact summary it was shown no
     * longer describes what it is asking for, and it must be shown a new one — or someone
     * trying tokens, which should cost one attempt each.
     */
    public function consume(string $token, string $binding): bool
    {
        $key = self::PREFIX.$token;
        $stored = $this->pool->getItem($key)->get();

        $this->pool->deleteItem($key);

        return \is_string($stored) && hash_equals($stored, $binding);
    }
}
