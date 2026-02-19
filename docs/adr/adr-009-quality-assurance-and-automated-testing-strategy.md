# ADR-009: Quality Assurance and Automated Testing Strategy

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

Bike Trip Planner relies on a Decoupled API-First Architecture (ADR-001) where the PHP 8.5 backend executes complex mathematical
and spatial algorithms (Pacing Engine, Douglas-Peucker decimation) and the Next.js 16 frontend manages a highly
interactive, "local-first" state using `localStorage` and file APIs.

A poorly defined testing strategy can lead to two extremes:

1. **The "Ice Cream Cone" Anti-pattern:** Relying too heavily on slow, brittle End-to-End (E2E) tests that fail randomly
   due to network latency when querying external APIs (OSM, Weather).
2. **The "False Sense of Security":** Having 100% backend unit test coverage, but failing to catch critical bugs in the
   browser, such as the Next.js UI failing to parse a downloaded JSON file or a broken PDF export button.

We must define a unified testing strategy that maximizes the Return on Investment (ROI) of the developer's time, ensures
the mathematical accuracy of the algorithms, and guarantees the reliability of the local-first browser features.

### Architectural Requirements

| Requirement           | Description                                                                                                                 |
|-----------------------|-----------------------------------------------------------------------------------------------------------------------------|
| Domain Logic Accuracy | The `PacingEngine` and `RouteSimplifier` must be mathematically verified against edge cases.                                |
| Browser API Testing   | The test suite must be capable of interacting with the browser's `localStorage` and intercepting file downloads (PDF/JSON). |
| Determinism           | Tests must not randomly fail (flakiness) due to external API rate limits (e.g., OpenStreetMap Overpass).                    |
| Modern Standards      | The testing stack must utilize the latest paradigms (e.g., PHPUnit 13 Attributes instead of legacy annotations).            |

---

## Decision Drivers

* **Execution Speed** — Backend math tests should execute in milliseconds. E2E tests should be parallelized to run in
  under 3 minutes.
* **Debugging Experience (DX)** — When a test fails in the CI pipeline, the developer must have visual proof (
  traces/screenshots) to diagnose the issue without needing to reproduce it locally.
* **Separation of Concerns** — The backend should be tested in isolation from the frontend to pinpoint where data drift
  or logic failures occur.

---

## Considered Options

### Option A: Heavy Frontend Unit Testing (Jest + React Testing Library)

Writing unit tests for every React component in Next.js alongside PHPUnit for the backend.

* *Cons:* Extremely time-consuming to mock Zustand stores, `openapi-fetch` clients, and Canvas/MapLibre elements. Does
  not verify that the backend and frontend actually communicate correctly.

### Option B: 100% E2E Testing (Cypress)

Booting the entire Docker stack and driving the application solely through Cypress.

* *Cons:* Cypress struggles with multi-tab testing (e.g., clicking a TER train station link that opens in a new tab) and
  does not natively support testing multiple browser engines (WebKit/Firefox) as seamlessly as newer tools.

### Option C: The "Testing Trophy" Hybrid Approach (PHPUnit 13 + Playwright) (Chosen)

1. **PHPUnit 13** for rapid, isolated unit and integration testing of the PHP 8.5 API Platform backend.
2. **Playwright** for E2E testing of the Next.js frontend, focusing on critical user journeys (Upload -> Generate ->
   Export).

---

## Decision Outcome

**Chosen: Option C (PHPUnit 13 + Playwright)**

### Why Other Options Were Rejected

**Option A (Jest/RTL) rejected:**
Testing a highly interactive mapping tool with Jest/JSDOM is notoriously difficult because JSDOM does not implement a
real rendering engine. Verifying that a polyline renders correctly on a map requires a real browser.

**Option B (Cypress) rejected:**
Playwright outpaces Cypress in 2026 by offering a superior Trace Viewer, native out-of-process multi-tab/multi-origin
support, and built-in API mocking capabilities.

### Why Option C was Chosen:

* **PHPUnit 13:** As of PHPUnit 13, doc-block annotations (e.g., `@dataProvider`, `@group`) are deprecated in favor of
  native PHP 8 Attributes (`#[DataProvider]`, `#[Group]`), making the test code much cleaner and statically analyzable
  by tools like PHPStan.
* **Playwright's Trace Viewer:** If an E2E test fails in the Docker CI, Playwright generates a zip trace containing DOM
  snapshots, network logs, and a timeline screencast, eliminating the "it works on my machine" problem.
* **Network Interception:** Playwright can mock the API Platform backend or external APIs, allowing us to test frontend
  UI states (e.g., a 500 Error from OSM) without writing complex backend fixtures.

---

## Implementation Strategy

### 8.1 — Backend: PHPUnit 13 (Unit & Integration)

We will configure PHPUnit 13 to test the core logic. The Symfony PHPUnit Bridge (`symfony/phpunit-bridge`) will be used
to track deprecations and polyfill features.

**File:** `api/tests/Service/PacingEngineTest.php`

```php
namespace App\Tests\Service;

use App\Pacing\PacingEngine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Utilizing modern PHPUnit 13 attributes instead of legacy PHPDoc annotations
#[CoversClass(PacingEngine::class)]
final class PacingEngineTest extends TestCase
{
    #[DataProvider('provideElevationScenarios')]
    public function testDynamicTargetShrinksWithElevation(
        array $decimatedPoints, 
        int $days, 
        float $expectedDayOneDistance
    ): void {
        $engine = new PacingEngine();
        $stages = $engine->generateStages($decimatedPoints, $days, 300.0);

        // Assert that the stage distance was correctly penalized by the D+
        $this->assertEqualsWithDelta($expectedDayOneDistance, $stages[0]->distance, 0.5);
    }

    public static function provideElevationScenarios(): iterable
    {
        yield 'Flat Terrain' => [
            'points' => [['lat' => 45.0, 'lon' => 4.0, 'ele' => 0]], // Simplified mock
            'days' => 3,
            'expectedDayOneDistance' => 110.8
        ];
        yield 'Mountain Terrain' => [
            'points' => [['lat' => 45.0, 'lon' => 4.0, 'ele' => 2000]], // Simplified mock
            'days' => 3,
            'expectedDayOneDistance' => 70.8 // 110.8 - (2000 / 50)
        ];
    }
}

```

### 8.2 — Frontend: Playwright (E2E & UI Testing)

Playwright will be installed in the Next.js workspace to test the application exactly as a user experiences it.

```bash
cd pwa
npx playwright install --with-deps

```

We will leverage Playwright's ability to interact with the DOM and intercept network requests to simulate the API
Platform backend.

**File:** `pwa/tests/e2e/trip-generation.spec.ts`

```typescript
import {test, expect} from '@playwright/test'; //

test.describe('Trip Generation Flow', () => {
    test('User can generate a trip and export a PDF roadbook', async ({page}) => {
        // 1. Mock the backend API Platform response to ensure deterministic, fast testing 
        // without hitting the real PHP server or OSM Overpass
        await page.route('**/api/v1/generate-trip', async (route) => {
            const json = {
                id: '123e4567-e89b-12d3-a456-426614174000',
                totalDistance: 150.5,
                stages: [
                    {dayNumber: 1, distance: 75.2, elevation: 500, warnings: []},
                    {dayNumber: 2, distance: 75.3, elevation: 600, warnings: []}
                ]
            };
            await route.fulfill({json});
        });

        // 2. Navigate to the Next.js app
        await page.goto('/');

        // 3. User interaction: Paste a Komoot URL
        await page.getByPlaceholder('Paste Komoot URL or upload GPX').fill('https://www.komoot.com/tour/123');
        await page.getByRole('button', {name: 'Generate Trip'}).click();

        // 4. Assert UI updates correctly
        await expect(page.getByText('Total Distance: 150.5 km')).toBeVisible();
        await expect(page.locator('.stage-card')).toHaveCount(2);

        // 5. Assert File Download (PDF Export)
        const downloadPromise = page.waitForEvent('download');
        await page.getByRole('button', {name: 'Export PDF'}).click();
        const download = await downloadPromise;

        expect(download.suggestedFilename()).toBe('Bike Trip Planner_Roadbook_123e4567-e89b-12d3-a456-426614174000.pdf');
    });
});

```

### 8.3 — Continuous Integration (CI) Configuration

The test suite will be orchestrated via a Makefile and executed in a CI environment (e.g., GitHub Actions or GitLab CI).

**File:** `Makefile`

```makefile
test-backend: ## Run PHPUnit 13 tests
	docker compose exec php vendor/bin/phpunit

test-e2e: ## Run Playwright E2E tests
	docker compose exec pwa npx playwright test

test-all: test-backend test-e2e

```

---

## Verification

1. **Backend Determinism:** Running `make test-backend` multiple times must consistently pass within ~1 second, proving
   that no external APIs (OSM) are being queried during the unit tests.
2. **Playwright Trace Viewer:** To debug a failing test, run `npx playwright test --ui`. This opens the Playwright UI
   mode, allowing the developer to step through the DOM snapshots, view the mocked network payloads, and visually
   identify why an assertion failed.
3. **Zustand Persistence Test:** Create a Playwright test that generates a trip, reloads the page (
   `await page.reload()`), and asserts that the trip data is still visible. This mathematically verifies that the
   Zustand `localStorage` persistence (ADR-003) is functioning correctly in a real browser environment.

---

## Consequences

### Positive

* **High Confidence:** Testing the API contract via PHPUnit and the UI via Playwright ensures both sides of the
  decoupled architecture are rock solid.
* **Cost Efficiency:** Mocking external APIs during E2E tests prevents the development environment from exhausting
  OpenWeatherMap API quotas or getting IP-banned by Overpass.
* **Excellent DX:** Migrating to PHPUnit 13 Attributes cleans up the PHP codebase, while Playwright's Codegen and Trace
  Viewer drastically reduce the time spent writing and debugging frontend tests.

### Negative

* **Mock Maintenance:** If the API Platform OpenAPI schema changes (e.g., modifying the `TripResponse` DTO), the mocked
  JSON responses in the Playwright `page.route()` intercepts must be manually updated. (This can be mitigated in Lot 2
  by validating the mock payloads against the Zod schema).

### Neutral

* Playwright tests run in headless mode by default. To see the browser visually interacting with the app during
  development, the developer must append the `--headed` flag or use the UI mode.

---

## Sources

* [Release notes - Playwright (UI Mode & Traces)](https://playwright.dev/docs/release-notes)
* [Playwright Features 2025: Benefits, Limits & AI Tools](https://thinksys.com/qa-testing/playwright-features/)
* [Playwright: Fast and reliable end-to-end testing for modern web apps](https://playwright.dev/)
* [How to Test Next.js Apps with Playwright: Complete Guide](https://www.getautonoma.com/blog/nextjs-playwright-testing-guide)
* [End-to-End Testing Your Next.js App with Playwright](https://medium.com/@natanael280198/end-to-end-testing-your-next-js-app-with-playwright-75ada18447ac)
* [Add #[Group] and #[DataProvider] attributes for PHPUnit 13 | Drupal.org](https://www.drupal.org/project/scheduler/issues/3527579)
* [PHPUnit annotation is deprecated in PHPUnit 13 and can be replaced with attribute | Inspectopedia - JetBrains](https://www.jetbrains.com/help/inspectopedia/PhpUnitAnnotationToAttributeInspection.html)
