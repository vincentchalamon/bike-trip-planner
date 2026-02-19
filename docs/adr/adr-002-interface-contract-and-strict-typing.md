# ADR-002: Interface Contract and Strict Typing (Single Source of Truth)

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

Following the decision to adopt a Decoupled API-First Architecture using API Platform (PHP 8.5) and Next.js 16 (
ADR-001), the application faces a critical data integrity challenge. The backend acts as a stateless computational
engine that receives raw inputs (e.g., Komoot URLs) and returns a complex, deeply nested JSON structure representing the
generated bikepacking trip (stages, geospatial coordinates, weather alerts, etc.).

Because Bike Trip Planner is a "local-first" application without a centralized database, this JSON structure is serialized into
the browser's `localStorage` (via Zustand) and serves as the absolute state of the application.

If the backend alters the JSON structure (e.g., renaming `distance` to `distanceInMeters`) and the frontend TypeScript
interfaces are not updated simultaneously, the application will silently fail, corrupt the user's local saves, and break
the UI. We must establish a **Single Source of Truth** for the data contract and eliminate manual TypeScript interface
duplication.

### Architectural Requirements

| Requirement              | Description                                                                                                |
|--------------------------|------------------------------------------------------------------------------------------------------------|
| Single Source of Truth   | The data schema must be defined in one place and automatically propagated to both stacks.                  |
| End-to-End Type Safety   | The Next.js frontend must refuse to compile if it expects a field that the PHP backend no longer provides. |
| Zero Runtime Overhead    | The typing solution should not bloat the frontend bundle size with heavy validation libraries if possible. |
| App Router Compatibility | The fetching mechanism must support Next.js 16's native `fetch` API for caching and Server Actions.        |

---

## Decision Drivers

* **Prevention of Data Drift** — Ensuring the API documentation and actual code are never out of sync.
* **Developer Experience (DX)** — Eliminating the tedious and error-prone process of writing TypeScript interfaces by
  hand.
* **Framework Synergy** — API Platform natively outputs OpenAPI v3 specifications, which should be leveraged as the
  ultimate integration contract.

---

## Considered Options

### Option A: Manual TypeScript Interfaces

Manually writing and maintaining TypeScript `interface` files in the Next.js project to match the PHP Data Transfer
Objects (DTOs).

### Option B: API Platform Client Generator (`@api-platform/client-generator`)

Using the official API Platform tool to scaffold React/Next.js components and interfaces directly from the Hydra/OpenAPI
documentation.

### Option C: `openapi-typescript` combined with `openapi-fetch` (Chosen)

Using the `openapi-typescript` CLI to generate pure, zero-dependency TypeScript definitions from API Platform's OpenAPI
endpoint, and utilizing `openapi-fetch` as a type-safe wrapper around the native browser/Node `fetch` API.

---

## Decision Outcome

**Chosen: Option C (`openapi-typescript` + `openapi-fetch`)**

### Why Other Options Were Rejected

**Option A (Manual Interfaces) rejected:**

* Highly prone to human error.
* Guarantees "documentation drift" and silent runtime failures when the backend evolves.

**Option B (`@api-platform/client-generator`) rejected:**

* While excellent for standard CRUD applications and admin panels, Bike Trip Planner's UI is highly custom (interactive maps,
  custom charts). The generated scaffolding is too opinionated and heavy for a Zustand-driven, local-first SPA.

### Why Option C was Chosen:

* **Zero Runtime Bloat:** `openapi-typescript` transforms OpenAPI schemas into TypeScript ASTs (Abstract Syntax Trees)
  without adding a single byte to the JS bundle.
* **Perfect Match for Next.js 16:** `openapi-fetch` is a lightweight (6kB) library that wraps the native `fetch` API.
  This means it perfectly respects Next.js 16's App Router cache semantics and Server Actions.
* **Absolute Type Safety:** `openapi-fetch` infers request bodies, query parameters, and response shapes directly from
  the generated paths, making invalid API calls impossible to compile while exposing the exact shapes to VS Code
  Intellisense.

---

## Implementation Strategy

The implementation relies on the backend defining the schema strictly via PHP 8.5 features, and the frontend generating
its typings automatically during the build/dev process.

### 2.1 — Backend: Defining the Single Source of Truth

The PHP backend defines the absolute structure of the data using typed properties and API Platform attributes. API
Platform automatically compiles this into an OpenAPI v3 specification exposed at `/docs.json`.

**File:** `api/src/ApiResource/TripResponse.php`

```php
namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

final class TripResponse
{
    public function __construct(
        #[ApiProperty(description: 'The unique identifier of the generated trip.')]
        public readonly string $id,

        #[ApiProperty(description: 'Total distance in kilometers.')]
        #[Assert\PositiveOrZero]
        public readonly float $totalDistance,

        /** @var array<int, Stage> */
        #[ApiProperty(description: 'Ordered list of daily stages.')]
        public readonly array $stages,
    ) {}
}

```

### 2.2 — Frontend: Generating the Typings

We install `openapi-fetch` as a dependency and `openapi-typescript` alongside `typescript` as dev dependencies in the
Next.js workspace.

```bash
npm i openapi-fetch
npm i -D openapi-typescript typescript

```

We configure a script in `package.json` to fetch the OpenAPI spec from the local API Platform container and generate the
definitions.

**File:** `pwa/package.json`

```json
{
  "scripts": {
    "dev": "next dev",
    "typegen": "npx openapi-typescript https://localhost/docs.json -o ./src/lib/api/schema.d.ts",
    "test:ts": "tsc --noEmit",
    "lint": "next lint"
  }
}

```

### 2.3 — Frontend: The Type-Safe Client

We instantiate the `openapi-fetch` client using the generated definitions.

**File:** `pwa/src/lib/api/client.ts`

```typescript
import createClient from 'openapi-fetch';
import type {paths} from './schema'; // Auto-generated by openapi-typescript

export const apiClient = createClient<paths>({
    baseUrl: process.env.NEXT_PUBLIC_API_URL || 'https://localhost',
});

```

### 2.4 — Frontend: Consuming the API Securely

When the user requests a trip generation, the Next.js frontend calls the API. TypeScript will enforce the request body
and automatically infer the response shape without the need for manual type casting or generic assertions.

**File:** `pwa/src/store/useTripStore.ts`

```typescript
import {create} from 'zustand';
import {persist} from 'zustand/middleware';
import {apiClient} from '@/lib/api/client';

// Extract the exact response type from the OpenAPI schema paths
type GenerateTripResponse =
    paths['/generate-trip']['post']['responses']['201']['content']['application/ld+json'];

interface TripState {
    trip: GenerateTripResponse | null;
    isLoading: boolean;
    generateTrip: (komootUrl: string) => Promise<void>;
}

export const useTripStore = create<TripState>()(
    persist(
        (set) => ({
            trip: null,
            isLoading: false,
            generateTrip: async (komootUrl: string) => {
                set({isLoading: true});

                // This call is 100% type-safe. 
                // TS will throw an error if `komootUrl` is not the expected property.
                const {data, error} = await apiClient.POST('/generate-trip', {
                    body: {
                        komootUrl,
                    },
                });

                if (error) {
                    set({isLoading: false});
                    throw new Error('Trip generation failed');
                }

                // `data` is strongly typed as GenerateTripResponse
                set({trip: data, isLoading: false});
            },
        }),
        {
            name: 'Bike Trip Planner-storage',
        }
    )
);

```

---

## Verification

1. **Backend Modification:** Rename `$totalDistance` to `$distanceKm` in the PHP `TripResponse` DTO.
2. **Schema Regeneration:** Run `npm run typegen` in the frontend directory.
3. **TypeScript Failure:** Run `npm run test:ts`. The frontend build must strictly fail because `useTripStore.ts` or UI
   components are trying to access `.totalDistance` which no longer exists in the generated `schema.d.ts`.
4. **Resolution:** The developer is forced to update the frontend code to use `.distanceKm`, preventing a runtime crash
   in production.

---

## Consequences

### Positive

* **Impenetrable Type Safety:** The contract between PHP and TypeScript is mathematically guaranteed by the OpenAPI
  specification.
* **Incredible DX:** Frontend development is drastically accelerated by VS Code Intellisense autocomplete for deep API
  objects.
* **Refactoring Confidence:** A backend developer can rename or deprecate fields and instantly see the impact on the
  frontend simply by running the `typegen` script.

### Negative

* **Workflow Dependency:** Frontend developers must have a running backend instance (or access to the latest
  `docs.json`) to update their types after a backend change.
* **Strictness:** Quick, "hacky" JSON modifications are impossible without updating the PHP DTOs first, slowing down
  unstructured prototyping.

### Neutral

* The frontend repository contains a generated `schema.d.ts` file that can be quite large. It should be committed to
  version control to allow frontend CI pipelines to run without needing to boot the backend container.

---

## Sources

* [OpenAPI-TypeScript Documentation](https://openapi-ts.dev/)
* [openapi-fetch GitHub Repository](https://github.com/openapi-ts/openapi-typescript/tree/main/packages/openapi-fetch)
* [API Platform Documentation: OpenAPI Specification](https://api-platform.com/docs/core/openapi/)
* [Next.js 16 App Router Data Fetching](https://nextjs.org/docs/app/building-your-application/data-fetching/fetching)
