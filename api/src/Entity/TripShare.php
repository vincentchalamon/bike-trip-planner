<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\McpTool;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\ApiResource\Mcp\ShareTripInput;
use App\ApiResource\Mcp\UnshareTripInput;
use App\ApiResource\Stage;
use App\ApiResource\Trip;
use App\ApiResource\TripDetail;
use App\ApiResource\TripRoute;
use App\ApiResource\TripRequest;
use App\Repository\TripShareRepository;
use App\State\Mcp\McpConfirmationProcessor;
use App\State\Mcp\McpShareTripProcessor;
use App\State\Mcp\McpUnshareTripProcessor;
use App\State\TripShareCreateProcessor;
use App\State\TripShareCreateProvider;
use App\State\TripShareDeleteProcessor;
use App\State\TripShareGpxProvider;
use App\State\TripShareProvider;
use App\State\TripShareRouteProvider;
use App\State\TripShareShortCodeProvider;
use App\State\TripShareStageProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use App\ApiResource\Mcp\ChallengeOrAcknowledgement;
use App\ApiResource\Mcp\ShareLink;

#[ORM\Entity(repositoryClass: TripShareRepository::class)]
#[ORM\Table(name: 'trip_share')]
#[ORM\UniqueConstraint(name: 'uniq_trip_share_token', columns: ['token'])]
#[ORM\UniqueConstraint(name: 'uniq_trip_share_short_code', columns: ['short_code'])]
#[ORM\Index(name: 'idx_trip_share_trip', columns: ['trip_id'])]
// At most one active share per trip, and the whole guarantee of TripShareCreateProcessor: the
// pre-check it makes first has a window, the constraint does not. It lived only in the SQL
// baseline, so the entity did not say what the code relies on.
#[ORM\UniqueConstraint(name: 'uniq_trip_share_active', columns: ['trip_id'], options: ['where' => '(deleted_at IS NULL)'])]
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
            security: "is_granted('TRIP_EDIT', tripId)",
            provider: TripShareCreateProvider::class,
            processor: TripShareCreateProcessor::class,
        ),
        new Get(
            uriTemplate: '/trips/{tripId}/share',
            uriVariables: [
                'tripId' => new Link(toProperty: 'trip', fromClass: TripRequest::class),
            ],
            openapi: new Operation(summary: 'Get the active share link for a trip.'),
            security: "is_granted('TRIP_EDIT', tripId)",
            provider: TripShareProvider::class,
        ),
        new Delete(
            uriTemplate: '/trips/{tripId}/share',
            uriVariables: [
                'tripId' => new Link(toProperty: 'trip', fromClass: TripRequest::class),
            ],
            openapi: new Operation(summary: 'Revoke the active share link.'),
            security: "is_granted('TRIP_EDIT', tripId)",
            provider: TripShareProvider::class,
            processor: TripShareDeleteProcessor::class,
        ),
        // --- Public short-code endpoints (anonymous, token-free) ---
        new Get(
            uriTemplate: '/s/{shortCode}',
            uriVariables: ['shortCode' => new Link(fromClass: TripShare::class, identifiers: ['shortCode'])],
            requirements: ['shortCode' => '[A-Za-z0-9_-]+'],
            openapi: new Operation(summary: 'View a shared trip via short code (anonymous).'),
            security: 'is_granted("PUBLIC_ACCESS")',
            output: TripDetail::class,
            provider: TripShareShortCodeProvider::class,
        ),
        new Get(
            uriTemplate: '/s/{shortCode}.gpx',
            outputFormats: ['gpx' => ['application/gpx+xml']],
            uriVariables: ['shortCode' => new Link(fromClass: TripShare::class, identifiers: ['shortCode'])],
            openapi: new Operation(summary: 'Download shared trip as GPX via short code.'),
            security: 'is_granted("PUBLIC_ACCESS")',
            output: Trip::class,
            provider: TripShareGpxProvider::class,
        ),
        new Get(
            uriTemplate: '/s/{shortCode}.fit',
            outputFormats: ['fit' => ['application/vnd.ant.fit']],
            uriVariables: ['shortCode' => new Link(fromClass: TripShare::class, identifiers: ['shortCode'])],
            openapi: new Operation(summary: 'Download shared trip as FIT via short code.'),
            security: 'is_granted("PUBLIC_ACCESS")',
            output: Trip::class,
            provider: TripShareGpxProvider::class, // format-agnostic: also serves .fit
        ),
        new Get(
            uriTemplate: '/s/{shortCode}/route',
            uriVariables: ['shortCode' => new Link(fromClass: TripShare::class, identifiers: ['shortCode'])],
            requirements: ['shortCode' => '[A-Za-z0-9_-]+'],
            openapi: new Operation(
                responses: [
                    304 => new OpenApiResponse(description: 'The geometry has not changed since the ETag you sent.'),
                ],
                summary: 'All-stages geometry for a shared trip (anonymous).',
                parameters: [
                    new Parameter(
                        name: 'If-None-Match',
                        in: 'header',
                        description: 'The ETag of a previously served route, quoted — for example `"7"`. Answered 304 when the geometry has not changed since, which it only does when the stages are regenerated.',
                        required: false,
                        schema: ['type' => 'string', 'pattern' => '^(\*|"\d+")$'],
                    ),
                ],
            ),
            security: 'is_granted("PUBLIC_ACCESS")',
            output: TripRoute::class,
            provider: TripShareRouteProvider::class,
        ),
        new Get(
            uriTemplate: '/s/{shortCode}/stages/{stageId}{._format}',
            outputFormats: [
                'gpx' => ['application/gpx+xml'],
                'fit' => ['application/vnd.ant.fit'],
            ],
            uriVariables: [
                'shortCode' => new Link(fromClass: TripShare::class, identifiers: ['shortCode']),
                'stageId' => new Link(fromClass: Stage::class),
            ],
            openapi: new Operation(summary: 'Download shared stage as GPX or FIT via short code.'),
            security: 'is_granted("PUBLIC_ACCESS")',
            output: Stage::class,
            provider: TripShareStageProvider::class,
        ),
    ],
    mcp: [
        // Sharing is not destructive, so no confirmation: it creates a link, it does not undo
        // anything. What it does do is put an address in the world, which is why the answer is
        // the address rather than the row — see ShareLink.
        'share_trip' => new McpTool(
            name: 'share_trip',
            description: <<<'TEXT'
                Publish a read-only link to a trip and return its address. Anyone holding the
                link can view the trip and download it as GPX or FIT, with no account. Use this
                when the user wants to send their trip to someone, or wants the file itself:
                the download is a link you hand over, not something this tool can carry.
                Calling it twice returns the link that already exists.
                TEXT,
            uriTemplate: '/trips/{tripId}/share',
            uriVariables: ['tripId' => new Link(toProperty: 'trip', fromClass: TripRequest::class)],
            // Ownership by URI variable, never `object`: the object form needs the provider to
            // have run, and a provider reports a missing record by throwing — so an unknown id
            // and someone else's id would stop answering alike (ADR-038 masks that on HTTP,
            // and nothing masks it here).
            security: "is_granted('TRIP_EDIT', tripId)",
            input: ShareTripInput::class,
            output: ShareLink::class,
            // The MCP handler defaults `validate` to false, which would skip every constraint
            // on the input. Nothing on a share carries one today; the declaration is what the
            // CI guard checks, and what keeps that true when one is added.
            validate: true,
            provider: TripShareCreateProvider::class,
            processor: McpShareTripProcessor::class,
            extraProperties: ['mcp_scope' => 'trips:write'],
        ),
        'unshare_trip' => new McpTool(
            name: 'unshare_trip',
            description: <<<'TEXT'
                Revoke a trip's public link. Anyone who kept the address loses access at once.
                Called without `confirmationToken`, this changes nothing: it answers with what
                the link currently is and a token to call back with.
                TEXT,
            annotations: ['destructiveHint' => true],
            uriTemplate: '/trips/{tripId}/share',
            uriVariables: ['tripId' => new Link(toProperty: 'trip', fromClass: TripRequest::class)],
            security: "is_granted('TRIP_EDIT', tripId)",
            input: UnshareTripInput::class,
            output: ChallengeOrAcknowledgement::class,
            validate: true,
            provider: TripShareProvider::class,
            processor: McpUnshareTripProcessor::class,
            extraProperties: [
                'mcp_scope' => 'trips:write',
                McpConfirmationProcessor::EXTRA_PROPERTY => 'Revoke the public link to this trip. Anyone holding the address loses access immediately. The trip itself is untouched, and a new link can be created afterwards — it will have a different address.',
            ],
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
