<?php

declare(strict_types=1);

namespace App\Llm;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Short-lived per-(trip, user) dialogue history backed by Redis.
 *
 * Only the last {@see self::MAX_MESSAGES} turns are kept so the prompt window
 * stays bounded for LLaMA 3B inference. Entries are stored as plain `{role,
 * content}` arrays compatible with the provider chat API.
 */
final readonly class ChatHistoryStore
{
    public const int MAX_MESSAGES = 5;

    public function __construct(
        #[Autowire(service: 'cache.trip_chat')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function get(string $tripId, string $userId): array
    {
        $item = $this->cache->getItem($this->key($tripId, $userId));

        return $item->isHit() ? $this->sanitize($item->get()) : [];
    }

    public function append(string $tripId, string $userId, string $role, string $content): void
    {
        $this->appendMany($tripId, $userId, [['role' => $role, 'content' => $content]]);
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public function appendMany(string $tripId, string $userId, array $messages): void
    {
        if ([] === $messages) {
            return;
        }

        $item = $this->cache->getItem($this->key($tripId, $userId));
        $history = $item->isHit() ? $this->sanitize($item->get()) : [];

        foreach ($messages as $message) {
            $history[] = $message;
        }

        if (\count($history) > self::MAX_MESSAGES) {
            $history = \array_slice($history, -self::MAX_MESSAGES);
        }

        $item->set($history);
        $this->cache->save($item);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function sanitize(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $entry) {
            if (!\is_array($entry)
                || !isset($entry['role'], $entry['content'])
                || !\is_string($entry['role'])
                || !\is_string($entry['content'])
            ) {
                continue;
            }

            $result[] = ['role' => $entry['role'], 'content' => $entry['content']];
        }

        return $result;
    }

    private function key(string $tripId, string $userId): string
    {
        return \sprintf('trip.%s.user.%s.chat', $tripId, $userId);
    }
}
