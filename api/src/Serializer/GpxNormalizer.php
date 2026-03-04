<?php

declare(strict_types=1);

namespace App\Serializer;

use App\ApiResource\Stage;
use App\GpxWriter\GpxWriterInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class GpxNormalizer implements NormalizerInterface
{
    public function __construct(
        private GpxWriterInterface $gpxWriter,
    ) {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): string
    {
        \assert($data instanceof Stage);

        $label = $data->label ?? \sprintf('Stage %d', $data->dayNumber);

        return $this->gpxWriter->generate(
            $data->geometry ?: [$data->startPoint, $data->endPoint],
            $label,
        );
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Stage && 'gpx' === $format;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [Stage::class => 'gpx' === $format];
    }
}
