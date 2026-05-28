<?php

declare(strict_types=1);

namespace Provisioner;

final readonly class RegionSelectionStore
{
    public function __construct(private string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * @return list<string>
     */
    public function load(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $contents = file_get_contents($this->path);
        if (false === $contents || '' === $contents) {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded) || !isset($decoded['slugs']) || !\is_array($decoded['slugs'])) {
            return [];
        }

        $slugs = [];
        foreach ($decoded['slugs'] as $slug) {
            if (\is_string($slug) && '' !== $slug) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /**
     * @param list<string> $slugs
     */
    public function save(array $slugs): void
    {
        $dir = \dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $payload = json_encode(
            ['slugs' => array_values($slugs)],
            \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        );

        if (false === file_put_contents($this->path, $payload.\PHP_EOL)) {
            throw new \RuntimeException(\sprintf('Failed to write region selection to %s', $this->path));
        }
    }
}
