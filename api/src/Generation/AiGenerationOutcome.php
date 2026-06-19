<?php

declare(strict_types=1);

namespace App\Generation;

/**
 * Outcome of an AI route-generation attempt (B1, ADR-042). Only SUCCESS yields a
 * routable geometry; the others are surfaced to the rider as a clarification or
 * a validation error rather than a hard failure.
 */
enum AiGenerationOutcome: string
{
    case SUCCESS = 'success';
    /** The model could not produce a parseable spec. */
    case UNPARSEABLE = 'unparseable';
    /** The brief targets a place outside the supported coverage area (France + Benelux). */
    case OUT_OF_ZONE = 'out_of_zone';
    /** One or more named places could not be geocoded inside the coverage area. */
    case UNGEOCODABLE = 'ungeocodable';
    /** Geocoding succeeded but routing (Valhalla) failed. */
    case ROUTING_FAILED = 'routing_failed';
}
