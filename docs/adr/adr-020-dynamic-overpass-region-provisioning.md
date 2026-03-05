# ADR-020: Dynamic Overpass Region Provisioning

- **Status:** Proposed
- **Date:** 2026-03-05
- **Extends:** ADR-017 (Valhalla Routing Engine and Self-Hosted Overpass Integration)
- **Depends on:** ADR-016 Option F (Self-hosted Overpass — foundation implemented)

## Context and Problem Statement

ADR-017 introduces a self-hosted Overpass instance with a single PBF region (Nord-Pas-de-Calais). This works for the primary use case but has two limitations:

1. **Geographic rigidity** — The imported region is baked into `compose.yaml`. Supporting trips in Brittany, Provence, or the Alps requires manual reconfiguration and full reimport.
2. **No operational tooling** — There is no way for a developer or operator to manage which regions are loaded without editing Docker configuration files.

This ADR designs a **dynamic region provisioning system** that allows adding OSM regions on-demand, with an interactive Symfony console command for local development and a background worker for automated provisioning.

---

## Architecture Overview

```text
┌─────────────────────────────────────────────────────────────┐
│                    Developer / Operator                      │
│                                                             │
│  $ bin/console app:overpass:provision                        │
│  > Which region do you want to add? Nord-Pa…                │
│  > ✓ Nord-Pas-de-Calais (223 MB)                           │
│  > Which region do you want to add? (leave empty to finish) │
│  > Bret…                                                    │
│  > ✓ Bretagne (307 MB)                                     │
│  > Which region do you want to add? (leave empty to finish) │
│  > [enter]                                                  │
│  > Downloading 2 regions...                                 │
│  > [1/2] Nord-Pas-de-Calais ████████████████ 223 MB  OK    │
│  > [2/2] Bretagne           ████████████████ 307 MB  OK    │
│  > Merging 2 PBF files with osmium...                       │
│  > Triggering Overpass reimport...                           │
│  > Done. Overpass will be available in ~30-45 minutes.       │
└──────────────┬──────────────────────────────────────────────┘
               │
               ▼
┌──────────────────────────────────────────────────────────────┐
│                     osmium-worker                             │
│                (Alpine + osmium-tool + curl)                  │
│                                                              │
│  1. Downloads PBF files to /data/regions/                    │
│  2. Merges all PBFs into /data/region.osm.pbf                │
│  3. Triggers Overpass reimport                               │
└──────────────────────────┬───────────────────────────────────┘
                           │
                           ▼
               ┌──────────────────────────┐
               │       overpass            │
               │  (wiktorn/overpass-api)   │
               │                           │
               │  Starts with empty PBF    │
               │  stub (.docker/osm/)      │
               │  Reimports after          │
               │  provisioning             │
               └──────────────────────────┘
```

---

## Decision Drivers

1. **Developer experience** — Adding regions should be as easy as running a single command with autocomplete, similar to `make entity` in Symfony MakerBundle.
2. **No persistent database** — Region state is derived from filesystem (downloaded PBF files), consistent with the local-first architecture.
3. **Offline-first** — Once regions are downloaded, the system works without internet. PBF files persist in Docker volumes.
4. **Incremental** — Adding a region does not require re-downloading existing regions. Only the new PBF is fetched, then all PBFs are merged.

---

## Detailed Design

### 20.0 — Empty PBF Stub for Instant Startup

An **~18 KB PBF stub** with real Lille road data (~300m around Grand Place, 1074 nodes / 217 ways) is committed at `.docker/osm/lille-stub.osm.pbf`. It is shared by both Overpass and Valhalla via bind-mounts. Overpass converts PBF→BZ2 at startup via `OVERPASS_PLANET_PREPROCESS`; Valhalla builds 2 valid tiles from it.

```yaml
overpass:
  volumes:
    - .docker/osm/lille-stub.osm.pbf:/data/osm/region.osm.pbf:ro
```

**Startup flow:**

1. Overpass reads the bind-mounted Lille stub → converts PBF→BZ2 via osmium → imports in ~15s
2. `LocalOverpassStatusChecker` reports Overpass as ready
3. `OsmScanner` queries local Overpass → empty results → falls back to public `overpass-api.de`
4. Developer runs `app:overpass:provision` to download real regions when needed

This means `docker compose up` is never blocked by a PBF download. The application is fully functional immediately via the public API fallback.

---

### 20.1 — Geofabrik Region Registry

A hardcoded registry of 27 French Geofabrik regions. Each entry maps a human-readable name to a Geofabrik download slug and approximate PBF size.

```php
namespace App\Command\Overpass;

final class GeofabrikRegionRegistry
{
    /**
     * @return array<string, array{slug: string, size: string}>
     */
    public static function all(): array
    {
        return [
            'Alsace' => ['slug' => 'alsace', 'size' => '122 MB'],
            'Aquitaine' => ['slug' => 'aquitaine', 'size' => '276 MB'],
            'Auvergne' => ['slug' => 'auvergne', 'size' => '141 MB'],
            'Basse-Normandie' => ['slug' => 'basse-normandie', 'size' => '134 MB'],
            'Bourgogne' => ['slug' => 'bourgogne', 'size' => '186 MB'],
            'Bretagne' => ['slug' => 'bretagne', 'size' => '307 MB'],
            'Centre' => ['slug' => 'centre', 'size' => '225 MB'],
            'Champagne-Ardenne' => ['slug' => 'champagne-ardenne', 'size' => '98 MB'],
            'Corse' => ['slug' => 'corse', 'size' => '32 MB'],
            'Franche-Comte' => ['slug' => 'franche-comte', 'size' => '115 MB'],
            'Guadeloupe' => ['slug' => 'guadeloupe', 'size' => '23 MB'],
            'Guyane' => ['slug' => 'guyane', 'size' => '14 MB'],
            'Haute-Normandie' => ['slug' => 'haute-normandie', 'size' => '99 MB'],
            'Ile-de-France' => ['slug' => 'ile-de-france', 'size' => '314 MB'],
            'Languedoc-Roussillon' => ['slug' => 'languedoc-roussillon', 'size' => '249 MB'],
            'Limousin' => ['slug' => 'limousin', 'size' => '92 MB'],
            'Lorraine' => ['slug' => 'lorraine', 'size' => '160 MB'],
            'Martinique' => ['slug' => 'martinique', 'size' => '19 MB'],
            'Mayotte' => ['slug' => 'mayotte', 'size' => '10 MB'],
            'Midi-Pyrenees' => ['slug' => 'midi-pyrenees', 'size' => '336 MB'],
            'Nord-Pas-de-Calais' => ['slug' => 'nord-pas-de-calais', 'size' => '223 MB'],
            'Pays-de-la-Loire' => ['slug' => 'pays-de-la-loire', 'size' => '347 MB'],
            'Picardie' => ['slug' => 'picardie', 'size' => '124 MB'],
            'Poitou-Charentes' => ['slug' => 'poitou-charentes', 'size' => '217 MB'],
            'Provence-Alpes-Cote-d-Azur' => ['slug' => 'provence-alpes-cote-d-azur', 'size' => '362 MB'],
            'Reunion' => ['slug' => 'reunion', 'size' => '32 MB'],
            'Rhone-Alpes' => ['slug' => 'rhone-alpes', 'size' => '491 MB'],
        ];
    }

    public static function downloadUrl(string $slug): string
    {
        return sprintf(
            'https://download.geofabrik.de/europe/france/%s-latest.osm.pbf',
            $slug,
        );
    }
}
```

---

### 20.2 — Interactive Console Command: `app:overpass:provision`

Inspired by Symfony MakerBundle's `make:entity` command, this interactive command allows developers to select regions one by one with autocomplete suggestions.

#### UX Flow

```text
$ bin/console app:overpass:provision

 Overpass Region Provisioner
 ===========================

 Which region do you want to add?:
 > Nord  [TAB]
 > Nord-Pas-de-Calais (223 MB)

 ✓ Added: Nord-Pas-de-Calais

 Which region do you want to add? (leave empty to finish):
 > Bre  [TAB]
 > Bretagne (307 MB)

 ✓ Added: Bretagne

 Which region do you want to add? (leave empty to finish):
 > [ENTER]

 Selected regions:
  • Nord-Pas-de-Calais (223 MB)
  • Bretagne (307 MB)
  Total estimated download: ~530 MB

 Do you want to proceed? (yes/no) [yes]:
 > yes

 [1/2] Downloading Nord-Pas-de-Calais... 223 MB ✓
 [2/2] Downloading Bretagne...           307 MB ✓

 Merging 2 PBF files with osmium...      ✓

 Triggering Overpass reimport...          ✓

 [OK] Done! Overpass will reimport the merged data.
      Monitor progress: docker compose logs -f overpass
```

#### Key Features

- **Autocomplete** — Uses Symfony Console's `QuestionHelper` with a `ChoiceQuestion` or custom `Question` with autocomplete callback. Region names are fuzzy-matched (case-insensitive, accent-insensitive).
- **Already-downloaded detection** — If a PBF file already exists in `/data/regions/`, it is skipped during download (shown as "already available").
- **Multi-region loop** — Keeps prompting until the user submits an empty answer, exactly like `make:entity` prompts for fields.
- **Dry-run mode** — `--dry-run` flag lists what would be downloaded without executing.
- **Summary + confirmation** — Before downloading, shows a summary table with regions and sizes.

#### Implementation Sketch

```php
namespace App\Command\Overpass;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:overpass:provision',
    description: 'Download OSM regions and provision the local Overpass instance',
)]
final class ProvisionOverpassCommand extends Command
{
    private const string REGIONS_DIR = '/data/regions';
    private const string MERGED_PBF = '/data/region.osm.pbf';

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be downloaded without executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Overpass Region Provisioner');

        $allRegions = GeofabrikRegionRegistry::all();
        $regionNames = array_keys($allRegions);
        $selected = [];

        // Interactive region selection loop
        $isFirst = true;
        while (true) {
            $prompt = $isFirst
                ? 'Which region do you want to add?'
                : 'Which region do you want to add? (leave empty to finish)';
            $isFirst = false;

            $question = new Question($prompt);
            $question->setAutocompleterValues(
                array_map(
                    fn (string $name) => sprintf('%s (%s)', $name, $allRegions[$name]['size']),
                    array_diff($regionNames, $selected),
                ),
            );

            $answer = $io->askQuestion($question);

            if (null === $answer || '' === trim($answer)) {
                if ([] === $selected) {
                    $io->warning('No region selected.');
                    return Command::SUCCESS;
                }
                break;
            }

            // Extract region name (strip size suffix if present)
            $regionName = preg_replace('/\s*\(.*\)$/', '', trim($answer));
            if (!isset($allRegions[$regionName])) {
                $io->error(sprintf('Unknown region: "%s"', $regionName));
                continue;
            }
            if (in_array($regionName, $selected, true)) {
                $io->warning(sprintf('"%s" is already selected.', $regionName));
                continue;
            }

            $selected[] = $regionName;
            $io->success(sprintf('Added: %s', $regionName));
        }

        // Summary
        $io->section('Selected regions');
        foreach ($selected as $name) {
            $io->writeln(sprintf('  • %s (%s)', $name, $allRegions[$name]['size']));
        }

        if (!$io->confirm('Do you want to proceed?', true)) {
            return Command::SUCCESS;
        }

        // Download, merge, trigger reimport...
        // (Implementation delegates to dedicated services)

        return Command::SUCCESS;
    }
}
```

---

### 20.3 — osmium-worker Container

A dedicated Alpine container with `osmium-tool` and `curl` for PBF merging operations.

```yaml
osmium-worker:
  image: alpine:3.21
  volumes:
    - osm-pbf-data:/data
  command: >
    sh -c '
      apk add --no-cache osmium-tool &&
      if ls /data/regions/*.osm.pbf 1>/dev/null 2>&1; then
        osmium merge /data/regions/*.osm.pbf -o /data/region.osm.pbf --overwrite &&
        touch /data/.reimport-trigger;
      fi
    '
  restart: "no"
  profiles: ["provisioning"]
```

**Key design decisions:**

- **`profiles: ["provisioning"]`** — Not started by default with `docker compose up`. Only activated when explicitly requested by the console command via `docker compose --profile provisioning run osmium-worker`.
- **Shared volume** — Reads individual PBFs from `/data/regions/`, writes merged output to `/data/region.osm.pbf`.
- **Reimport trigger** — Touches `/data/.reimport-trigger` file. The Overpass entrypoint watches for this file.

---

### 20.4 — Overpass Reimport Mechanism

The `wiktorn/overpass-api` image supports `OVERPASS_MODE=init` which imports the PBF on first start. For subsequent reimports after region changes:

**Option A (recommended): Container restart**

The simplest approach: after osmium merges the PBF, the console command restarts the Overpass container. The image detects the PBF has changed and reimports.

```php
// In ProvisionOverpassCommand, after merge:
$process = new Process(['docker', 'compose', 'restart', 'overpass']);
$process->run();
```

**Option B (future): Custom entrypoint with file watch**

A custom entrypoint script that watches for `/data/.reimport-trigger`:

```bash
#!/bin/sh
# Watches for reimport trigger, stops dispatcher, reimports, restarts
inotifywait -m /data/osm -e create -e modify |
while read dir action file; do
  if [ "$file" = ".reimport-trigger" ]; then
    /app/bin/dispatcher --terminate
    /app/bin/update_overpass --flush
    /app/bin/dispatcher &
    rm /data/osm/.reimport-trigger
  fi
done
```

Option A is sufficient for the foundation. Option B can be implemented later if zero-downtime reimport becomes necessary.

---

### 20.5 — OsmScanner Fallback Logic

The `OsmScanner` implements a local-first strategy with automatic fallback to the public Overpass API:

```text
Query arrives
    │
    ▼
Is local Overpass ready? ──No──▶ Query public overpass-api.de
    │                                       │
   Yes                                      │
    │                                       │
    ▼                                       │
Query local Overpass                        │
    │                                       │
    ▼                                       │
Has results? ──No──▶ Query public overpass-api.de
    │                        │
   Yes                       │
    │                        │
    ▼                        ▼
Return results         Return results
(cache 24h)            (cache 24h)
```

**Status check:** `GET /api/status` on the local Overpass instance with 1s timeout. The result is cached in-memory for 30 seconds (`LocalOverpassStatusChecker`) to avoid hammering the endpoint on every query.

**Empty result detection:** If the local Overpass returns a valid response with zero elements (the route is outside the imported region), the scanner falls back to the public API transparently.

---

### 20.6 — Implementation Phases

| Phase | Scope | ADR |
|-------|-------|-----|
| **Phase 1 (current)** | Docker infrastructure (overpass with versioned empty PBF stub), OsmScanner fallback, split HTTP clients | ADR-016 Option F |
| **Phase 2** | Interactive console command (`app:overpass:provision`), GeofabrikRegionRegistry | ADR-020 |
| **Phase 3** | osmium-worker container, PBF merge pipeline, reimport trigger | ADR-020 |
| **Phase 4** | Automatic region detection from route bounding box | ADR-020 |
| **Phase 5** | Valhalla tile rebuild integration (shared PBF) | ADR-017 |

---

## Consequences

### Positive

- **Developer-friendly** — Single interactive command to provision regions, with autocomplete and multi-select.
- **Incremental** — New regions can be added without affecting existing data. Only the merge step touches the combined PBF.
- **Transparent fallback** — The public Overpass API is always available as a safety net. Routes outside provisioned regions work seamlessly.
- **No persistent database** — Region state is derived from filesystem (PBF files in `/data/regions/`). Consistent with the local-first architecture.
- **Composable** — The merged PBF serves both Overpass and Valhalla (ADR-017), extending the synergy benefit.

### Negative

- **Merge time scales with data** — Merging all 27 French regions (~4 GB total) takes ~2-5 minutes. Acceptable for an occasional provisioning operation.
- **Reimport downtime** — Overpass is unavailable during reimport (~30 min per GB). Mitigated by the fallback to the public API.
- **Disk usage** — Individual PBFs are kept for incremental merge. Worst case (all France): ~4 GB PBFs + ~5 GB merged + ~20 GB Overpass DB ≈ 29 GB.
- **osmium dependency** — Requires `osmium-tool` in the worker container. Alpine package is well-maintained and lightweight (~15 MB).

### Neutral

- The console command pattern follows Symfony MakerBundle conventions, familiar to Symfony developers.
- The Geofabrik region list is France-only. European regions can be added to the registry in a future iteration.
- The reimport trigger mechanism (file-based) is intentionally simple. More sophisticated approaches (Redis queue, HTTP signal) are possible but unnecessary at this stage.

---

## Sources

- [ADR-016: Performance Optimization Strategy](adr-016-performance-optimization-strategy.md) — Option F (self-hosted Overpass)
- [ADR-017: Valhalla Routing Engine and Self-Hosted Overpass Integration](adr-017-valhalla-routing-engine-and-self-hosted-overpass-integration.md) — Docker infrastructure design
- [Geofabrik Downloads — France](https://download.geofabrik.de/europe/france.html) — PBF region list
- [osmium-tool documentation](https://osmcode.org/osmium-tool/) — PBF merge operations
- [wiktorn/overpass-api Docker image](https://hub.docker.com/r/wiktorn/overpass-api) — Overpass container
- [Symfony Console: Question Helper](https://symfony.com/doc/current/components/console/helpers/questionhelper.html) — Autocomplete implementation
