<?php

declare(strict_types=1);

namespace App\Serializer;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\Trip;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes a {@see Trip} resource into a single FIT course structure.
 *
 * All stage geometries are merged into one continuous point list so that GPS
 * devices display the whole trip as a single course. POIs and accommodations
 * from every stage are merged into the course-point/waypoint list. Mirrors
 * {@see TripGpxNormalizer} for the FIT format (the {@see FitEncoder} consumes
 * the same `courseName`/`points`/`waypoints` shape as the per-stage FIT export).
 */
final readonly class TripFitNormalizer implements NormalizerInterface
{
    public function __construct(
        private TripRequestRepositoryInterface $tripStateManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof Trip) {
            throw new \InvalidArgumentException(\sprintf('Expected instance of %s, got %s.', Trip::class, get_debug_type($data)));
        }

        /** @var list<Stage> $stages */
        $stages = $context['trip_stages'] ?? $this->tripStateManager->getStages($data->id) ?? [];

        $points = [];
        $waypoints = [];

        foreach ($stages as $stage) {
            $stagePoints = array_map(
                static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
            );
            array_push($points, ...$stagePoints);

            foreach ($stage->pois as $poi) {
                $waypoints[] = [
                    'lat' => $poi->lat,
                    'lon' => $poi->lon,
                    'name' => $poi->name,
                    'type' => $poi->category,
                ];
            }

            foreach ($stage->accommodations as $accommodation) {
                $waypoints[] = [
                    'lat' => $accommodation->lat,
                    'lon' => $accommodation->lon,
                    'name' => $accommodation->name,
                    'type' => $accommodation->type,
                ];
            }
        }

        return [
            'courseName' => $this->tripStateManager->getTitle($data->id) ?? $data->id,
            'points' => $points,
            'waypoints' => $waypoints,
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Trip && 'fit' === $format;
    }

    /**
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [Trip::class => 'fit' === $format];
    }
}
