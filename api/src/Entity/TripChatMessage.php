<?php

declare(strict_types=1);

namespace App\Entity;

use App\ApiResource\TripRequest;
use App\Repository\TripChatMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Long-term persistence of a single chat turn (user message or assistant reply)
 * stored alongside the volatile Redis context window.
 *
 * Redis still keeps the last {@see \App\Llm\ChatHistoryStore::MAX_MESSAGES} turns
 * for LLM prompt context, but this entity allows the rider to recover their full
 * conversation history when reloading the trip page (in-ride consultation —
 * cf. sprint 32 in `TRACKING.md`).
 *
 * Cascades on `trip_id` and `user_id` ensure GDPR-compliant deletion when a trip
 * or a user is removed.
 */
#[ORM\Entity(repositoryClass: TripChatMessageRepository::class)]
#[ORM\Table(name: 'trip_chat_message')]
#[ORM\Index(name: 'idx_trip_chat_trip_user_created', columns: ['trip_id', 'user_id', 'created_at'])]
#[ORM\Index(name: 'IDX_trip_chat_message_user', columns: ['user_id'])]
class TripChatMessage
{
    public const string ROLE_USER = 'user';

    public const string ROLE_ASSISTANT = 'assistant';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable', options: ['precision' => 6])]
    private \DateTimeImmutable $createdAt;

    /**
     * @param non-empty-string                                                                                                                                                                                               $role one of {@see self::ROLE_USER} or {@see self::ROLE_ASSISTANT}
     * @param list<array{name: string, category: string, lat: float, lon: float, distance_m: int, detour_m: int, opening_hours_today: ?string, closes_at: ?string, phone: ?string, deeplink: string, warning: ?string}>|null $pois optional POI suggestions referenced by the message (JSONB)
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: TripRequest::class)]
        #[ORM\JoinColumn(name: 'trip_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private TripRequest $trip,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 16)]
        private string $role,
        #[ORM\Column(type: 'text')]
        private string $content,
        #[ORM\Column(length: 32, nullable: true)]
        private ?string $action = null,
        #[ORM\Column(name: 'geo_lat', type: 'float', nullable: true)]
        private ?float $geoLat = null,
        #[ORM\Column(name: 'geo_lon', type: 'float', nullable: true)]
        private ?float $geoLon = null,
        #[ORM\Column(name: 'geo_accuracy_m', type: 'float', nullable: true)]
        private ?float $geoAccuracyM = null,
        #[ORM\Column(type: 'jsonb', nullable: true)]
        private ?array $pois = null,
        ?Uuid $id = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTrip(): TripRequest
    {
        return $this->trip;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return non-empty-string
     */
    public function getRole(): string
    {
        return $this->role;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function getGeoLat(): ?float
    {
        return $this->geoLat;
    }

    public function getGeoLon(): ?float
    {
        return $this->geoLon;
    }

    public function getGeoAccuracyM(): ?float
    {
        return $this->geoAccuracyM;
    }

    /**
     * @return list<array{name: string, category: string, lat: float, lon: float, distance_m: int, detour_m: int, opening_hours_today: ?string, closes_at: ?string, phone: ?string, deeplink: string, warning: ?string}>|null
     */
    public function getPois(): ?array
    {
        return $this->pois;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
