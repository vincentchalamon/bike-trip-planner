<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\ApiResource\TripDetail;
use App\ApiResource\TripRequest;
use App\Repository\TripShareRepository;
use App\State\TripShareCreateProcessor;
use App\State\TripShareViewProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TripShareRepository::class)]
#[ORM\Table(name: 'trip_share')]
#[ORM\UniqueConstraint(name: 'uniq_trip_share_token', columns: ['token'])]
#[ORM\Index(name: 'idx_trip_share_trip', columns: ['trip_id'])]
#[ApiResource(
    shortName: 'TripShare',
    operations: [
        new Post(
            uriTemplate: '/trips/{tripId}/shares',
            uriVariables: [
                'tripId' => new Link(toProperty: 'trip', fromClass: TripRequest::class),
            ],
            status: 201,
            openapi: new Operation(summary: 'Create a read-only share link for a trip.'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
            processor: TripShareCreateProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/trips/{tripId}/shares',
            uriVariables: [
                'tripId' => new Link(toProperty: 'trip', fromClass: TripRequest::class),
            ],
            openapi: new Operation(summary: 'List all share links for a trip.'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
        ),
        new Delete(
            uriTemplate: '/trips/{tripId}/shares/{id}',
            uriVariables: [
                'tripId' => new Link(toProperty: 'trip', fromClass: TripRequest::class),
                'id' => new Link(fromClass: TripShare::class),
            ],
            openapi: new Operation(summary: 'Revoke a share link.'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
        ),
        new Get(
            uriTemplate: '/shares/{tripId}',
            uriVariables: [
                'tripId' => new Link(fromClass: TripShare::class),
            ],
            openapi: new Operation(
                summary: 'View a shared trip (read-only, anonymous access).',
                parameters: [
                    new Parameter(
                        name: 'token',
                        in: 'query',
                        description: 'Share token (64 hex characters)',
                        required: true,
                        schema: ['type' => 'string'],
                    ),
                ],
            ),
            security: 'is_granted("PUBLIC_ACCESS")',
            output: TripDetail::class,
            provider: TripShareViewProvider::class,
        ),
    ],
)]
class TripShare
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ApiProperty(writable: false)]
    private Uuid $id;

    #[ORM\Column]
    #[ApiProperty(writable: false)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TripRequest::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ApiProperty(readable: false, writable: false)]
        private ?TripRequest $trip = null,
        #[ORM\Column(length: 64)]
        #[ApiProperty(writable: false)]
        private string $token = '',
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $expiresAt = null,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function generateToken(): void
    {
        $this->token = bin2hex(random_bytes(32));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTrip(): TripRequest
    {
        return $this->trip;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isValid(): bool
    {
        return !$this->expiresAt instanceof \DateTimeImmutable || $this->expiresAt > new \DateTimeImmutable();
    }
}
