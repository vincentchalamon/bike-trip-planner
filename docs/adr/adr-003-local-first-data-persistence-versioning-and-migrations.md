# ADR-003: Local-First Data Persistence, Versioning, and Migrations

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

Bike Trip Planner is a "local-first" application. The absolute state of a user's trip is stored entirely in the browser's
`localStorage` and can be exported as physical `.json` files.

Because we rely on a Decoupled API-First Architecture (ADR-001) with strict OpenAPI typing (ADR-002), any change to the
backend schema (e.g., renaming `$totalDistance` to `$distanceKm`, or adding a mandatory `warnings` array to a `Stage`)
creates a critical breaking change for existing local data.

If a user opens the application with an older version of the trip saved in `localStorage`, or imports an old `.json`
file, the Next.js frontend will attempt to render it using the new TypeScript interfaces. This will result in silent UI
failures, `undefined` runtime errors, and complete application crashes.

We need a robust, offline-capable mechanism to detect the version of a loaded JSON payload, upgrade it to the current
schema (data migration), and validate its integrity before it is allowed into the application state.

### Architectural Requirements

| Requirement            | Description                                                                                                          |
|------------------------|----------------------------------------------------------------------------------------------------------------------|
| Backward Compatibility | Users must be able to load trips generated months ago without data loss.                                             |
| Offline Migrations     | The migration process must happen locally without requiring a network call to the PHP backend.                       |
| Runtime Type Safety    | We cannot blindly trust the contents of an uploaded `.json` file or `localStorage`. It must be validated at runtime. |
| Single Migration Path  | Uploaded `.json` files and `localStorage` hydration must share the exact same migration logic.                       |

---

## Decision Drivers

* **User Experience (UX)** — Prevent data loss and "white screens of death" caused by schema mismatches.
* **Offline Reliability** — Uphold the "local-first" philosophy by keeping file imports purely client-side.
* **Defensive Programming** — Enforce a strict boundary between untrusted external data (a JSON file) and the
  strictly-typed Zustand store.

---

## Considered Options

### Option A: Backend Migration Endpoint (`/v1/migrate`)

Send the raw imported JSON to the API Platform backend. The PHP application detects the version, runs PHP-based
migrations, and returns the updated `TripResponse` DTO.

### Option B: Frontend Migrations using Zustand `persist` and Custom Upgraders

Leverage Zustand's built-in `migrate` function for `localStorage`, but handle `.json` file imports completely
separately.

### Option C: Unified Frontend Pipeline with Zustand `migrate` and `Zod` Validation (Chosen)

Use Zustand's native `version` and `migrate` configuration to handle schema upgrades, and enforce a strict runtime
validation boundary using `Zod` (schema validation library) before hydrating the store from *any* source.

---

## Decision Outcome

**Chosen: Option C (Unified Frontend Pipeline with Zustand + Zod)**

### Why Other Options Were Rejected

**Option A (Backend Migration) rejected:**

* Violates the "local-first" offline requirement. A user without an internet connection would be unable to open their
  own locally saved `.json` file.
* Adds unnecessary network latency just to rename JSON keys.

**Option B (Zustand Custom Upgraders Only) rejected:**

* Zustand's `persist` handles `localStorage` beautifully, but lacks runtime validation. If a user manually edits a
  `.json` file and breaks the structure (e.g., changing a number to a string), Zustand will ingest it, corrupting the
  store and crashing Next.js React Server/Client Components.

### Why Option C was Chosen

* **Zustand (v5.x)** provides a native `version` integer and a `migrate(persistedState, version)` callback specifically
  designed for Redux/Zustand local-first migrations.
* **Zod (v3.23+)** provides mathematically sound runtime parsing. By defining a Zod schema that mirrors the OpenAPI
  `TripResponse` type, we guarantee that no malformed data ever enters the React lifecycle.

---

## Implementation Strategy

### 3.1 — Defining the Store Version and Zod Schema Boundary

We will introduce `zod` to the frontend workspace to parse untrusted JSON.

```bash
npm install zod
```

We create a runtime validation schema that strictly mirrors the current OpenAPI-generated types.

**File:** `pwa/src/lib/validation/tripSchema.ts`

```typescript
import {z} from 'zod';
import type {paths} from '@/lib/api/schema'; // From ADR-002

// Extract the exact TS type for reference
type CurrentTripType = paths['/generate-trip']['post']['responses']['201']['content']['application/ld+json'];

// Define the runtime boundary
export const TripSchema = z.object({
    id: z.string().uuid(),
    totalDistance: z.number().nonnegative(),
    // As the backend evolves, this Zod schema MUST be updated to match CurrentTripType
    stages: z.array(
        z.object({
            dayNumber: z.number().int().positive(),
            distance: z.number().nonnegative(),
            // Future fields will go here
        })
    )
});
```

### 3.2 — Implementing Zustand Migrations

We configure the Zustand `persist` middleware to handle versioning. The current version of the app is `1`.

**File:** `pwa/src/store/useTripStore.ts`

```typescript
import {create} from 'zustand';
import {persist, createJSONStorage} from 'zustand/middleware';
import {TripSchema} from '@/lib/validation/tripSchema';
import type {z} from 'zod';

type TripStateData = z.infer<typeof TripSchema>;

interface TripState {
    trip: TripStateData | null;
    importFromJson: (jsonData: string) => void;
    // ... other methods
}

const CURRENT_STORE_VERSION = 1;

export const useTripStore = create<TripState>()(
    persist(
        (set) => ({
            trip: null,
            importFromJson: (jsonData: string) => {
                try {
                    // 1. Parse string to object
                    const rawData = JSON.parse(jsonData);
                    // 2. Validate against current Zod schema to ensure integrity
                    const validTrip = TripSchema.parse(rawData);
                    set({trip: validTrip});
                } catch (error) {
                    console.error("Invalid JSON file:", error);
                    throw new Error("The imported trip file is corrupted or incompatible.");
                }
            },
        }),
        {
            name: 'Bike Trip Planner-storage',
            version: CURRENT_STORE_VERSION,

            // Zustand's native migration hook for localStorage
            migrate: (persistedState: any, version: number): TripStateData | null => {
                if (version === 0) {
                    // Example future migration: Version 0 to 1
                    // e.g., renaming 'distance' to 'totalDistance'
                    persistedState.totalDistance = persistedState.distance;
                    delete persistedState.distance;
                }

                // After migrations, ALWAYS run through Zod to ensure complete safety
                try {
                    if (persistedState && persistedState.trip) {
                        persistedState.trip = TripSchema.parse(persistedState.trip);
                    }
                    return persistedState as TripStateData;
                } catch (e) {
                    console.error("LocalStorage migration failed Zod validation. Wiping state.");
                    return null; // Gracefully wipe corrupted state rather than crashing
                }
            },
        }
    )
);
```

### 3.3 — The Export Structure

When exporting a file, we must embed the application version so that future imports can know where to start the
migration chain (if we implement file-based migrations in the future).

**File:** `pwa/src/lib/exportUtils.ts`

```typescript
export const exportTripToFile = (trip: TripStateData) => {
    const exportPayload = {
        BikeTripPlannerVersion: 1, // Metadata for future migrations
        data: trip,
    };

    const blob = new Blob([JSON.stringify(exportPayload, null, 2)], {type: 'application/json'});
    const url = URL.createObjectURL(blob);
    // ... trigger browser download
};
```

---

## Verification

1. **Unit Testing Zod (Jest/Vitest):** Create a mock JSON object representing an old `V0` schema. Pass it through the
   migration function and assert that it correctly transforms into a `V1` schema and passes `TripSchema.parse()`.
2. **End-to-End Testing (Playwright):** * Inject a malformed JSON string into the browser's `localStorage` via
   Playwright's `page.evaluate()`.

   * Reload the page.
   * Verify that the application does not crash (React Error Boundary should not trigger) and that the store gracefully
     resets to `null` due to the Zod validation failure.

3. **Manual Import Validation:** Upload a `.json` file where `totalDistance` is a string `"100"` instead of a number
   `100`. Verify the UI displays a clean "Invalid file" toast notification rather than crashing.

---

## Consequences

### Positive

* **Rock-Solid Stability:** Impossible for malformed local data or old JSON files to crash the Next.js React tree.
* **Offline Integrity:** Migrations run entirely in the browser using the client's CPU.
* **Clear Upgrade Path:** As the API Platform backend evolves in Lot 2 (e.g., adding dynamic pricing arrays), we simply
  bump `CURRENT_STORE_VERSION` to `2` and write a transform function in the `migrate` callback.

### Negative

* **Maintenance Overhead:** The developer must remember to update both the PHP DTOs (for OpenAPI) AND the Zod schema (
  `TripSchema.ts`) in the frontend. (Note: Tools like `zod-openapi` exist but often introduce unnecessary complexity for
  a simple MVP. Manual Zod alignment is accepted for Lot 1).

### Neutral

* Corrupted `localStorage` data is aggressively wiped if it fails migration. This is safer than crashing, but could
  result in edge-case data loss if a migration script is poorly written.

---

## Sources

* [Zustand Documentation: Persist Middleware & Migrations](https://www.google.com/search?q=https://docs.pmnd.rs/zustand/integrations/persisting-store-data%23how-can-i-migrate-persisted-state)
* [Zod Documentation: Schema Parsing](https://zod.dev/?id=parse)
* [Next.js Error Boundaries](https://nextjs.org/docs/app/building-your-application/routing/error-handling)
