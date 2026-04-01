<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Stage;
use App\ApiResource\Trip;
use App\ApiResource\TripDetail;
use App\ApiResource\TripRequest;
use App\Repository\TripShareRepository;
use App\State\TripShareCreateProcessor;
use App\State\TripShareDeleteProcessor;
use App\State\TripShareGpxProvider;
use App\State\TripShareProvider;
use App\State\TripShareShortCodeProvider;
use App\State\TripShareStageProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TripShareRepository::class)]
#[ORM\Table(name: 'trip_share')]
#[ORM\UniqueConstraint(name: 'uniq_trip_share_token', columns: ['token'])]
#[ORM\UniqueConstraint(name: 'uniq_trip_share_short_code', columns: ['short_code'])]
#[ORM\Index(name: 'idx_trip_share_trip', columns: ['trip_id'])]
#[ApiResource(
    shortName: 'TripShare',
    operations: [
        // --- Owner endpoints (authenticated) ---
        new Post(
            uriTemplate: '/trips/{tripId}/share',
            uriVariables: [
                'tripId' => new Link(toProperty: 'trip', fromClass: TripRequest::class),
            ],
            status: 201,
            openapi: new Operation(summary: 'Create a read-only share link for a trip.'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
            processor: TripShareCreateProcessor::class,
        ),
        new Get(
            uriTemplate: '/trips/{tripId}/share',
            uriVariables: [
                'tripId' => new Link(toProperty: 'trip', fromClass: TripRequest::class),
            ],
            openapi: new Operation(summary: 'Get the active share link for a trip.'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
            provider: TripShareProvider::class,
        ),
        new Delete(
            uriTemplate: '/trips/{tripId}/share',
            uriVariables: [
                'tripId' => new Link(toProperty: 'trip', fromClass: TripRequest::class),
            ],
            openapi: new Operation(summary: 'Revoke the active share link.'),
            security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))",
            provider: TripShareProvider::class,
            processor: TripShareDeleteProcessor::class,
        ),
        // --- Public short-code endpoints (anonymous, token-free) ---
        new Get(
            uriTemplate: '/s/{shortCode}',
            uriVariables: ['shortCode' => new Link(fromClass: TripShare::class)],
            openapi: new Operation(summary: 'View a shared trip via short code (anonymous).'),
            security: 'is_granted("PUBLIC_ACCESS")',
            output: TripDetail::class,
            provider: TripShareShortCodeProvider::class,
        ),
        new Get(
            uriTemplate: '/s/{shortCode}.gpx',
            outputFormats: ['gpx' => ['application/gpx+xml']],
            uriVariables: ['shortCode' => new Link(fromClass: TripShare::class)],
            openapi: new Operation(summary: 'Download shared trip as GPX via short code.'),
            security: 'is_granted("PUBLIC_ACCESS")',
            output: Trip::class,
            provider: TripShareGpxProvider::class,
        ),
        new Get(
            uriTemplate: '/s/{shortCode}/stages/{index}{._format}',
            outputFormats: [
                'gpx' => ['application/gpx+xml'],
                'fit' => ['application/vnd.ant.fit'],
            ],
            uriVariables: [
                'shortCode' => new Link(fromClass: TripShare::class),
                'index' => new Link(toProperty: 'dayNumber', fromClass: Stage::class),
            ],
            openapi: new Operation(summary: 'Download shared stage as GPX or FIT via short code.'),
            security: 'is_granted("PUBLIC_ACCESS")',
            output: Stage::class,
            provider: TripShareStageProvider::class,
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

    #[ORM\Column(nullable: true)]
    #[ApiProperty(readable: false, writable: false)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: TripRequest::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[ApiProperty(readable: false, writable: false)]
        private ?TripRequest $trip = null,
        #[ORM\Column(length: 64)]
        #[ApiProperty(writable: false)]
        private string $token = '',
        #[ORM\Column(length: 8)]
        #[ApiProperty(writable: false)]
        private string $shortCode = '',
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function generateToken(): void
    {
        $this->token = bin2hex(random_bytes(32));
        $this->shortCode = substr(strtr(base64_encode(random_bytes(6)), '+/', '-_'), 0, 8);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function setTrip(TripRequest $trip): void
    {
        $this->trip = $trip;
    }

    public function getTrip(): ?TripRequest
    {
        return $this->trip;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getShortCode(): string
    {
        return $this->shortCode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function softDelete(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return !$this->deletedAt instanceof \DateTimeImmutable;
    }
}
