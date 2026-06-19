<?php

declare(strict_types=1);

namespace App\Generation;

use App\ApiResource\Model\Coordinate;

/**
 * Result of {@see AiTripGenerationService::generate()}: the routed geometry on
 * success, or an outcome + human-readable message to surface as a clarification
 * / validation error.
 */
final readonly class AiGeneratedRoute
{
    /**
     * @param array<string, mixed> $spec        the parsed LLM spec (empty when unparseable)
     * @param list<Coordinate>     $coordinates the routed geometry (empty unless SUCCESS)
     */
    private function __construct(
        public AiGenerationOutcome $outcome,
        public string $message,
        public array $spec = [],
        public array $coordinates = [],
        public float $distanceKm = 0.0,
    ) {
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<Coordinate>     $coordinates
     */
    public static function success(array $spec, array $coordinates, float $distanceKm): self
    {
        return new self(AiGenerationOutcome::SUCCESS, '', $spec, $coordinates, $distanceKm);
    }

    public static function unparseable(): self
    {
        return new self(AiGenerationOutcome::UNPARSEABLE, "L'assistant n'a pas pu proposer d'itinéraire exploitable. Reformulez votre demande.");
    }

    public static function outOfZone(string $message): self
    {
        return new self(AiGenerationOutcome::OUT_OF_ZONE, '' !== $message ? $message : 'La génération d\'itinéraire est limitée à la France et au Benelux.');
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function ungeocodable(array $spec, string $message): self
    {
        return new self(AiGenerationOutcome::UNGEOCODABLE, $message, $spec);
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function routingFailed(array $spec): self
    {
        return new self(AiGenerationOutcome::ROUTING_FAILED, 'Le calcul de l\'itinéraire a échoué. Réessayez dans un instant.', $spec);
    }

    public function isSuccess(): bool
    {
        return AiGenerationOutcome::SUCCESS === $this->outcome;
    }
}
