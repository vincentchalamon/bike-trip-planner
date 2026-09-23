<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Stage;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO and persistent entity for {@see Trip}.
 *
 * Serves dual purpose: API Platform input (validation constraints) and Doctrine entity (ORM mapping).
 * Persistence-only fields (id, title, sourceType, locale, timestamps, stages) are excluded from
 * the API schema via #[ApiProperty(writable: false, readable: false)].
 */
#[ORM\Entity]
#[ORM\Table(name: 'trip')]
// The shape of the trip-list query: filter on the owner, order by creation descending
// ({@see \App\State\TripCollectionProvider}). It subsumes the single-column index on user_id
// that preceded it. Declared here and not only in the SQL baseline, so the entity says what
// the code relies on — the correction ADR-077's unit already made for uniq_trip_share_active.
// The descending order the migration declares cannot be expressed here; the attribute names
// the columns, the migration is where the shape lives.
#[ORM\Index(name: 'idx_trip_user_created_at', columns: ['user_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
final class TripRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ApiProperty(readable: false, writable: false)]
    public ?Uuid $id;

    #[ORM\Column(length: 2048, nullable: true)]
    #[Assert\NotBlank(groups: ['trip_request:create'])]
    #[Assert\Url(protocols: ['https'])]
    public ?string $sourceUrl = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $startDate = null {
        set(?\DateTimeImmutable $value) {
            $this->startDate = self::normalizeDate($value);
        }
    }

    // Number of days: endDate - startDate + 1
    // If endDate omitted, default from distance (ceil(distance/80))
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThan(propertyPath: 'startDate', message: 'End date must be after start date.')]
    public ?\DateTimeImmutable $endDate = null {
        set(?\DateTimeImmutable $value) {
            $this->endDate = self::normalizeDate($value);
        }
    }

    // Fatigue factor (0.9 = -10%/day), configurable by the user
    #[ORM\Column]
    #[Assert\Range(min: 0.5, max: 1.0)]
    public float $fatigueFactor = 0.9;

    // Elevation penalty (50 = -1km par 50m D+), configurable by the user
    #[ORM\Column]
    #[Assert\Positive]
    public float $elevationPenalty = 50.0;

    #[ORM\Column]
    public bool $ebikeMode = false;

    #[ORM\Column]
    #[ApiProperty(description: 'Typical departure hour (0-23, default 8)')]
    #[Assert\Range(min: 0, max: 23)]
    public int $departureHour = 8;

    // Maximum distance per day cap (km), applied after pacing formula
    #[ORM\Column]
    #[ApiProperty(description: 'Maximum distance cap per day in km (default: 80)')]
    #[Assert\Range(min: 30, max: 300)]
    public float $maxDistancePerDay = 80.0;

    // Average cycling speed (km/h), reserved for travel time estimation (Sprint 5, issue #61)
    #[ORM\Column]
    #[ApiProperty(description: 'Average cycling speed in km/h (default: 15)')]
    #[Assert\Range(min: 5, max: 50)]
    public float $averageSpeed = 15.0;

    /**
     * Single source of truth for the searchable accommodation vocabulary, and
     * the list every new trip starts with — there is no opt-in type any more.
     *
     * `shelter`, `motel` and `rental` were removed (#927): `amenity=shelter` is
     * 76% street furniture (mostly bus shelters, see docs/audit/878-hebergements-osm-sans-nom.md),
     * `tourism=motel` is empty in France (11 rows over two regions, 0 in
     * DataTourisme), and the meublé market is let by the week. `shelter` rows are
     * still imported, but for the in-ride "where can I take cover" intent only —
     * {@see \App\Osm\AccommodationRepository} excludes them from lodging results.
     *
     * @var list<string>
     */
    public const array ALL_ACCOMMODATION_TYPES = ['camp_site', 'hostel', 'alpine_hut', 'chalet', 'guest_house', 'hotel', 'wilderness_hut'];

    /**
     * Enabled accommodation types for the reference-index search.
     * Defaults to the whole vocabulary; at least one type must remain enabled.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'text[]')]
    #[ApiProperty(description: 'Enabled accommodation types for search (default: every type)')]
    #[Assert\Count(min: 1, minMessage: 'At least one accommodation type must be enabled.')]
    #[Assert\All([
        new Assert\Choice(choices: self::ALL_ACCOMMODATION_TYPES),
    ])]
    public array $enabledAccommodationTypes = self::ALL_ACCOMMODATION_TYPES;

    // --- Persistence-only fields (not exposed in API input/output) ---

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[ApiProperty(readable: false)]
    public ?string $title = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[ApiProperty(readable: false, writable: false)]
    public ?string $sourceType = null;

    /**
     * Structural-readiness status (ADR-043): `draft` until the pacing stages are persisted,
     * then `ready`. Independent of the asynchronous enrichment completion gate.
     */
    #[ORM\Column(length: 20, options: ['default' => 'draft'])]
    #[ApiProperty(readable: false, writable: false)]
    public string $status = 'draft';

    #[ORM\Column(length: 5)]
    #[ApiProperty(readable: false, writable: false)]
    public string $locale = 'en';

    /**
     * Monotonic counter of structural writes to this trip, bumped on every write of the
     * stage collection and on every settings change that invalidates in-flight work.
     *
     * Replaces the Redis-held generation counter, which was a non-atomic get/+1/set with
     * a 30-minute TTL: past that TTL the value vanished, the staleness guard stopped
     * rejecting anything (a null current generation is treated as "not stale"), and the
     * counter restarted from 1 — it could go backwards. A column bumped inside the write
     * transaction is atomic and monotonic by construction (#252, RC1 and RC5).
     *
     * Deliberately not `#[ORM\Version]`: Doctrine does not bump a parent's version when a
     * child changes, and what changes here is the Stage rows.
     */
    #[ORM\Column(options: ['default' => 1])]
    #[ApiProperty(readable: false, writable: false)]
    public int $version = 1;

    /**
     * Mirror of the enrichment status map, written as each computation settles (ADR-072).
     *
     * The live map lives in Redis under a 30-minute TTL, which is shorter than the life of a
     * trip: past it the state did not merely go cold, it ceased to exist anywhere, and both
     * read paths fell back to "there are stages, so it must be analysed" — reporting success
     * for a trip whose every computation had failed.
     *
     * The same reasoning that moved {@see self::$version} out of Redis, for the same reason.
     * Redis stays the hot path; this is what answers once it is gone.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: 'jsonb', options: ['default' => '{}'])]
    #[ApiProperty(readable: false, writable: false)]
    public array $computationStatus = [];

    /**
     * True when the route falls (even partly) outside the provisioned coverage
     * area: the trip is display-only (no Valhalla rerouting).
     *
     * Persisted at stage-store time (issue #775) from the expensive PostGIS
     * {@see \App\Osm\CoverageRepositoryInterface::isRouteOutOfZone()} query so
     * {@see \App\State\TripDetailProvider} reads it in O(1) instead of recomputing
     * it on every trip reload.
     */
    #[ORM\Column(options: ['default' => false])]
    #[ApiProperty(readable: false, writable: false)]
    public bool $outOfZone = false;

    #[ORM\Column]
    #[ApiProperty(readable: false, writable: false)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[ApiProperty(readable: false, writable: false)]
    public \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'trips')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[ApiProperty(readable: false, writable: false)]
    public ?User $user = null;

    /** @var Collection<int, Stage> */
    #[ORM\OneToMany(targetEntity: Stage::class, mappedBy: 'trip', cascade: ['persist', 'remove'], orphanRemoval: true)]
    // `\SortDirection` is the global enum PHP ships natively in 8.6; on 8.5 it
    // comes from symfony/polyfill-php86, required explicitly in composer.json
    // because we name the class ourselves rather than inheriting it from
    // doctrine/orm. Doctrine's own deprecation asks for this enum by name —
    // Doctrine\Common\Collections\Order is a different thing, used by the
    // Collections criteria API, not by #[ORM\OrderBy].
    #[ORM\OrderBy(['position' => \SortDirection::Ascending])]
    #[ApiProperty(readable: false, writable: false)]
    public Collection $stages;

    public function __construct(?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->stages = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function normalizeDate(?\DateTimeImmutable $value): ?\DateTimeImmutable
    {
        if (!$value instanceof \DateTimeImmutable) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value->format('Y-m-d'), new \DateTimeZone('UTC'));
        } catch (\Error $error) {
            // Symfony's var-exporter DeepCloner (test array cache) reconstructs the
            // value in place and runs this set hook before the clone is initialized,
            // so format() throws. The reference is already a normalized value from
            // an earlier set; return it as-is and let reconstruction finish it.
            // Narrowed to that specific error so any other Error still fails loudly.
            if (!str_contains($error->getMessage(), 'has not been correctly initialized')) {
                throw $error;
            }

            return $value;
        }
    }

    public function addStage(Stage $stage): void
    {
        if (!$this->stages->contains($stage)) {
            $this->stages->add($stage);
        }
    }

    public function removeStage(Stage $stage): void
    {
        $this->stages->removeElement($stage);
    }

    public function clearStages(): void
    {
        $this->stages->clear();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
