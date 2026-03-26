<?php

declare(strict_types=1);

namespace App\Serializer;

use InvalidArgumentException;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

abstract readonly class AbstractStageNormalizer implements NormalizerInterface
{
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof Stage) {
            throw new InvalidArgumentException(\sprintf('Expected instance of %s, got %s.', Stage::class, get_debug_type($data)));
        }

        return [
            $this->nameKey() => $data->label ?? \sprintf('Stage %d', $data->dayNumber),
            'points' => array_map(
                static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                $data->geometry ?: [$data->startPoint, $data->endPoint],
            ),
            'waypoints' => $this->buildWaypoints($data),
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Stage && $this->format() === $format;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [Stage::class => $this->format() === $format];
    }

    abstract protected function format(): string;

    abstract protected function nameKey(): string;

    /**
     * @return array{lat: float, lon: float, name: string, type: string}
     */
    protected function buildWaypointEntry(string $name, string $category, float $lat, float $lon): array
    {
        return [
            'lat' => $lat,
            'lon' => $lon,
            'name' => $name,
            'type' => $category,
        ];
    }

    /**
     * @return list<array{lat: float, lon: float, name: string, ...}>
     */
    private function buildWaypoints(Stage $stage): array
    {
        $waypoints = [];

        foreach ($stage->pois as $poi) {
            $waypoints[] = $this->buildWaypointEntry($poi->name, $poi->category, $poi->lat, $poi->lon);
        }

        foreach ($stage->accommodations as $accommodation) {
            $waypoints[] = $this->buildWaypointEntry($accommodation->name, $accommodation->type, $accommodation->lat, $accommodation->lon);
        }

        return $waypoints;
    }
}
