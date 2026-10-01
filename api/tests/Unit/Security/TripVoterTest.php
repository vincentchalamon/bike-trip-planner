<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use PHPUnit\Framework\MockObject\Stub;
use App\ApiResource\TripRequest;
use App\Entity\User;
use App\Security\Voter\TripVoter;
use App\Repository\OwnedTripFinderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

final class TripVoterTest extends TestCase
{
    /** @var OwnedTripFinderInterface&Stub */
    private OwnedTripFinderInterface $trips;

    private TripVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->trips = $this->createStub(OwnedTripFinderInterface::class);
        $this->voter = new TripVoter($this->trips);
    }

    #[Test]
    public function abstainWhenAttributeNotSupported(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $subject = new TripRequest();

        $result = $this->voter->vote($token, $subject, ['UNSUPPORTED_ATTR']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function denyWhenUserIsNotAuthenticated(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $subject = new TripRequest();
        $subject->id = Uuid::fromString('01936f6e-0000-7000-8000-000000000001');

        $result = $this->voter->vote($token, $subject, [TripVoter::TRIP_VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function denyWhenSubjectIsEmptyString(): void
    {
        $user = new User('test@example.com');
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, '', [TripVoter::TRIP_VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function grantWhenOwnerFoundInDatabase(): void
    {
        $userId = Uuid::v7();
        $user = new User('owner@example.com', $userId);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tripId = '01936f6e-0000-7000-8000-000000000001';
        $subject = new TripRequest();
        $subject->id = Uuid::fromString($tripId);

        $this->mockDatabaseOwnershipCheck(true);

        $result = $this->voter->vote($token, $subject, [TripVoter::TRIP_EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function denyWhenNotFoundInDatabase(): void
    {
        $user = new User('stranger@example.com');
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $subject = new TripRequest();
        $subject->id = Uuid::fromString('01936f6e-0000-7000-8000-000000000003');

        $this->mockDatabaseOwnershipCheck(false);

        $result = $this->voter->vote($token, $subject, [TripVoter::TRIP_VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function grantWhenOwnerFoundInDatabaseViaStringSubject(): void
    {
        $userId = Uuid::v7();
        $user = new User('owner@example.com', $userId);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tripId = '01936f6e-0000-7000-8000-000000000004';

        $this->mockDatabaseOwnershipCheck(true);

        // Stage operations pass the tripId as a plain string, not a TripRequest
        $result = $this->voter->vote($token, $tripId, [TripVoter::TRIP_VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    private function mockDatabaseOwnershipCheck(bool $owned): void
    {
        $this->trips->method('isOwnedBy')->willReturn($owned);
    }
}
