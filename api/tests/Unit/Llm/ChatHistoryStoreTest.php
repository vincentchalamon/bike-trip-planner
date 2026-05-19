<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\ChatHistoryStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ChatHistoryStoreTest extends TestCase
{
    private const string TRIP_ID = '01936f6e-0000-7000-8000-000000000099';

    private const string USER_ID = '0193c000-0000-7000-8000-000000000001';

    #[Test]
    public function getReturnsEmptyArrayWhenNoHistoryIsStored(): void
    {
        $store = new ChatHistoryStore(new ArrayAdapter());

        self::assertSame([], $store->get(self::TRIP_ID, self::USER_ID));
    }

    #[Test]
    public function appendAndGetRoundTripASingleMessage(): void
    {
        $store = new ChatHistoryStore(new ArrayAdapter());

        $store->append(self::TRIP_ID, self::USER_ID, 'user', 'Bonjour');

        self::assertSame(
            [['role' => 'user', 'content' => 'Bonjour']],
            $store->get(self::TRIP_ID, self::USER_ID),
        );
    }

    #[Test]
    public function appendManyPersistsAllMessagesInOrder(): void
    {
        $store = new ChatHistoryStore(new ArrayAdapter());

        $store->appendMany(self::TRIP_ID, self::USER_ID, [
            ['role' => 'user', 'content' => 'Bonjour'],
            ['role' => 'assistant', 'content' => 'Salut !'],
        ]);

        self::assertSame(
            [
                ['role' => 'user', 'content' => 'Bonjour'],
                ['role' => 'assistant', 'content' => 'Salut !'],
            ],
            $store->get(self::TRIP_ID, self::USER_ID),
        );
    }

    #[Test]
    public function appendManyWithAnEmptyListDoesNothing(): void
    {
        $store = new ChatHistoryStore(new ArrayAdapter());
        $store->append(self::TRIP_ID, self::USER_ID, 'user', 'Bonjour');

        $store->appendMany(self::TRIP_ID, self::USER_ID, []);

        self::assertSame(
            [['role' => 'user', 'content' => 'Bonjour']],
            $store->get(self::TRIP_ID, self::USER_ID),
        );
    }

    #[Test]
    public function historyIsTrimmedToTheLastMaxMessagesWhenItExceedsTheBound(): void
    {
        $store = new ChatHistoryStore(new ArrayAdapter());

        for ($i = 1; $i <= ChatHistoryStore::MAX_MESSAGES + 2; ++$i) {
            $store->append(self::TRIP_ID, self::USER_ID, 'user', 'msg '.$i);
        }

        $history = $store->get(self::TRIP_ID, self::USER_ID);

        self::assertCount(ChatHistoryStore::MAX_MESSAGES, $history);
        self::assertSame('msg 3', $history[0]['content']);
        self::assertSame('msg '.(ChatHistoryStore::MAX_MESSAGES + 2), $history[ChatHistoryStore::MAX_MESSAGES - 1]['content']);
    }

    #[Test]
    public function getDiscardsMalformedEntriesAndKeepsValidOnes(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('trip.'.self::TRIP_ID.'.user.'.self::USER_ID.'.chat');
        $item->set([
            ['role' => 'user', 'content' => 'ok'],
            'not-an-array',
            ['role' => 'assistant'], // missing content
            ['role' => 123, 'content' => 'bad-role-type'],
            ['role' => 'user', 'content' => 'still ok'],
        ]);
        $cache->save($item);

        $store = new ChatHistoryStore($cache);

        self::assertSame(
            [
                ['role' => 'user', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'still ok'],
            ],
            $store->get(self::TRIP_ID, self::USER_ID),
        );
    }

    #[Test]
    public function historyIsScopedPerTripAndUser(): void
    {
        $store = new ChatHistoryStore(new ArrayAdapter());

        $store->append('trip-a', 'user-1', 'user', 'A1');
        $store->append('trip-b', 'user-1', 'user', 'B1');
        $store->append('trip-a', 'user-2', 'user', 'A2');

        self::assertSame([['role' => 'user', 'content' => 'A1']], $store->get('trip-a', 'user-1'));
        self::assertSame([['role' => 'user', 'content' => 'B1']], $store->get('trip-b', 'user-1'));
        self::assertSame([['role' => 'user', 'content' => 'A2']], $store->get('trip-a', 'user-2'));
    }
}
