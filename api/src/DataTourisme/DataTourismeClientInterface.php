<?php

declare(strict_types=1);

namespace App\DataTourisme;

interface DataTourismeClientInterface
{
    public function isEnabled(): bool;

    /**
     * Fetches data from the DataTourisme API with Redis caching and rate limiting.
     * Returns ['results' => []] silently on network error or quota exhaustion.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function request(string $path, array $query = [], ?int $ttlSeconds = null): array;
}
