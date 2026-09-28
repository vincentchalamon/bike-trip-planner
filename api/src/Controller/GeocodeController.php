<?php

declare(strict_types=1);

namespace App\Controller;

use App\Geo\NominatimPlaces;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class GeocodeController
{
    public function __construct(
        private NominatimPlaces $places,
    ) {
    }

    #[Route('/geocode/reverse', methods: ['GET'])]
    public function reverse(Request $request): JsonResponse
    {
        try {
            return new JsonResponse(['results' => $this->places->reverse(...$this->coordinates($request))]);
        } catch (HttpExceptionInterface $httpException) {
            return ProblemResponse::fromException($httpException);
        }
    }

    /**
     * @return array{float, float}
     */
    private function coordinates(Request $request): array
    {
        $lat = $request->query->get('lat');
        $lon = $request->query->get('lon');

        if (null === $lat || '' === $lat || null === $lon || '' === $lon) {
            throw new BadRequestHttpException('Missing required parameters: lat, lon');
        }

        // `(float) 'abc'` is 0.0: without this, garbage became a lookup of the Gulf of Guinea.
        if (!\is_numeric($lat) || !\is_numeric($lon) || \abs((float) $lat) > 90 || \abs((float) $lon) > 180) {
            throw new UnprocessableEntityHttpException('lat must be a number within [-90, 90] and lon within [-180, 180]');
        }

        return [(float) $lat, (float) $lon];
    }
}
