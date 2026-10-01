<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\ApiResource\TripRequest;
use App\Entity\TripShare;
use App\Repository\TripShareRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * A revoked share is soft-deleted, not removed: both lookups must step over it.
 */
#[CoversClass(TripShareRepository::class)]
#[ResetDatabase]
final class TripShareRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private TripShareRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->em = $em;

        /** @var TripShareRepository $repository */
        $repository = $container->get(TripShareRepository::class);
        $this->repository = $repository;
    }

    #[Test]
    public function theActiveShareOfATripIsFoundAndARevokedOneIsNot(): void
    {
        $trip = $this->trip();
        $otherTrip = $this->trip();
        $revoked = $this->share($trip, revoked: true);
        $active = $this->share($trip);
        $this->share($otherTrip);

        $found = $this->repository->findActiveByTrip((string) $trip->id);

        self::assertInstanceOf(TripShare::class, $found);
        self::assertTrue($found->getId()->equals($active->getId()));
        self::assertFalse($found->getId()->equals($revoked->getId()));
    }

    #[Test]
    public function aTripWithOnlyARevokedShareHasNoActiveOne(): void
    {
        $trip = $this->trip();
        $this->share($trip, revoked: true);

        self::assertNull($this->repository->findActiveByTrip((string) $trip->id));
        self::assertNull($this->repository->findActiveByTrip(Uuid::v7()->toRfc4122()));
    }

    #[Test]
    public function aShortCodeResolvesOnlyWhileTheShareIsActive(): void
    {
        $active = $this->share($this->trip());
        $revoked = $this->share($this->trip(), revoked: true);

        self::assertTrue($this->repository->findByShortCode($active->getShortCode())?->getId()->equals($active->getId()));
        self::assertNull($this->repository->findByShortCode($revoked->getShortCode()));
        self::assertNull($this->repository->findByShortCode('missing0'));
    }

    private function trip(): TripRequest
    {
        $trip = new TripRequest();
        $this->em->persist($trip);
        $this->em->flush();

        return $trip;
    }

    private function share(TripRequest $trip, bool $revoked = false): TripShare
    {
        $share = new TripShare($trip);
        $share->generateToken();
        if ($revoked) {
            $share->softDelete();
        }

        $this->em->persist($share);
        $this->em->flush();

        return $share;
    }
}
