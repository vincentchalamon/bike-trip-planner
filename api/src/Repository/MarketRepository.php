<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Market;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Market>
 */
final class MarketRepository extends ServiceEntityRepository implements MarketRepositoryInterface
{
    private const float DEGREES_PER_METER = 1.0 / 111_320.0;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Market::class);
    }

    /**
     * @return list<Market>
     */
    public function findNearEndpoint(
        float $lat,
        float $lon,
        int $radiusMeters,
        int $dayOfWeek,
    ): array {
        $radiusDeg = $radiusMeters * self::DEGREES_PER_METER;
        $lonFactor = abs(cos(deg2rad($lat)));
        $lonDeg = 0.0 < $lonFactor ? $radiusDeg / $lonFactor : $radiusDeg;

        $minLat = $lat - $radiusDeg;
        $maxLat = $lat + $radiusDeg;
        $minLon = $lon - $lonDeg;
        $maxLon = $lon + $lonDeg;

        /** @var list<Market> $candidates */
        $candidates = $this->createQueryBuilder('m')
            ->where('m.dayOfWeek = :dayOfWeek')
            ->andWhere('m.lat BETWEEN :minLat AND :maxLat')
            ->andWhere('m.lon BETWEEN :minLon AND :maxLon')
            ->setParameter('dayOfWeek', $dayOfWeek)
            ->setParameter('minLat', $minLat)
            ->setParameter('maxLat', $maxLat)
            ->setParameter('minLon', $minLon)
            ->setParameter('maxLon', $maxLon)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $candidates,
            fn (Market $market): bool => $this->haversineMeters(
                $lat,
                $lon,
                $market->getLat(),
                $market->getLon(),
            ) <= $radiusMeters,
        ));
    }

    public function findByExternalId(string $externalId): ?Market
    {
        return $this->findOneBy(['externalId' => $externalId]);
    }

    public function save(Market $market, bool $flush = false): void
    {
        $this->getEntityManager()->persist($market);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    private function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6_371_000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * asin(sqrt($a));
    }
}
