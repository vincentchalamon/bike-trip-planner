<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Mcp;

use App\State\Mcp\ConfirmationStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;

/**
 * That a confirmation token is spent once even when two calls arrive together.
 *
 * `McpConfirmationProcessorTest` covers the sequential case, and cannot cover this one: the
 * read and the delete are two cache round trips, so two calls carrying the same token — a
 * client retrying while its first attempt is still in flight — could both read the binding
 * before either deletion landed, and a trip would be deleted twice. Standing in for the second
 * caller here is the lock itself, held from outside.
 */
final class ConfirmationStoreTest extends KernelTestCase
{
    /**
     * A token comes back through a model, and a model is free to truncate it, quote it or
     * paraphrase it. The string is on its way to a cache key and a lock name, and Symfony's
     * PSR-6 adapters throw on a key holding one of `{}()/\@:` — which would turn the documented
     * "not valid for this call" into a 500, a worse answer given louder.
     */
    #[Test]
    public function whatCannotBeATokenNeverReachesTheBackend(): void
    {
        $store = $this->store();

        foreach (['', 'not-a-token', '{evil}', 'a/b\\c@d:e', str_repeat('f', 5000), ' 0123456789abcdef0123456789abcdef ', '0123456789ABCDEF0123456789ABCDEF'] as $notAToken) {
            self::assertFalse($store->consume($notAToken, 'a-binding'), \sprintf('"%s" was looked up as a token.', substr($notAToken, 0, 40)));
        }
    }

    /** The check above is only worth anything while it still describes what `issue()` mints. */
    #[Test]
    public function whatIsMintedIsWhatIsAccepted(): void
    {
        $store = $this->store();

        self::assertTrue($store->consume($store->issue('a-binding'), 'a-binding'));
    }

    #[Test]
    public function aTokenBeingSpentElsewhereIsRefusedHere(): void
    {
        $store = $this->store();
        $locks = self::getContainer()->get('lock.factory');
        \assert($locks instanceof LockFactory);

        $token = $store->issue('a-binding');

        // The other call, mid-flight: it has the lock and has not deleted the item yet.
        $elsewhere = $locks->createLock('mcp_confirmation.'.$token, 5);
        self::assertTrue($elsewhere->acquire(), 'The lock was already held — this test cannot say anything.');

        try {
            self::assertFalse($store->consume($token, 'a-binding'), 'Two calls were both told the token was theirs.');
        } finally {
            $elsewhere->release();
        }

        // And the token survives the refusal: the call that holds it is the one entitled to
        // spend it, so losing the race must not cost the winner its token.
        self::assertTrue($store->consume($token, 'a-binding'));
        self::assertFalse($store->consume($token, 'a-binding'));
    }

    private function store(): ConfirmationStore
    {
        self::bootKernel();

        $store = self::getContainer()->get(ConfirmationStore::class);
        \assert($store instanceof ConfirmationStore);

        return $store;
    }
}
