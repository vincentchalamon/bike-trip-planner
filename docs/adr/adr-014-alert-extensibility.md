# ADR-014 — Alert System Extensibility

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

The alert engine must be extensible to allow adding new alert rules without modifying existing
code. Rules need to be discovered automatically, executed in a predictable priority order, and
each independently unit-testable.

## Decision

We use the **Tagged Iterator pattern** from Symfony DI to implement a Chain of Responsibility.

### Architecture

#### Interface

```php
// src/Analyzer/StageAnalyzerInterface.php
#[AutoconfigureTag('app.stage_analyzer')]
interface StageAnalyzerInterface
{
    /** @return list<Alert> */
    public function analyze(Stage $stage, array $context = []): array;

    /** Lower value = higher priority (5 = critical, 100 = nudge) */
    public static function getPriority(): int;
}
```

#### Registry

The `AnalyzerRegistry` collects all tagged services, sorts them by priority, and runs them in
order:

```php
final class AnalyzerRegistry
{
    public function __construct(
        #[TaggedIterator('app.stage_analyzer')]
        iterable $analyzers,
    ) { ... }

    public function analyze(Stage $stage, array $context = []): list<Alert> { ... }
}
```

#### Priority conventions

| Priority | Use case        | Example                       |
|----------|-----------------|-------------------------------|
| 5        | Continuity      | `ContinuityAnalyzer`          |
| 10       | Critical terrain | `ElevationAlertAnalyzer`      |
| 20       | Terrain warnings | `SurfaceAlertAnalyzer`, `TrafficDangerAnalyzer` |
| 100      | Nudges          | `LunchNudgeAnalyzer`          |

### Context keys available to analyzers

| Key           | Type                | Available after        |
|---------------|---------------------|------------------------|
| `nextStage`   | `?Stage`            | Always (from Registry) |
| `tripDays`    | `int`               | After `GenerateStages` |
| `startDate`   | `?\DateTimeImmutable` | After PATCH dates    |
| `endDate`     | `?\DateTimeImmutable` | After PATCH dates    |
| `osmPois`     | `array`             | After `ScanPois`       |
| `osmWays`     | `array`             | After `AnalyzeTerrain` |
| `weatherData` | `array`             | After `FetchWeather`   |

## How to add a new alert rule

1. Create the class in `src/Analyzer/Rules/`:

    ```php
    namespace App\Analyzer\Rules;
    
    use App\Analyzer\StageAnalyzerInterface;
    use App\ApiResource\Model\Alert;
    use App\ApiResource\Model\Stage;
    use App\Enum\AlertType;
    
    final class MyNewAnalyzer implements StageAnalyzerInterface
    {
        public function analyze(Stage $stage, array $context = []): array
        {
            if ($someCondition) {
                return [new Alert(AlertType::Warning, 'Message actionnable', $lat, $lon)];
            }
            return [];
        }
    
        public static function getPriority(): int
        {
            return 50; // 10=critical, 100=nudge
        }
    }
    ```

2. **That is all.** Symfony autoconfiguration discovers the class automatically via
   `#[AutoconfigureTag]` on the interface.

3. Write the unit test in `tests/Analyzer/Rules/MyNewAnalyzerTest.php`.

## Conventions

- One analyzer = one file = one test.
- Naming: `{Concept}Analyzer.php` + `{Concept}AnalyzerTest.php`.
- Alert messages must be **actionable** — not "error detected" but "Unpaved section of 3km
  between km 45 and km 48".
- Always provide `lat`/`lon` when possible for map rendering.
- Use `AlertType::NUDGE` for informational suggestions, `WARNING` for things to prepare for,
  `CRITICAL` for genuine danger.

## Consequences

- Adding a new rule requires only one new file and one new test.
- No modification to `AnalyzerRegistry`, `AnalyzeTerrainHandler`, or any configuration file.
- Rules are auto-discovered and sorted by priority at container compile time.
- All rules are independently unit-testable with a mock `Stage`.
