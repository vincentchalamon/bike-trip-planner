# ADR-007: Frontend Local State Management and Reactivity (Zustand)

*(Note: Adjusting the sequential numbering to ADR-011 to follow the previously established sequence, while addressing
the exact topic you requested).*

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

Bike Trip Planner operates under a strictly "local-first" paradigm (ADR-001). When the API Platform backend generates a trip, it
returns a deeply nested JSON payload containing the overall route, individual daily stages, geospatial coordinates,
weather forecasts, and security alerts.

Because the application lacks a persistent cloud database for user sessions, this complex JSON object must be stored
securely within the browser. Furthermore, the Next.js 16 frontend is highly interactive. If a user manually edits a
stage (e.g., changing the date or adding a custom POI), the UI must instantly reflect these changes across all
components (maps, elevation charts, summary cards) without triggering a full page reload or a heavy backend
recalculation.

Finally, Next.js utilizes Server-Side Rendering (SSR) by default. Reading from the browser's `localStorage` during the
initial SSR pass results in critical React hydration mismatch errors.

### Architectural Requirements

| Requirement           | Description                                                                                                                         |
|-----------------------|-------------------------------------------------------------------------------------------------------------------------------------|
| Deep Reactivity       | Components must react instantly to deep object mutations (e.g., adding a warning to Stage 3) without unnecessary global re-renders. |
| Automatic Persistence | The state must automatically serialize to `localStorage` so users do not lose their trip if they refresh the tab.                   |
| SSR Compatibility     | The solution must safely handle the hydration gap between the Node.js server render and the client's `localStorage` execution.      |
| TypeScript Inference  | The state manager must flawlessly inherit the `openapi-typescript` schemas defined in ADR-002.                                      |

---

## Decision Drivers

* **Developer Experience (DX)** — Modifying deeply nested arrays (stages -> pois) in immutable JavaScript is notoriously
  painful and error-prone.
* **Performance** — Avoiding the "Prop-Drilling" anti-pattern and preventing the entire React tree from re-rendering
  when only a single map marker changes.
* **Bundle Size** — Keeping the client-side JavaScript bundle as light as possible to ensure fast loading on mobile
  devices in low-connectivity areas.

---

## Considered Options

### Option A: React Context API + `useReducer`

Using React's native state management tools to create a global `TripContext` wrapping the entire application.

* *Cons:* React Context does not natively support component-level selector optimization. Updating one property in the
  context forces every consuming component to re-render. It also requires writing manual boilerplate to sync with
  `localStorage` and handle SSR hydration.

### Option B: Redux Toolkit (RTK)

The industry standard for complex state management in enterprise React applications.

* *Cons:* Massive boilerplate. Redux requires actions, reducers, and providers. While RTK simplifies this, it is
  architectural overkill for a local-first MVP that does not require complex asynchronous thunk orchestration (since
  data fetching is straightforward).

### Option C: Zustand + Immer + Persist Middleware (Chosen)

A minimalist, unopinionated state management library (Zustand) combined with immutable state drafts (Immer) and native
`localStorage` synchronization (Persist).

---

## Decision Outcome

<!-- markdownlint-disable MD036 -->
**Chosen: Option C (Zustand + Immer + Persist)**

### Why Other Options Were Rejected

**Option A (Context API) rejected:**
It violates the performance requirement. If the user moves a slider to change the pacing, the entire application (
including heavy MapLibre canvas components) would re-render, causing severe UI lag.

**Option B (Redux Toolkit) rejected:**
It violates the bundle size and DX drivers. Zustand accomplishes the exact same goal with less than 2kB of bundle size
and zero `<Provider>` wrapper components, keeping the Next.js App Router tree perfectly clean.

### Why Option C was Chosen

* **Zustand `persist`:** Natively handles serializing and deserializing the state to `localStorage` with built-in
  versioning and migration hooks (critical for ADR-003).
* **Zustand Selectors:** Components only subscribe to the specific slice of state they need (e.g.,
  `const stages = useTripStore((state) => state.trip.stages)`), completely eliminating unnecessary re-renders.
* **Immer Middleware:** Modifying a nested object requires writing `state.trip.stages[1].warnings.push(newWarning)`.
  Without Immer, this requires incredibly verbose object spreading (`...state`). Immer allows direct mutation syntax
  while safely compiling it into immutable updates under the hood.

---

## Implementation Strategy

### 11.1 — Dependency Installation

We install Zustand and Immer in the Next.js workspace.

```bash
cd pwa
npm install zustand immer
```

### 11.2 — The Store Definition

We combine Zustand's `persist` and `immer` middlewares to create a powerful, strongly-typed store that aligns with our
OpenAPI contract.

**File:** `pwa/src/store/useTripStore.ts`

```typescript
import {create} from 'zustand';
import {persist} from 'zustand/middleware';
import {immer} from 'zustand/middleware/immer';
import type {paths} from '@/lib/api/schema'; // ADR-002 Contract

type TripResponse = paths['/generate-trip']['post']['responses']['201']['content']['application/ld+json'];
type Warning = paths['/generate-trip']['post']['responses']['201']['content']['application/ld+json']['stages'][number]['warnings'][number];

interface TripState {
    trip: TripResponse | null;
    hasHydrated: boolean;

    // Actions
    setHasHydrated: (state: boolean) => void;
    setTrip: (trip: TripResponse) => void;
    addWarningToStage: (stageIndex: number, warning: Warning) => void;
    clearTrip: () => void;
}

export const useTripStore = create<TripState>()(
    persist(
        immer((set) => ({
            trip: null,
            hasHydrated: false, // Tracks if localStorage has been loaded into memory

            setHasHydrated: (state) => set({hasHydrated: state}),

            setTrip: (trip) => set({trip}),

            // Thanks to Immer, we can mutate the deeply nested array directly!
            addWarningToStage: (stageIndex, warning) =>
                set((state) => {
                    if (state.trip && state.trip.stages[stageIndex]) {
                        state.trip.stages[stageIndex].warnings.push(warning);
                    }
                }),

            clearTrip: () => set({trip: null}),
        })),
        {
            name: 'Bike Trip Planner-storage',
            // We explicitly skip hydrating the 'hasHydrated' boolean itself
            partialize: (state) => ({trip: state.trip}),
            onRehydrateStorage: () => (state) => {
                if (state) {
                    state.setHasHydrated(true);
                }
            },
        }
    )
);
```

### 11.3 — Preventing Next.js Hydration Mismatches

Because Next.js 16 App Router renders components on the server (where `localStorage` is `undefined`), we must prevent
Client Components from rendering the persisted state until the browser has successfully hydrated the Zustand store.
Otherwise, React will throw a Error #418 (Hydration Mismatch).

We create a reusable boundary component.

**File:** `pwa/src/components/HydrationBoundary.tsx`

```tsx
'use client';

import {useEffect} from 'react';
import {useTripStore} from '@/store/useTripStore';

export default function HydrationBoundary({children}: { children: React.ReactNode }) {
    const hasHydrated = useTripStore((state) => state.hasHydrated);

    // Fallback UI while localStorage is being read (usually < 50ms)
    if (!hasHydrated) {
        return (
            <div className="flex items-center justify-center h-screen">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"/>
            </div>
        );
    }

    return <>{children}</>;
}
```

**Usage in a Layout or Page:**

```tsx
import HydrationBoundary from '@/components/HydrationBoundary';
import TripDashboard from '@/components/TripDashboard';

export default function Page() {
    return (
        <HydrationBoundary>
            <TripDashboard/>
        </HydrationBoundary>
    );
}
```

---

## Verification

1. **Hydration Safety:** Run `npm run build` and `npm run start` (production mode). Load the page. Verify in the browser
   console that no React hydration mismatch warnings are thrown.
2. **Reactivity Performance (React Profiler):** Open React DevTools. Trigger the `addWarningToStage` action. Verify that
   only the component subscribing to that specific stage's warnings re-renders, while the MapLibre component and
   unrelated stage cards do not re-render.
3. **Persistence Recovery:** - Generate a trip.

* Force close the browser tab.
* Reopen the application.
* Verify that the trip data is instantly restored from `localStorage` without requiring a network request to the
  backend.

---

## Consequences

### Positive

* **Unmatched DX:** Combining Zustand and Immer allows the developer (and AI agents) to write highly readable,
  synchronous update logic without battling complex JavaScript array spreading (
  `[...state.trip.stages.slice(0, i), updatedStage, ...]`).
* **Optimal Performance:** Fine-grained selectors ensure that the heavy interactive elements (maps, charts) remain
  smooth and performant, operating strictly outside the React render loop until their specific data slice changes.
* **Robust Offline Capability:** The `persist` middleware guarantees the "local-first" contract. The application
  functions flawlessly even if the server is unreachable after the initial generation.

### Negative

* **Client-Side Exclusivity:** Because the state relies on `localStorage`, the trip data is completely invisible to
  Next.js Server Components. Search Engine Optimization (SEO) for specific user trips is impossible. (This is
  acceptable, as Bike Trip Planner MVP routes are private to the user's local machine).

### Neutral

* Zustand's documentation recommends using atomic stores for different domains. If the application grows to include user
  settings (e.g., Dark Mode, Metric vs Imperial), a separate `useSettingsStore` should be created rather than bloating
  the `useTripStore`.

---

## Sources

* [Zustand Official Documentation: Persist Middleware](https://www.google.com/search?q=https://zustand-demo.pmnd.rs/docs/integrations/persisting-store-data)
* [Zustand Official Documentation: Immer Middleware](https://www.google.com/search?q=https://zustand-demo.pmnd.rs/docs/integrations/immer-middleware)
* [Next.js Documentation: React Hydration Error](https://nextjs.org/docs/messages/react-hydration-error)
* [Immer.js Official Documentation](https://immerjs.github.io/immer/)
