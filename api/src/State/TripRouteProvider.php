<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRoute;
use App\Repository\DoctrineTripRequestRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<TripRoute>
 */
final readonly class TripRouteProvider implements ProviderInterface
{
    public function __construct(
        private DoctrineTripRequestRepository $tripStateManager,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripRoute
    {
        $id = \is_string($uriVariables['id'] ?? null) ? $uriVariables['id'] : '';

        $stages = $this->tripStateManager->getStages($id);
        if (null === $stages) {
            throw new NotFoundHttpException(\sprintf('Trip "%s" not found.', $id));
        }

        return new TripRoute(
            id: $id,
            stages: array_map(
                static fn (Stage $stage): array => [
                    'dayNumber' => $stage->dayNumber,
                    'geometry' => array_map(
                        static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
                        $stage->geometry,
                    ),
                ],
                $stages,
            ),
        );
    }
}
