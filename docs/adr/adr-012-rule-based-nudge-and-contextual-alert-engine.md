# ADR-012: Rule-Based "Nudge" and Contextual Alert Engine (Survival Module)

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

The "Survival & Security" module (Lot 1) is a core differentiator for Bike Trip Planner. Once the `PacingEngine` (ADR-006)
divides the GPX track into daily stages, the system must analyze these stages and inject contextual data.

The business requirements include dozens of conditional rules:

* **Surface Alert:** *If* bike type is "Road" *and* OSM surface is `unpaved`, *then* generate a Warning.
* **Physical Alert:** *If* stage , *then* generate an "Effort Intense" Warning.
* **The "Lunch Nudge":** *If* no lunch POI exists in the stage *and* OSM detects a market on that specific weekday,
  *then* push a Market Suggestion.
* **Maintenance Alert:** *If* trip duration days *and* distance to next bike shop , *then* generate a Tooling Alert.

**The Architectural Problem:** If we implement these checks procedurally inside the API Platform
`TripGenerationProcessor`, we will create a massive, unmaintainable "spaghetti" of `if/else` statements. This violates
the Open/Closed Principle (SOLID), makes unit testing a nightmare, and guarantees merge conflicts when adding new rules
in future lots.

We must define a scalable, decoupled architecture in PHP 8.5 to evaluate these rules and attach alerts/nudges to the
`TripResponse` DTO.

### Architectural Requirements

| Requirement             | Description                                                                                             |
|-------------------------|---------------------------------------------------------------------------------------------------------|
| Extensibility           | Adding a new rule (e.g., "Wind Alert") must not require modifying the core generation classes.          |
| Testability             | Every business rule must be testable in complete isolation.                                             |
| Deterministic Execution | Rules must be executed in a predictable order (e.g., critical safety alerts before lunch nudges).       |
| Standardized Output     | All rules must output a strictly typed `Alert` or `Suggestion` DTO that aligns with the OpenAPI schema. |

---

## Decision Drivers

* **Maintainability** — The application logic will grow significantly in Lot 2 and Lot 3. The foundational pattern must
  support this without refactoring.
* **Developer Experience (DX)** — An AI coding agent or human developer should be able to create a new rule by simply
  creating a single new file.
* **Performance** — Rule evaluation must occur entirely in RAM, iterating over the generated `Stage` objects without
  executing redundant external API calls.

---

## Considered Options

### Option A: Procedural Evaluation (`if/else` monolith)

A single `SecurityScanner` service that executes every rule sequentially inside a massive method.

* *Cons:* Extremely fragile. High cyclomatic complexity. Impossible to isolate state during testing.

### Option B: External Rules Engine (Symfony ExpressionLanguage / RulerZ)

Writing business rules as string expressions in a YAML configuration file and evaluating them dynamically at runtime.

* *Pros:* Highly configurable without changing code.
* *Cons:* Overkill for an MVP. Expressions lack strict PHP 8.5 static typing (PHPStan cannot analyze a YAML string
  effectively), leading to silent runtime bugs.

### Option C: Chain of Responsibility / Tagged Iterator Pattern (Chosen)

Utilizing Symfony's native Dependency Injection (DI) component to create an `AnalyzerInterface`. Each rule is its own
class, automatically tagged and injected into an `AnalyzerRegistry` that iterates over them.

---

## Decision Outcome

**Chosen: Option C (Tagged Iterator Pattern)**

### Why Other Options Were Rejected

Option A guarantees technical debt within the first month of development. Option B sacrifices the strict typing
guarantee established in ADR-002.

Option C perfectly leverages the Symfony 8 ecosystem. By using the `#[AutoconfigureTag]` attribute, the DI container
automatically discovers any class implementing the `AnalyzerInterface` and adds it to the pipeline.

---

## Implementation Strategy

### 12.1 — The DTO Contracts

First, we define the outputs expected in our OpenAPI schema (ADR-002).

**File:** `api/src/ApiResource/Model/Alert.php`

```php
namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;

final class Alert
{
    public const TYPE_CRITICAL = 'critical';
    public const TYPE_WARNING = 'warning';
    public const TYPE_NUDGE = 'nudge';

    public function __construct(
        #[ApiProperty(description: 'Severity level: critical, warning, or nudge.')]
        public readonly string $type,

        #[ApiProperty(description: 'Short, actionable message for the UI.')]
        public readonly string $message,

        #[ApiProperty(description: 'Optional latitude for map rendering.')]
        public readonly ?float $lat = null,

        #[ApiProperty(description: 'Optional longitude for map rendering.')]
        public readonly ?float $lon = null,
    ) {}
}
```

### 12.2 — The Analyzer Interface

We define the interface that every rule must implement.

**File:** `api/src/Analyzer/StageAnalyzerInterface.php`

```php
namespace App\Analyzer;

use App\ApiResource\Stage;
use App\ApiResource\Model\Alert;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.stage_analyzer')]
interface StageAnalyzerInterface
{
    /**
     * Analyzes a stage and returns an array of Alerts/Nudges.
     * @return Alert[]
     */
    public function analyze(Stage $stage, array $context = []): array;
    
    /**
     * Determines the execution order. Lower numbers execute first.
     */
    public static function getPriority(): int;
}
```

### 12.3 — Implementing an Isolated Rule (The "Lunch Nudge")

A developer (or AI) can now build complex rules completely isolated from the rest of the application.

**File:** `api/src/Analyzer/Rules/LunchNudgeAnalyzer.php`

```php
namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Stage;
use App\ApiResource\Model\Alert;

final class LunchNudgeAnalyzer implements StageAnalyzerInterface
{
    public function analyze(Stage $stage, array $context = []): array
    {
        $alerts = [];
        $hasLunchPoi = false;

        // 1. Check if the user already has a lunch spot planned
        foreach ($stage->pois as $poi) {
            if ($poi->category === 'restaurant' || $poi->category === 'lunch') {
                $hasLunchPoi = true;
                break;
            }
        }

        // 2. If no lunch is planned, inject the nudge
        if (!$hasLunchPoi) {
            // Logic to check local markets based on $stage->date goes here.
            // For example, if a market is detected at the midway point:
            $alerts[] = new Alert(
                type: Alert::TYPE_NUDGE,
                message: 'Pas de déjeuner prévu ? Un marché local se tient ce matin à mi-parcours.',
            );
        }

        return $alerts;
    }

    public static function getPriority(): int
    {
        return 100; // Low priority, runs after critical safety checks
    }
}
```

### 12.4 — The Registry (Orchestrator)

The registry gathers all tagged services via Symfony's `#[TaggedIterator]` and applies them to the stages.

**File:** `api/src/Analyzer/AnalyzerRegistry.php`

```php
namespace App\Analyzer;

use App\ApiResource\TripResponse;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final readonly class AnalyzerRegistry
{
    /**
     * @param iterable<StageAnalyzerInterface> $analyzers
     */
    public function __construct(
        #[TaggedIterator('app.stage_analyzer', defaultPriorityMethod: 'getPriority')]
        private iterable $analyzers
    ) {}

    public function processTrip(TripResponse $trip): void
    {
        foreach ($trip->stages as $stage) {
            foreach ($this->analyzers as $analyzer) {
                $newAlerts = $analyzer->analyze($stage);
                
                // Append new alerts to the stage's existing alerts
                foreach ($newAlerts as $alert) {
                    $stage->addAlert($alert);
                }
            }
        }
    }
}
```

### 12.5 — Frontend Rendering (Progressive Disclosure)

Because the API outputs standardized `Alert` objects, the Next.js frontend can use a simple map function to render them
contextually.

**File:** `pwa/src/components/StageCard.tsx`

```tsx
import {AlertTriangle, Info, MapPin} from 'lucide-react';
import type {paths} from '@/lib/api/schema';

type Alert = paths['/generate-trip']['post']['responses']['201']['content']['application/ld+json']['stages'][number]['alerts'][number];

const AlertBadge = ({alert}: { alert: Alert }) => {
    const isCritical = alert.type === 'critical';
    const isNudge = alert.type === 'nudge';

    return (
        <div className={`flex items-center p-2 mt-2 text-sm rounded-md ${
            isCritical ? 'bg-red-50 text-red-700 border border-red-200' :
                isNudge ? 'bg-blue-50 text-blue-700 border border-blue-200' :
                    'bg-orange-50 text-orange-700 border border-orange-200'
        }`}>
            {isCritical ? <AlertTriangle className="w-4 h-4 mr-2"/> : <Info className="w-4 h-4 mr-2"/>}
            <span>{alert.message}</span>
            {alert.lat && alert.lon && (
                <button className="ml-auto flex items-center text-xs underline">
                    <MapPin className="w-3 h-3 mr-1"/> View on Map
                </button>
            )}
        </div>
    );
};
```

---

## Verification

1. **Isolation Testing:** Create `tests/Analyzer/Rules/LunchNudgeAnalyzerTest.php`. Mock a `Stage` DTO with and without
   a lunch POI. Assert that `analyze()` returns exactly 1 or 0 `Alert` objects. This proves the logic works without
   booting the Symfony kernel.
2. **Container Compilation:** Run `docker compose exec php bin/console debug:container --tag=app.stage_analyzer`. Verify
   that all created analyzer classes are automatically listed and sorted by their priority integer.
3. **End-to-End Validation:** In Next.js, verify that a `critical` alert strictly renders with the red CSS classes
   defined in the `AlertBadge` component, confirming the contract behaves as expected.

---

## Consequences

### Positive

* **Adherence to OCP (Open/Closed Principle):** The `AnalyzerRegistry` is closed for modification. To add a new rule in
  the future (e.g., "Ferry Crossing Alert"), a developer simply creates `FerryAnalyzer.php`. Zero changes are needed in
  the core architecture.
* **Flawless Static Analysis:** PHPStan Level 9 easily comprehends arrays of strictly typed interfaces, preventing
  runtime type errors.
* **Frontend Agnosticism:** The frontend is completely blind to *how* or *why* an alert was generated. It merely trusts
  the strictly-typed `type` string to handle the visual rendering.

### Negative

* **Order Dependency Complexity:** If one rule depends on the output of another (e.g., an "Accommodation Nudge" depends
  on a "Danger Warning" to avoid suggesting hotels in dangerous areas), managing integers in `getPriority()` can become
  cumbersome.
* **State Mutation:** The `AnalyzerRegistry` mutates the `Stage` objects by reference (`$stage->addAlert()`). While
  standard in PHP, this differs from strict functional programming paradigms.

### Neutral

* The context array passed to `analyze()` allows injecting user preferences (e.g., `['bike_type' => 'gravel']`). As the
  application grows, this generic array should be refactored into a strictly typed `AnalysisContext` DTO.
