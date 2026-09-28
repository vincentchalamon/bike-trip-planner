<?php

declare(strict_types=1);

namespace App\Serializer;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Trip;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a {@see Trip} into a single track: every stage geometry merged into one point
 * list, so that devices display the whole trip as one tour, and the POIs and accommodations of
 * every stage merged into one waypoint list.
 *
 * The whole-trip counterpart of {@see AbstractStageNormalizer}.
 */
abstract readonly class AbstractTripNormalizer implements NormalizerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof Trip) {
            throw new \InvalidArgumentException(\sprintf('Expected instance of %s, got %s.', Trip::class, get_debug_type($data)));
        }

        $export = $data->export() ?? throw new \LogicException(\sprintf('Trip %s carries nothing to export: only TripGpxProvider loads one.', $data->id));

        $points = [];
        $waypoints = [];

        foreach ($export->stages as $stage) {
            $stagePoints = array_map(
                static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
            );
            array_push($points, ...$stagePoints);

            foreach ($stage->resupply?->all() ?? [] as $poi) {
                $waypoints[] = $this->waypoint($poi->name, $poi->category, $poi->lat, $poi->lon);
            }

            foreach ($stage->accommodations as $accommodation) {
                $waypoints[] = $this->waypoint($accommodation->name, $accommodation->type, $accommodation->lat, $accommodation->lon);
            }
        }

        return [
            ...$this->header($export),
            'points' => $points,
            'waypoints' => $waypoints,
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Trip && $this->format() === $format;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [Trip::class => $this->format() === $format];
    }

    abstract protected function format(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function header(TripExport $export): array;

    /**
     * @return array{lat: float, lon: float, name: string, ...}
     */
    abstract protected function waypoint(string $name, string $category, float $lat, float $lon): array;
}
