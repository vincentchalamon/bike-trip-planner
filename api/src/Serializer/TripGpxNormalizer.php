<?php

declare(strict_types=1);

namespace App\Serializer;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\Trip;
use App\Repository\TripRequestRepositoryInterface;
use App\Serializer\Mapper\WaypointMapper;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a {@see Trip} resource into a multi-segment GPX structure.
 *
 * Each stage becomes a separate `<trkseg>` inside a single `<trk>` element.
 * Waypoints (POIs, accommodations) from all stages are merged into the global
 * `<wpt>` list.
 */
final readonly class TripGpxNormalizer implements NormalizerInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof Trip) {
            throw new \InvalidArgumentException(\sprintf('Expected instance of %s, got %s.', Trip::class, get_debug_type($data)));
        }

        /** @var list<Stage> $stages */
        $stages = $this->tripStateManager->getStages($data->id) ?? [];

        $segments = [];
        $waypoints = [];

        foreach ($stages as $stage) {
            $points = array_map(
                static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
            );
            $segments[] = $points;

            foreach ($stage->pois as $poi) {
                $waypoints[] = [
                    'lat' => $poi->lat,
                    'lon' => $poi->lon,
                    'name' => $poi->name,
                    'symbol' => WaypointMapper::gpxSymbol($poi->category),
                    'type' => $poi->category,
                ];
            }

            foreach ($stage->accommodations as $accommodation) {
                $waypoints[] = [
                    'lat' => $accommodation->lat,
                    'lon' => $accommodation->lon,
                    'name' => $accommodation->name,
                    'symbol' => WaypointMapper::gpxSymbol($accommodation->type),
                    'type' => $accommodation->type,
                ];
            }
        }

        return [
            'trackName' => $data->id,
            'segments' => $segments,
            'waypoints' => $waypoints,
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Trip && 'gpx' === $format;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [Trip::class => 'gpx' === $format];
    }
}
