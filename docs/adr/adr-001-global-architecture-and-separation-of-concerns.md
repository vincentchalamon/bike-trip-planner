# ADR-001: Global Architecture and Separation of Concerns

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

Bike Trip Planner aims to be a "local-first" application that generates bikepacking itineraries without relying on a
persistent cloud database (e.g., MySQL or PostgreSQL) for user accounts or trip history. The application state is
entirely maintained in the user's browser and serialized to/from local JSON files.

Despite having no database, the application requires heavy processing:

1. Parsing and manipulating large GPX files or Komoot URLs.
2. Executing a "Pacing Engine" (a mathematical fatigue calculation algorithm) to chunk routes.
3. Querying external geographical APIs (OpenStreetMap Overpass) and weather APIs securely.

The core architectural problem is defining the strict boundaries between the client (browser) and the server to ensure
high performance, secure API key management, and optimal maintainability. Furthermore, the project demands a **high
level of code quality** (frontend and backend) and must adhere to industry standards for API design to ensure flawless
communication between the frontend and the stateless backend.

### Architectural Requirements

| Requirement      | Description                                                                               |
|------------------|-------------------------------------------------------------------------------------------|
| State Management | Must hold the entire trip state locally (browser) and allow JSON export/import.           |
| Security         | Must securely query OpenWeather and OSM Overpass without exposing API keys in the client. |
| Standardization  | The API must expose a strict, self-documenting contract (OpenAPI) to prevent data drift.  |
| Code Quality     | Strict static analysis and automated formatting must be enforced across both stacks.      |

### Technical Constraints

* **Backend:** PHP 8.5 with a focus on modern API standards.
* **Frontend:** Next.js 16.
* **Hosting/Ops:** Containerized via Docker. Must be bootstrapped from the official `api-platform/api-platform` template
  repository (excluding Helm charts).

---

## Decision Drivers

* **Security** — Never expose external API keys to the client payload.
* **Standardization** — Leverage established hypermedia API standards (JSON-LD, OpenAPI) out of the box instead of
  reinventing the wheel.
* **Quality & DX** — High static analysis levels, automated testing, and strict typings to prevent runtime errors.
* **Separation of Concerns** — Ensure the frontend remains a presentation and state layer, while the backend acts as a
  stateless, strictly typed computational engine.

---

## Considered Options

### Option A: Custom Symfony REST API Monolith

Building a custom REST API from scratch using bare Symfony 8 components (`AbstractController`, `Serializer`,
`Validator`).

### Option B: "All-in-Frontend" Monolith (Next.js Only)

All GPX parsing, spatial calculations, and external API calls are executed directly in the browser or via Next.js Server
Actions.

### Option C: API Platform with Next.js SPA (Chosen)

A strict separation using **API Platform** (built on top of Symfony) for the backend to act as a stateless, standardized
API engine, and a Next.js 16 Single Page Application (SPA) as the state manager and UI renderer.

---

## Decision Outcome

**Chosen: Option C (API Platform with Next.js SPA)**

### Why Other Options Were Rejected

**Option A (Custom Symfony REST API) rejected:**

* Requires manual maintenance of OpenAPI (Swagger) documentation.
* Misses out on API Platform's built-in hypermedia features (Hydra, JSON-LD) and automatic error normalization (RFC
  7807).
* Reinventing serialization and deserialization flows that API Platform handles natively via DTOs and State Providers.

**Option B (Next.js Only) rejected:**

| Criterion   | API Platform (Option C)                                     | Next.js Only (Option B)                                                       |
|-------------|-------------------------------------------------------------|-------------------------------------------------------------------------------|
| Security    | API keys kept in PHP `.env`. Backend handles rate-limiting. | Pushes domain logic and secret management to a boundary closer to the client. |
| Performance | PHP 8.5 JIT handles large GPX arrays efficiently.           | Browser main thread blocked during heavy GPX parsing if done client-side.     |
| Standards   | Natively outputs OpenAPI v3 and JSON-LD.                    | Requires manual setup of Swagger/OpenAPI generators.                          |

---

## Implementation Strategy

The architecture will be implemented by bootstrapping the project using the `api-platform/api-platform` template,
stripping out the Kubernetes/Helm configurations, and adapting it for a stateless (database-less) workflow.

### 1.1 — Docker Environment Bootstrap

We will use the Caddy-based setup provided by the API Platform distribution.

**Action:** Bootstrap from repository and clean up.

```bash
composer create-project api-platform/api-platform bike-trip-planner
cd bike-trip-planner
rm -rf helm/ # Remove Kubernetes charts
```

The resulting `compose.yaml` provides a highly optimized Caddy server for the backend and a Node container
for Next.js, ensuring HTTP/3 and native CORS configuration.

### 1.2 — Backend Implementation (Stateless API Platform)

Since there is no database (Doctrine), we will define `ApiResource` attributes directly on PHP Data Transfer Objects (
DTOs) and use **State Providers** and **State Processors** to handle the business logic.

**Create:** `api/src/ApiResource/TripRequest.php`

```php
namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\TripGenerationProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new Post(
            uriTemplate: '/generate-trip',
            processor: TripGenerationProcessor::class,
            output: TripResponse::class,
            openapiContext: [
                'summary' => 'Generates a bikepacking trip from a Komoot URL or GPX data.',
            ]
        ),
    ]
)]
final class TripRequest
{
    #[Assert\NotBlank]
    #[Assert\Url]
    public string $komootUrl;
}
```

**Create:** `api/src/State/TripGenerationProcessor.php`

```php
namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TripRequest;
use App\ApiResource\TripResponse;
use App\PacingEngine;
use App\OsmScanner;

final readonly class TripGenerationProcessor implements ProcessorInterface
{
    public function __construct(
        private PacingEngine $pacingEngine,
        private OsmScanner $osmScanner
    ) {}

    /**
     * @param TripRequest $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TripResponse
    {
        // 1. Fetch GPX from $data->komootUrl
        // 2. Execute PacingEngine
        // 3. Scan OSM for alerts
        // 4. Return new TripResponse DTO (automatically serialized by API Platform)
        
        return new TripResponse(/* ... */);
    }
}
```

### 1.3 — Frontend Implementation (State Manager)

Next.js will use `Zustand` for state management, persisting the data returned by API Platform to the browser's
`localStorage`.

**File:** `pwa/src/store/useTripStore.ts`

```typescript
import {create} from 'zustand';
import {persist} from 'zustand/middleware';
import type {TripResponse} from '@/types/api';

interface TripState {
    trip: TripResponse | null;
    isLoading: boolean;
    generateTrip: (komootUrl: string) => Promise<void>;
    importFromJson: (jsonData: string) => void;
    exportToJson: () => string;
}

export const useTripStore = create<TripState>()(
    persist(
        (set, get) => ({
            trip: null,
            isLoading: false,
            generateTrip: async (komootUrl: string) => {
                set({isLoading: true});
                try {
                    const response = await fetch(`${process.env.NEXT_PUBLIC_ENTRYPOINT}/generate-trip`, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/ld+json'},
                        body: JSON.stringify({komootUrl})
                    });
                    const tripData = await response.json();
                    set({trip: tripData, isLoading: false});
                } catch (error) {
                    set({isLoading: false});
                    throw error;
                }
            },
            importFromJson: (jsonData) => set({trip: JSON.parse(jsonData)}),
            exportToJson: () => JSON.stringify(get().trip, null, 2),
        }),
        {
            name: 'bike-trip-planner-storage',
        }
    )
);
```

### 1.4 — Code Quality Standards

To ensure zero-ambiguity and prevent regressions, strict quality tools are enforced in both CI/CD and local hooks (e.g.,
Husky).

**Backend Quality Tools:**

* **PHPStan:** Level 9 (maximum strictness) to ensure perfect type safety across all DTOs and Providers.
* **PHP-CS-Fixer:** Configured with `@Symfony` and `@PSR12` rules for uniform formatting.
* **PHPUnit:** Mandatory tests for custom `ProcessorInterface` implementations.

**Frontend Quality Tools:**

* **TypeScript:** `strict: true` in `tsconfig.json`. Types will be generated automatically from API Platform's OpenAPI
  specification using `@api-platform/api-doc-parser`.
* **ESLint & Prettier:** Strict Next.js core web vitals and React recommended rules.
* **Playwright:** End-to-end tests validating the full cycle (Input -> API Call -> Zustand State -> UI Render).

---

## Verification

1. `docker compose up --wait` — successfully boots PHP and Node.js containers.
2. `curl -k https://localhost/docs.json` — returns the OpenAPI specification generated automatically by API Platform.
3. `docker compose exec php vendor/bin/phpstan analyse -l 9 src/` — returns zero errors.
4. `docker compose exec pwa npm run lint` — returns zero ESLint errors.
5. **Manual Test:** Submitting a Komoot URL triggers the custom API Platform `TripGenerationProcessor` and persists the
   standardized JSON-LD response in the browser's local storage.

---

## Consequences

### Positive

* **API Standardization:** The API natively supports JSON-LD, OpenAPI, and RFC 7807 (Problem Details for HTTP APIs),
  making the contract between PHP and Next.js unbreakable.
* **Uncompromised Security:** API keys for OSM and Weather are safely isolated in the PHP backend.
* **DX (Developer Experience):** The `api-platform/api-platform` setup provides a production-ready Docker
  configuration (Caddy) with built-in HTTPS and CORS out of the box.

### Negative

* **Learning Curve:** Using API Platform without Doctrine ORM requires a deep understanding of custom State Providers
  and Processors, which is less conventional than traditional CRUD setups.
* **Overhead:** API Platform includes many features (GraphQL, Mercure) that might be overkill for a simple MVP,
  requiring manual disabling in `api_platform.yaml` to optimize performance.

### Neutral

* The frontend must adapt to consuming JSON-LD formats (e.g., handling `@id` and `@type` keys) if strict hypermedia
  traversal is used.

---

## Sources

* [API Platform Documentation: State Providers & Processors](https://api-platform.com/docs/core/state-providers/)
* [API Platform Documentation: Without Doctrine](https://www.google.com/search?q=https://api-platform.com/docs/core/data-providers/%23custom-state-provider)
* [Caddy: The Ultimate Server](https://caddyserver.com/)
* [Zustand Documentation - Persist Middleware](https://docs.pmnd.rs/zustand/integrations/persisting-store-data)
