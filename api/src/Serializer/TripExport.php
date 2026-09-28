<?php

declare(strict_types=1);

namespace App\Serializer;

use App\ApiResource\Stage;

/**
 * What a whole-trip GPX or FIT file is built from, loaded once by
 * {@see \App\State\TripGpxProvider} and carried to the normalizer on the {@see \App\ApiResource\Trip}.
 *
 * The normalizers used to reload the stages themselves, after the provider had already read
 * them to decide whether there was a file to build at all: two hydrations of every stage per
 * download.
 */
final readonly class TripExport
{
    /**
     * @param list<Stage> $stages
     */
    public function __construct(
        public string $name,
        public ?string $sourceUrl,
        public array $stages,
    ) {
    }
}
