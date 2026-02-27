# Plan de developpement PWA — Bike Trip Planner (Lot 1)

## Contexte

La PWA Next.js 16 est actuellement un scaffold vide : shadcn/ui est configure
(`components.json`, Tailwind CSS v4, theme `sky`), les dependances de base sont installees
(Zustand, Immer, Zod, dayjs, lucide-react), mais aucun store, client API, composant metier
ou page fonctionnelle n'existe. Le backend API Platform (PHP 8.5 / Symfony 8) est operationnel
avec un pipeline async Mercure (12 handlers), des endpoints REST (`POST`/`PATCH`/`DELETE`) et un
export PDF via Gotenberg.

L'objectif est de developper l'integralite du frontend (US #1 a #6) en respectant le design
defini dans `docs/plan/design-specifications.md` et la maquette
`docs/plan/template.png`, avec des tests E2E Playwright.

**References :**

- Contrat de types : ADR-002
- Persistence locale : ADR-003
- State management : ADR-007
- Strategie de tests : ADR-009
- Schema OpenAPI : `openapi.json` (racine du projet)

---

## Modifications API requises

### 1. Endpoints de geocodage (nouveau)

Le design specifie que les champs de localisation offrent des suggestions de lieux
(autocompletion) et que les etapes affichent des noms de villes (pas des coordonnees GPS).
Deux endpoints de geocodage sont necessaires.

**Fichiers a creer :**

- `api/src/ApiResource/GeocodeResult.php` — DTO de reponse
- `api/src/Controller/GeocodeController.php` — Controleur Symfony avec 2 actions
- `api/tests/Functional/GeocodeTest.php` — Tests fonctionnels

**Fichier a modifier :**

- `api/config/packages/framework.php` — Ajouter le scoped HTTP client `nominatim.client`

#### 1.1 Geocodage direct (recherche de lieux)

```text
GET /geocode/search?q={query}&limit=5
```

- Proxy vers Nominatim `/search?format=jsonv2&q={query}&limit={limit}`
- Cache 24h via pool `cache.osm`
- Reponse JSON (pas JSON-LD) :

```json
{
  "results": [
    {
      "name": "Paris",
      "displayName": "Paris, Ile-de-France, France",
      "lat": 48.8566,
      "lon": 2.3522,
      "type": "city"
    }
  ]
}
```

#### 1.2 Geocodage inverse (coordonnees vers nom de ville)

```text
GET /geocode/reverse?lat={lat}&lon={lon}
```

- Proxy vers Nominatim `/reverse?format=jsonv2&lat={lat}&lon={lon}&zoom=10`
- Cache 24h via pool `cache.osm`
- Reponse JSON :

```json
{
  "name": "Chartres",
  "displayName": "Chartres, Eure-et-Loir, Centre-Val de Loire, France",
  "lat": 48.4469,
  "lon": 1.4892,
  "type": "city"
}
```

#### 1.3 Configuration du scoped HTTP client

```php
// api/config/packages/framework.php
'nominatim.client' => [
    'base_uri' => 'https://nominatim.openstreetmap.org',
    'headers' => [
        'User-Agent' => 'BikeTripPlanner/1.0 (contact@example.com)',
        'Accept' => 'application/json',
    ],
    'timeout' => 10,
    'max_redirects' => 2,
    'rate_limiter' => 'nominatim', // 1 req/s (politesse Nominatim)
],
```

### 2. Enrichissement des evenements Mercure

Les events Mercure actuels ne contiennent pas assez de donnees pour le rendu frontend.
Modifications a apporter dans les handlers pour que le frontend puisse construire
l'interface progressivement sans endpoints GET supplementaires.

#### 2.1 Event `route_parsed` — ajouter `title`

**Fichier :** `api/src/MessageHandler/FetchAndParseRouteHandler.php`

Le `RouteFetchResult` contient deja un champ `?string $title` (extrait de Komoot ou
Google My Maps). Il faut l'inclure dans le payload de l'event Mercure :

```php
$this->publisher->publish($tripId, MercureEventType::ROUTE_PARSED, [
    'totalDistance' => $totalDistance,
    'totalElevation' => $totalElevation,
    'sourceType' => $result->sourceType->value,
    'title' => $result->title, // AJOUTER
]);
```

#### 2.2 Event `stages_computed` — publier les stages complets

**Fichier :** `api/src/MessageHandler/GenerateStagesHandler.php`

Le payload actuel `stages[{dayNumber, distance}]` est insuffisant. Chaque etape doit
inclure les donnees necessaires au rendu des `StageCard` :

```php
$this->publisher->publish($tripId, MercureEventType::STAGES_COMPUTED, [
    'stages' => array_map(fn(Stage $s) => [
        'dayNumber' => $s->dayNumber,
        'distance' => $s->distance,
        'elevation' => $s->elevation,       // AJOUTER
        'startPoint' => [                    // AJOUTER
            'lat' => $s->startPoint->lat,
            'lon' => $s->startPoint->lon,
            'ele' => $s->startPoint->ele,
        ],
        'endPoint' => [                      // AJOUTER
            'lat' => $s->endPoint->lat,
            'lon' => $s->endPoint->lon,
            'ele' => $s->endPoint->ele,
        ],
        'geometry' => array_map(             // AJOUTER
            fn(Coordinate $c) => ['lat' => $c->lat, 'lon' => $c->lon, 'ele' => $c->ele],
            $s->geometry
        ),
        'label' => $s->label,               // AJOUTER
    ], $stages),
]);
```

#### 2.3 Event `weather_fetched` — publier la meteo par etape

**Fichier :** `api/src/MessageHandler/FetchWeatherHandler.php`

Le payload actuel ne contient que le nombre d'etapes avec meteo. Le frontend a besoin
des donnees meteo completes par etape :

```php
$this->publisher->publish($tripId, MercureEventType::WEATHER_FETCHED, [
    'stages' => array_map(fn(Stage $s) => [
        'dayNumber' => $s->dayNumber,
        'weather' => $s->weather ? [
            'icon' => $s->weather->icon,
            'description' => $s->weather->description,
            'tempMin' => $s->weather->tempMin,
            'tempMax' => $s->weather->tempMax,
            'windSpeed' => $s->weather->windSpeed,
            'windDirection' => $s->weather->windDirection,
            'precipitationProbability' => $s->weather->precipitationProbability,
        ] : null,
    ], $stagesWithWeather),
]);
```

### 3. Stockage du titre dans le cache

**Fichier :** `api/src/Repository/TripRequestRepository.php`

Ajouter deux methodes pour stocker et recuperer le titre extrait de la source :

```php
public function storeTitle(string $tripId, ?string $title): void;
public function getTitle(string $tripId): ?string;
```

Cle de cache : `trip.{tripId}.title`, meme TTL que les autres cles trip (30 min
auto-refresh).

---

## Architecture frontend

### Structure de fichiers cible

```text
pwa/src/
  app/
    layout.tsx                     # Modifier : metadata, lang
    page.tsx                       # Remplacer : HydrationBoundary + TripPlanner
    globals.css                    # Modifier : variables design brand
  components/
    ui/                            # Genere par shadcn/ui
    hydration-boundary.tsx         # Garde SSR/hydratation Zustand
    trip-planner.tsx               # Orchestrateur principal (Client Component)
    magic-link-input.tsx           # Champ URL Komoot
    trip-summary.tsx               # Distance totale + elevation totale
    trip-header.tsx                # Grid 2 colonnes (infos + calendrier)
    trip-title.tsx                 # Titre editable
    location-fields.tsx            # Depart + arrivee avec ligne verticale
    location-combobox.tsx          # Autocompletion de lieux
    weather-indicator.tsx          # Icone meteo + description
    calendar-widget.tsx            # Calendrier custom (compact/complet)
    editable-field.tsx             # Composant texte -> input inline
    timeline.tsx                   # Timeline verticale
    timeline-marker.tsx            # Marqueur cercle vide brand
    stage-card.tsx                 # Carte d'etape (Card shadcn)
    stage-locations.tsx            # Depart -> Arrivee editables
    stage-metadata.tsx             # Distance, D+, meteo
    alert-list.tsx                 # Liste d'alertes
    alert-badge.tsx                # Badge alerte colore (CVA)
    accommodation-panel.tsx        # Panneau hebergements
    accommodation-item.tsx         # Ligne hebergement editable
    add-stage-button.tsx           # Bouton dashed "+ Add stage"
    add-accommodation-button.tsx   # Bouton dashed "+ Add accommodation"
    export-pdf-button.tsx          # Bouton export PDF
  hooks/
    use-editable.ts                # Logique edition inline
    use-calendar.ts                # Logique calendrier (mois, semaines)
    use-mercure.ts                 # Connexion SSE Mercure
    use-hydration.ts               # Etat hydratation Zustand
  store/
    trip-store.ts                  # Store principal (Zustand + Immer + Persist)
    ui-store.ts                    # Etats ephemeres (pas de persist)
  lib/
    api/
      schema.d.ts                  # Types generes (openapi-typescript)
      client.ts                    # Instance openapi-fetch
    mercure/
      client.ts                    # Wrapper EventSource + reconnexion
      types.ts                     # Union discriminee des events SSE
    geocode/
      client.ts                    # Appels geocodage direct/inverse
    validation/
      schemas.ts                   # Schemas Zod alignes sur DTOs PHP
    utils.ts                       # Existant : cn() (clsx + tailwind-merge)
  tests/
    fixtures/
      mock-data.ts                 # Factories de donnees mockees
      api-mocks.ts                 # Helpers page.route() pour Playwright
      sse-helpers.ts               # Injection d'events Mercure en test
    trip-creation.spec.ts          # Creation de trip via magic link
    stage-management.spec.ts       # CRUD etapes
    trip-editing.spec.ts           # Edition titre, dates, localisations
    pdf-export.spec.ts             # Export PDF
    error-handling.spec.ts         # Erreurs reseau, validation, SSE
    local-persistence.spec.ts      # Persistence localStorage, reload
```

### Systeme de couleurs

Tokens CSS a ajouter dans `globals.css` pour etendre le theme shadcn/ui :

| Token           | Valeur    | Usage                                                    |
|-----------------|-----------|----------------------------------------------------------|
| `--brand`       | `#3AA5B9` | Timeline, calendrier, dates selectionnees, bouton export |
| `--brand-light` | `#EBF5F6` | Fond du champ magic link                                 |
| `--brand-hover` | `#2E8A9A` | Hover bouton export, liens interactifs                   |
| `--muted-icon`  | `#9DA5A7` | Icones crayon, boutons dashed, textes secondaires        |

Ces tokens sont aussi enregistres dans `@theme inline` pour generer les classes utilitaires
Tailwind : `bg-brand`, `text-brand`, `border-brand`, `bg-brand-light`, `text-muted-icon`.

### Hierarchie des composants

```text
page.tsx (Server Component)
  └─ HydrationBoundary (Client — attend hydratation Zustand)
       └─ TripPlanner (Client — orchestrateur principal)
            ├─ MagicLinkInput
            ├─ TripSummary (distance totale + elevation totale)
            ├─ TripHeader (grid 2 colonnes desktop / 1 colonne mobile)
            │    ├─ Colonne gauche (50%) :
            │    │    ├─ TripTitle (EditableField)
            │    │    ├─ LocationFields (2x EditableField + ligne verticale)
            │    │    │    └─ LocationCombobox (autocompletion Nominatim)
            │    │    └─ WeatherIndicator
            │    └─ Colonne droite (50%) :
            │         └─ CalendarWidget (compact/complet)
            ├─ Timeline
            │    ├─ [pour chaque etape :]
            │    │    ├─ TimelineMarker (cercle vide #3AA5B9)
            │    │    ├─ StageCard (Card shadcn)
            │    │    │    ├─ StageLocations (depart -> arrivee, editables)
            │    │    │    │    └─ LocationCombobox (autocompletion)
            │    │    │    ├─ StageMetadata (distance, D+, meteo)
            │    │    │    ├─ AlertList
            │    │    │    │    └─ AlertBadge (critical/warning/nudge)
            │    │    │    └─ AccommodationPanel
            │    │    │         ├─ AccommodationItem (nom, lien, prix editables)
            │    │    │         └─ AddAccommodationButton
            │    │    └─ AddStageButton (entre les etapes, pas premier/dernier)
            │    └─ TimelineMarker (arrivee finale)
            └─ ExportPdfButton
```

---

## Phase 1 : Fondations techniques

**Objectif :** Mettre en place l'infrastructure technique : client API type-safe, stores
Zustand, client Mercure SSE, schemas Zod, configuration Next.js.

### 1.1 Generation des types TypeScript (ADR-002)

**Fichier a modifier :** `pwa/package.json`

Configurer le script `typegen` pour pointer vers le fichier `openapi.json` local
(pas le serveur live, qui demande un certificat auto-signe) :

```json
{
  "scripts": {
    "typegen": "openapi-typescript ../openapi.json -o ./src/lib/api/schema.d.ts"
  }
}
```

Executer `npm run typegen` pour generer `pwa/src/lib/api/schema.d.ts`. Ce fichier
contient les types TypeScript pour toutes les operations et tous les schemas de l'API.

### 1.2 Client API openapi-fetch

**Fichier a creer :** `pwa/src/lib/api/client.ts`

Creer l'instance `openapi-fetch` typee depuis `schema.d.ts` :

- Header par defaut : `Content-Type: application/ld+json` (format API Platform)
- Header `Accept: application/ld+json`

Utilitaires de gestion d'erreurs :

- **422 ConstraintViolation** : parser `violations[]` et extraire les messages par champ
- **400 Bad Request** : parser le body RFC 7807 (`title`, `detail`)
- **404 Not Found** : trip ou etape inexistante
- **Erreur reseau** : `TypeError: Failed to fetch` -> message utilisateur

### 1.3 Configuration Next.js

**Fichier a modifier :** `pwa/next.config.ts`

```typescript
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  async rewrites() {
    return [
      {
        source: "/api/:path*",
        destination: `${process.env.API_BACKEND_URL ?? "https://php"}/:path*`,
      },
    ];
  },
};

export default nextConfig;
```

**Fichier a creer :** `pwa/.env.local`

```env
# URL interne Docker du backend PHP (pas de NEXT_PUBLIC_ = serveur seulement)
API_BACKEND_URL=https://php

# URL publique du hub Mercure (le navigateur se connecte directement)
NEXT_PUBLIC_MERCURE_URL=https://localhost/.well-known/mercure
```

### 1.4 Client Mercure SSE

**Fichier a creer :** `pwa/src/lib/mercure/types.ts`

Union discriminee TypeScript pour les 13 types d'evenements Mercure :

```typescript
type MercureEvent =
  | { type: "route_parsed"; data: { totalDistance: number; totalElevation: number; sourceType: string; title: string | null } }
  | { type: "stages_computed"; data: { stages: StagePayload[] } }
  | { type: "weather_fetched"; data: { stages: WeatherPayload[] } }
  | { type: "pois_scanned"; data: { stageIndex: number; pois: PoiPayload[] } }
  | { type: "accommodations_found"; data: { stageIndex: number; accommodations: AccommodationPayload[] } }
  | { type: "terrain_alerts"; data: { stageIndex: number; alerts: AlertPayload[] } }
  | { type: "calendar_alerts"; data: { stageIndex: number; alerts: AlertPayload[] } }
  | { type: "wind_alerts"; data: { stageIndex: number; alerts: AlertPayload[] } }
  | { type: "resupply_nudges"; data: { stageIndex: number; alerts: AlertPayload[] } }
  | { type: "bike_shop_alerts"; data: { stageIndex: number; alerts: AlertPayload[] } }
  | { type: "validation_error"; data: { code: string; message: string } }
  | { type: "computation_error"; data: { computation: string; message: string; retryable: boolean } }
  | { type: "trip_complete"; data: { computationStatus: Record<string, string> } };
```

**Fichier a creer :** `pwa/src/lib/mercure/client.ts`

Classe `MercureClient` wrappant `EventSource` :

- Constructeur : `mercureHubUrl`, `topic` (ex: `/trips/{tripId}`)
- Reconnexion automatique avec backoff exponentiel (1s, 2s, 4s, 8s, max 30s)
- Methode `onEvent(callback: (event: MercureEvent) => void)`
- Methode `close()` pour fermer proprement la connexion
- Support test E2E : ecouter `window.__test_mercure_event` (CustomEvent)
  pour injecter des events Mercure sans serveur reel

**Fichier a creer :** `pwa/src/hooks/use-mercure.ts`

Hook React `useMercure(tripId: string | null)` :

- Ouvre la connexion Mercure quand `tripId` est non-null
- Dispatche les events vers les actions du `trip-store`
- Ferme la connexion au unmount ou quand `tripId` change
- Expose `sseConnected: boolean` pour l'indicateur UI

### 1.5 Store Zustand principal (trip-store)

**Fichier a creer :** `pwa/src/store/trip-store.ts`

Structure du store (ADR-007) :

```typescript
interface StageData {
  dayNumber: number;
  distance: number;
  elevation: number;
  startPoint: { lat: number; lon: number; ele: number };
  endPoint: { lat: number; lon: number; ele: number };
  geometry: { lat: number; lon: number; ele: number }[];
  label: string | null;
  startLabel: string | null;  // Nom de ville (geocodage inverse)
  endLabel: string | null;    // Nom de ville (geocodage inverse)
  weather: WeatherData | null;
  alerts: AlertData[];
  pois: PoiData[];
  accommodations: AccommodationData[];
}

interface TripState {
  // --- Donnees persistees ---
  trip: { id: string; title: string; sourceUrl: string } | null;
  totalDistance: number | null;
  totalElevation: number | null;
  sourceType: string | null;
  startDate: string | null;    // ISO date (YYYY-MM-DD)
  endDate: string | null;      // ISO date (YYYY-MM-DD)
  stages: StageData[];
  computationStatus: Record<string, string>;

  // --- Etat hydratation (non persiste) ---
  hasHydrated: boolean;

  // --- Actions ---
  setTrip: (trip: { id: string; title: string; sourceUrl: string }) => void;
  updateRouteData: (data: {
    totalDistance: number;
    totalElevation: number;
    sourceType: string;
    title: string | null;
  }) => void;
  setStages: (stages: StageData[]) => void;
  updateStageWeather: (dayNumber: number, weather: WeatherData) => void;
  updateStagePois: (stageIndex: number, pois: PoiData[]) => void;
  updateStageAccommodations: (stageIndex: number, accs: AccommodationData[]) => void;
  updateStageAlerts: (stageIndex: number, alerts: AlertData[]) => void;
  updateStageLabel: (stageIndex: number, field: "startLabel" | "endLabel", value: string) => void;
  addLocalAccommodation: (stageIndex: number, acc: AccommodationData) => void;
  removeLocalAccommodation: (stageIndex: number, accIndex: number) => void;
  updateLocalAccommodation: (stageIndex: number, accIndex: number, data: Partial<AccommodationData>) => void;
  updateTitle: (title: string) => void;
  updateDates: (startDate: string | null, endDate: string | null) => void;
  setComputationStatus: (status: Record<string, string>) => void;
  clearTrip: () => void;
  setHasHydrated: (value: boolean) => void;
}
```

Configuration des middlewares :

```typescript
export const useTripStore = create<TripState>()(
  persist(
    immer((set) => ({
      // ... state initial + actions avec mutations directes (Immer)
    })),
    {
      name: "bike-trip-planner-storage",
      version: 1,
      partialize: (state) => {
        // Exclure hasHydrated de la persistence
        const { hasHydrated, ...rest } = state;
        return rest;
      },
      onRehydrateStorage: () => (state) => {
        state?.setHasHydrated(true);
      },
      migrate: (persisted, version) => {
        // Pret pour les futures migrations (ADR-003)
        return persisted as TripState;
      },
      // Validation Zod a la deserialization
      // Si invalide, wipe gracieux (retourne state initial)
    }
  )
);
```

### 1.6 Store UI ephemere (ui-store)

**Fichier a creer :** `pwa/src/store/ui-store.ts`

Store sans persistence pour les etats transitoires :

```typescript
interface UiState {
  isProcessing: boolean;
  sseConnected: boolean;
  expandedCalendar: boolean;
  error: { type: string; message: string } | null;

  setProcessing: (value: boolean) => void;
  setSseConnected: (value: boolean) => void;
  setExpandedCalendar: (value: boolean) => void;
  setError: (error: { type: string; message: string } | null) => void;
}
```

### 1.7 Schemas Zod de validation

**Fichier a creer :** `pwa/src/lib/validation/schemas.ts`

Schemas alignes manuellement sur les DTOs PHP (ADR-002) :

```typescript
import { z } from "zod";

export const CoordinateSchema = z.object({
  lat: z.number(),
  lon: z.number(),
  ele: z.number().default(0),
});

export const AlertSchema = z.object({
  type: z.enum(["critical", "warning", "nudge"]),
  message: z.string(),
  lat: z.number().nullable().optional(),
  lon: z.number().nullable().optional(),
});

export const WeatherForecastSchema = z.object({
  icon: z.string(),
  description: z.string(),
  tempMin: z.number(),
  tempMax: z.number(),
  windSpeed: z.number(),
  windDirection: z.string(),
  precipitationProbability: z.number(),
});

export const PointOfInterestSchema = z.object({
  name: z.string(),
  category: z.string(),
  lat: z.number(),
  lon: z.number(),
  distanceFromStart: z.number().nullable().optional(),
});

export const AccommodationSchema = z.object({
  name: z.string(),
  type: z.string(),
  lat: z.number(),
  lon: z.number(),
  estimatedPriceMin: z.number(),
  estimatedPriceMax: z.number(),
  isExactPrice: z.boolean(),
});

export const StageDataSchema = z.object({
  dayNumber: z.number(),
  distance: z.number(),
  elevation: z.number(),
  startPoint: CoordinateSchema,
  endPoint: CoordinateSchema,
  geometry: z.array(CoordinateSchema),
  label: z.string().nullable(),
  startLabel: z.string().nullable(),
  endLabel: z.string().nullable(),
  weather: WeatherForecastSchema.nullable(),
  alerts: z.array(AlertSchema),
  pois: z.array(PointOfInterestSchema),
  accommodations: z.array(AccommodationSchema),
});

export const TripStateSchema = z.object({
  trip: z.object({
    id: z.string(),
    title: z.string(),
    sourceUrl: z.string(),
  }).nullable(),
  totalDistance: z.number().nullable(),
  totalElevation: z.number().nullable(),
  sourceType: z.string().nullable(),
  startDate: z.string().nullable(),
  endDate: z.string().nullable(),
  stages: z.array(StageDataSchema),
  computationStatus: z.record(z.string()),
});
```

### 1.8 HydrationBoundary

**Fichier a creer :** `pwa/src/components/hydration-boundary.tsx`

Composant client qui bloque le rendu tant que `hasHydrated === false` dans le
`trip-store`. Affiche un `Skeleton` shadcn (pleine page) en attendant que le store
Zustand ait fini de lire `localStorage`.

```tsx
"use client";
import { useTripStore } from "@/store/trip-store";
import { Skeleton } from "@/components/ui/skeleton";

export function HydrationBoundary({ children }: { children: React.ReactNode }) {
  const hasHydrated = useTripStore((s) => s.hasHydrated);
  if (!hasHydrated) {
    return <Skeleton className="h-screen w-full" />;
  }
  return <>{children}</>;
}
```

**Livrable Phase 1 :** `npm run typegen` genere les types. Le store est fonctionnel et
persiste dans localStorage. Le client API peut appeler le backend via les rewrites Next.js.
Le client Mercure peut se connecter et dispatcher des events. Validation Zod operationnelle.

---

## Phase 2 : Composants UI (shell visuel)

**Objectif :** Construire tous les composants visuels avec des donnees mockees, conformes
aux specifications de design.

### 2.1 Installation des composants shadcn/ui

```bash
cd pwa && npx shadcn add input button card badge tooltip separator skeleton sonner popover command
```

### 2.2 Theme design personnalise

**Fichier a modifier :** `pwa/src/app/globals.css`

Ajouter les variables CSS dans `:root` et `.dark`, puis les enregistrer dans
`@theme inline` pour generer les classes utilitaires Tailwind :

```css
/* Dans :root */
--brand: #3AA5B9;
--brand-light: #EBF5F6;
--brand-hover: #2E8A9A;
--muted-icon: #9DA5A7;

/* Dans @theme inline */
--color-brand: var(--brand);
--color-brand-light: var(--brand-light);
--color-brand-hover: var(--brand-hover);
--color-muted-icon: var(--muted-icon);
```

### 2.3 Composants a implementer

Chaque composant est decrit avec sa responsabilite, ses props principales, ses classes
Tailwind cles, et ses attributs d'accessibilite.

#### 2.3.1 EditableField — Composant reutilisable central

**Fichier :** `pwa/src/components/editable-field.tsx`

**Hook :** `pwa/src/hooks/use-editable.ts`

Pattern d'edition inline : texte brut par defaut, se transforme en `Input` shadcn au clic.
L'input herite des memes classes typographiques que le texte pour une transition
imperceptible. Icone crayon `var(--muted-icon)` visible au hover (desktop) ou en permanence
(mobile via `md:opacity-0 md:group-hover:opacity-100`).

Props :

```typescript
interface EditableFieldProps {
  value: string;
  onChange: (value: string) => void;
  className?: string;         // Classes typo (text-xl, font-semibold...)
  placeholder?: string;
  "aria-label": string;
  "data-testid"?: string;
}
```

Comportement :

- **Mode affichage :** `<span>` cliquable avec `role="button"`, `tabIndex={0}`,
  `group` pour le hover de l'icone crayon (`Pencil` de lucide-react)
- **Mode edition :** `<Input>` avec `bg-transparent border-none shadow-none focus:ring-0`
- **Raccourcis :** `Enter` = sauvegarder et quitter, `Escape` = annuler et restaurer
- **Transition :** `onBlur` = sauvegarder (comme Enter)

#### 2.3.2 MagicLinkInput

**Fichier :** `pwa/src/components/magic-link-input.tsx`

Input pleine largeur pour coller un lien Komoot ou Google My Maps.

- Classes : `w-full text-xl md:text-2xl bg-brand-light rounded-full border-none
  px-6 md:px-8 py-4 md:py-6 placeholder:text-muted-foreground/60`
- Auto-detection au `onPaste` : si le texte colle matche la regex
  `^https://(?:www\.komoot\.com/.+|www\.google\.com/maps/d/.+|maps\.app\.goo\.gl/.+)`,
  declencher automatiquement la soumission
- Pendant le traitement : spinner `Loader2` anime (lucide) a droite du champ
- Erreurs : toast Sonner pour les erreurs API, message inline rouge sous le champ
  pour les erreurs de validation format
- `data-testid="magic-link-input"`
- `placeholder="Enter your Komoot link here..."`

#### 2.3.3 TripSummary

**Fichier :** `pwa/src/components/trip-summary.tsx`

- Flexbox horizontal centre sous le MagicLinkInput
- Icone `Bike` couleur brand + "Total distance: {X}km"
- Icone `Mountain` couleur `orange-500` + "Total elevation: {X}m"
- Classes : `text-sm text-muted-foreground flex items-center gap-6`
- `data-testid="total-distance"` et `data-testid="total-elevation"` sur les valeurs

#### 2.3.4 TripTitle

Utilise `EditableField` avec les classes `text-xl md:text-2xl font-semibold`.

Valeur par defaut auto-generee : un nom de figure feministe aleatoire pioche dans une
liste client-side (ex: "Annie Londonderry", "Alfonsina Strada", "Evelyne Carrer",
"Beryl Burton", "Eileen Sheridan", "Marianne Martin", "Dervla Murphy"). Le titre est
stocke dans le `trip-store` et editable par l'utilisateur.

#### 2.3.5 LocationFields

**Fichier :** `pwa/src/components/location-fields.tsx`

Deux champs d'`EditableField` (depart + arrivee) relies par un indicateur visuel vertical :

```text
  ● Depart : Paris        ✏️
  |
  |
  ● Arrivee : Chartres    ✏️
```

- Colonne gauche (20px) : point `bg-brand` (4px) en haut, ligne `border-l-2 border-brand`
  au centre, point `bg-brand` en bas
- Colonne droite : `EditableField` depart (ligne 1), `EditableField` arrivee (ligne 2)
- Si boucle (distance depart-arrivee < 1km) : icone `RefreshCw` (lucide) et arrivee
  en `text-muted-foreground/60`
- Les icones crayon s'alignent verticalement avec celle du `TripTitle`
- En mode edition : le champ est remplace par un `LocationCombobox` (autocompletion)

#### 2.3.6 LocationCombobox — Autocompletion de lieux

**Fichier :** `pwa/src/components/location-combobox.tsx`

Composant base sur shadcn `Popover` + `Command` (`CommandInput`, `CommandList`,
`CommandItem`) :

- `onValueChange` avec debounce 300ms vers `GET /geocode/search?q={query}&limit=5`
- Chaque suggestion affiche `name` (bold) et `displayName` (muted, plus petit)
- Selection -> `onChange` remonte le `Coordinate` (lat, lon) + le `name` comme label
- Fermeture automatique du popover apres selection
- Gestion du chargement (spinner dans `CommandList`) et du cas "aucun resultat"

#### 2.3.7 CalendarWidget

**Fichier :** `pwa/src/components/calendar-widget.tsx`

**Hook :** `pwa/src/hooks/use-calendar.ts`

Calendrier custom (pas le composant Calendar de shadcn, trop eloigne des specs) :

**Mode compact :**

- Affiche uniquement la semaine contenant la date de debut
- Labels des jours de la semaine en gras (Lun, Mar, ..., Dim)
- Grille 7 colonnes

**Mode complet :**

- Affiche le mois entier avec navigation (fleches `ChevronLeft`/`ChevronRight`)
- Jours du mois precedent/suivant en `text-muted-foreground/40`
- Mois + annee en gras `text-xl` en haut

**Toggle :** Fleche `ChevronDown` (compact -> complet) / `ChevronUp` (complet -> compact)
a droite du mois/annee.

**Mise en avant des dates selectionnees :**

- `startDate` et `endDate` : cercle `bg-brand text-white` avec bordure blanche epaisse
  (`ring-2 ring-white`)
- Rectangle englobant entre les deux dates : bordure `border border-brand rounded-full`
  (continue si dates consecutives, `border-dashed` si non consecutives)

**Interaction :**

- Clic sur un jour = selectionner comme `startDate` (premier clic) ou `endDate`
  (deuxieme clic)
- Changement de date -> dispatch `updateDates` dans le store + `PATCH /trips/{id}`

**Accessibilite :**

- `role="grid"`, cellules `role="gridcell"`
- Navigation clavier : fleches directionnelles
- `aria-selected` sur les dates selectionnees

#### 2.3.8 WeatherIndicator

**Fichier :** `pwa/src/components/weather-indicator.tsx`

- Classes : `text-xs text-muted-foreground/60`
- Icone meteo mappee depuis le code API : `01d`->`Sun`, `02d`->`CloudSun`,
  `03d`/`04d`->`Cloud`, `09d`/`10d`->`CloudRain`, `11d`->`CloudLightning`,
  `13d`->`Snowflake`, `50d`->`CloudFog`
- Description concise (ex: "Sunny, 18-24C")
- Marge haute significative pour separer visuellement du bloc titre/locations

#### 2.3.9 Timeline + TimelineMarker

**Fichier :** `pwa/src/components/timeline.tsx`

**Fichier :** `pwa/src/components/timeline-marker.tsx`

Structure :

```text
  ○ (TimelineMarker depart)
  │ (ligne verticale bg-brand, width 2px, position absolute)
  │
  ├── StageCard jour 1
  │
  ├── AddStageButton
  │
  ○ (TimelineMarker)
  │
  ├── StageCard jour 2
  │
  ...
  ○ (TimelineMarker arrivee)
```

- Ligne verticale : `absolute left-4 top-0 bottom-0 w-0.5 bg-brand`
- Marqueurs : cercles vides `w-4 h-4 rounded-full border-[3px] border-brand bg-background`
- Contenu des etapes : `ml-10 md:ml-16` (decale a droite de la timeline)
- `role="list"` sur la timeline, `role="listitem"` sur chaque bloc etape

#### 2.3.10 StageCard

**Fichier :** `pwa/src/components/stage-card.tsx`

- Base : `Card` shadcn avec `border-border shadow-sm rounded-xl max-w-[80%]`
- Mobile : `w-full` (pas de max-width)
- Bouton fermer `X` en position `absolute top-3 right-3` (lucide `X`, `text-muted-icon`)
- Desactive si seulement 2 etapes restantes (minimum backend)
- Contenu :
  1. `StageLocations` (depart -> arrivee)
  2. `StageMetadata` (distance, D+, meteo) — toujours visible meme si donnees manquantes
  3. `AlertList` (si alertes presentes)
  4. Separateur (`Separator` shadcn + marge haute)
  5. `AccommodationPanel`
- `data-testid="stage-card-{dayNumber}"`

#### 2.3.11 StageLocations

**Fichier :** `pwa/src/components/stage-locations.tsx`

- `EditableField` depart (bold) + icone `ArrowRight` (lucide, `text-muted-icon`) +
  `EditableField` arrivee (bold)
- Les labels affichent le nom de ville (`startLabel` / `endLabel`) ou "Unknown location"
  si pas encore geocode
- En mode edition : remplacer par `LocationCombobox` (autocompletion)
- Flexbox horizontal avec `items-center gap-2`

#### 2.3.12 StageMetadata

**Fichier :** `pwa/src/components/stage-metadata.tsx`

- Flexbox horizontal, `text-sm text-muted-foreground gap-4`
- Icone `Bike` + "{distance}km"
- Icone `Mountain` + "{elevation}m"
- Icone meteo + description courte (si meteo disponible)
- Si donnees manquantes : `Skeleton` inline (`w-16 h-4`)

#### 2.3.13 AlertBadge

**Fichier :** `pwa/src/components/alert-badge.tsx`

Variantes via CVA (class-variance-authority, inclus avec shadcn) :

| Type       | Classes                                                                    |
|------------|----------------------------------------------------------------------------|
| `critical` | `bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400`             |
| `warning`  | `bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400` |
| `nudge`    | `bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400`         |

- Classes communes : `rounded-md px-3 py-1.5 text-sm font-medium`
- Pas de bordure (conformement aux specs)
- Icones : `AlertTriangle` (critical), `AlertCircle` (warning), `Info` (nudge)
- `role="alert"` pour les alerts critical

#### 2.3.14 AlertList

**Fichier :** `pwa/src/components/alert-list.tsx`

- Liste verticale de `AlertBadge` avec `gap-2`
- Tri par severite : critical > warning > nudge
- N'apparait que si `alerts.length > 0`

#### 2.3.15 AccommodationPanel + AccommodationItem

**Fichier :** `pwa/src/components/accommodation-panel.tsx`

**Fichier :** `pwa/src/components/accommodation-item.tsx`

Panel :

- Fond `bg-muted/50 rounded-lg p-4`
- N'apparait pas avant la 1ere etape ni apres la derniere

Item :

- Nom en bold (`EditableField`)
- Lien : icone `Link2` (lucide) + `EditableField` URL, `text-sm text-muted-foreground`
- Prix : icone `Euro` (lucide) + "Xeur - Yeur", `text-sm text-muted-foreground`
- Bouton `X` pour supprimer (`absolute top-2 right-2`)
- `Separator` shadcn entre chaque item

#### 2.3.16 AddAccommodationButton

**Fichier :** `pwa/src/components/add-accommodation-button.tsx`

- `border-dashed border-muted-icon text-muted-icon`
- Texte : "+ Add accommodation"
- Toujours en bas du panneau (apres les accommodations existantes)

#### 2.3.17 AddStageButton

**Fichier :** `pwa/src/components/add-stage-button.tsx`

- `border-dashed border-muted-icon text-muted-icon w-full`
- Meme `max-width` que les `StageCard` (80% desktop, 100% mobile)
- Texte : "+ Add stage"
- N'apparait PAS avant la 1ere etape ni apres la derniere
- `data-testid="add-stage-button-{afterIndex}"`

#### 2.3.18 ExportPdfButton

**Fichier :** `pwa/src/components/export-pdf-button.tsx`

- Centre horizontalement, detache de la timeline (marge haute significative)
- Classes desktop : `bg-brand hover:bg-brand-hover text-white rounded-full px-12 py-6
  text-lg font-medium`
- Classes mobile : `w-full py-4`
- Icone `Download` (lucide) a gauche du texte "Export as PDF"
- Etats : disabled (pas de trip / computations en cours), loading (spinner `Loader2`),
  erreur (toast Sonner)
- `data-testid="export-pdf-button"`

### 2.4 Page principale

**Fichier a remplacer :** `pwa/src/app/page.tsx`

```tsx
import { HydrationBoundary } from "@/components/hydration-boundary";
import { TripPlanner } from "@/components/trip-planner";

export default function Page() {
  return (
    <HydrationBoundary>
      <TripPlanner />
    </HydrationBoundary>
  );
}
```

**Fichier a modifier :** `pwa/src/app/layout.tsx`

- `<html lang="fr">` (au lieu de "en")
- Metadata : `title: "Bike Trip Planner"`,
  `description: "Plan your bikepacking trips with ease"`

### 2.5 Design responsive

| Section             | Desktop (md: >= 768px)              | Mobile (< 768px)                  |
|---------------------|-------------------------------------|-----------------------------------|
| Container principal | `max-w-[1200px] mx-auto px-6`       | `w-full px-4`                     |
| Magic link          | `text-2xl px-8 py-6`                | `text-xl px-6 py-4`               |
| Trip header         | `grid grid-cols-2 gap-12`           | `grid grid-cols-1 gap-6`          |
| Stage cards         | `max-w-[80%] ml-16`                 | `w-full ml-10`                    |
| Icones crayon       | `opacity-0 group-hover:opacity-100` | `opacity-100` (toujours visibles) |
| Bouton export       | `px-12 py-6`                        | `w-full py-4`                     |
| Calendrier          | Mode complet par defaut             | Mode compact par defaut           |

**Livrable Phase 2 :** Tous les composants sont rendus avec des donnees en dur (mockees).
L'interface est conforme aux specifications de design sur desktop (1200px) et mobile
(375px). Pas encore de connexion API.

---

## Phase 3 : Integration API et temps reel

**Objectif :** Connecter le frontend au backend via openapi-fetch et Mercure SSE pour
obtenir un flux de creation de trip fonctionnel de bout en bout.

### 3.1 Flux de creation de trip

Sequence complete du parcours utilisateur principal :

```text
1. Utilisateur colle URL Komoot dans MagicLinkInput
2. Validation client (regex identique au backend TripRequest::$sourceUrl)
3. Si invalide : message d'erreur inline sous le champ
4. Si valide : POST /trips { sourceUrl } via openapi-fetch
5. Reponse 202 : { id, computationStatus }
6. Stocker trip.id dans le Zustand store
7. Ouvrir connexion Mercure SSE sur topic /trips/{tripId}
8. Traiter les events progressivement :

   route_parsed ──► updateRouteData (distance, elevation, title)
                     puis geocodage inverse depart/arrivee trip-level
   stages_computed ─► setStages (toutes les etapes avec coordonnees)
                      puis geocodage inverse pour chaque etape
   weather_fetched ─► updateStageWeather pour chaque etape
   pois_scanned ────► updateStagePois (par stageIndex)
   accommodations_found ► updateStageAccommodations (par stageIndex)
   terrain_alerts ──► updateStageAlerts (par stageIndex)
   calendar_alerts ─► updateStageAlerts (par stageIndex)
   wind_alerts ─────► updateStageAlerts (par stageIndex)
   resupply_nudges ─► updateStageAlerts (par stageIndex)
   bike_shop_alerts ► updateStageAlerts (par stageIndex)
   trip_complete ───► setComputationStatus + arreter le spinner
   validation_error ► toast Sonner (erreur utilisateur)
   computation_error► toast Sonner (erreur technique, retryable?)

9. L'interface se construit progressivement au fur et a mesure
   des events (skeleton -> donnees reelles)
```

### 3.2 Geocodage inverse automatique

Declenche apres reception de `stages_computed`, pour transformer les coordonnees GPS
en noms de villes affichables.

Pour chaque etape :

- `GET /geocode/reverse?lat={startPoint.lat}&lon={startPoint.lon}` -> `startLabel`
- `GET /geocode/reverse?lat={endPoint.lat}&lon={endPoint.lon}` -> `endLabel`

Pour le trip-level (composant `LocationFields`) :

- Depart = `startPoint` de l'etape 1
- Arrivee = `endPoint` de la derniere etape

Les appels sont lances en parallele (`Promise.all`) pour minimiser le temps d'attente.
Les resultats sont stockes dans le `trip-store` via `updateStageLabel`.

### 3.3 Autocompletion des lieux

**Fichier :** `pwa/src/lib/geocode/client.ts`

```typescript
export async function searchPlaces(query: string): Promise<GeocodeResult[]> {
  const res = await fetch(`/geocode/search?q=${encodeURIComponent(query)}&limit=5`);
  if (!res.ok) return [];
  const data = await res.json();
  return data.results;
}

export async function reverseGeocode(lat: number, lon: number): Promise<GeocodeResult | null> {
  const res = await fetch(`/geocode/reverse?lat=${lat}&lon=${lon}`);
  if (!res.ok) return null;
  return res.json();
}
```

Utilisation dans `LocationCombobox` et `StageLocations` :

- Debounce 300ms sur la frappe
- Affichage des suggestions dans `CommandList`
- Selection : met a jour le store avec les nouvelles coordonnees + label
- Si dans un champ de `StageLocations` : declenche `PATCH /trips/{tripId}/stages/{index}`
  avec les nouvelles coordonnees
- Si dans `LocationFields` (trip-level) : informatif uniquement (les coordonnees trip
  sont derivees des etapes)

### 3.4 Modification de trip (PATCH)

**Changement de dates (CalendarWidget) :**

1. L'utilisateur selectionne de nouvelles dates dans le calendrier
2. `updateDates(startDate, endDate)` dans le store (optimistic update)
3. `PATCH /trips/{id}` avec `{ startDate, endDate }` via openapi-fetch
4. Le backend re-declenche les calculs affectes (meteo, calendrier, eventuellement
   stages si `endDate` change le nombre de jours)
5. Marquer les computations affectees comme "pending" dans le store
6. Les composants affichent des `Skeleton` pour les donnees en cours de recalcul
7. La connexion Mercure SSE (deja ouverte) recoit les nouveaux events

**Livrable Phase 3 :** Coller une URL Komoot genere un trip complet avec affichage
progressif en temps reel. Les noms de villes sont affiches via geocodage inverse.
L'autocompletion fonctionne dans les champs de localisation. La modification des
dates declenche un recalcul.

---

## Phase 4 : Manipulation des etapes

**Objectif :** Permettre a l'utilisateur d'ajouter, modifier, supprimer et deplacer
des etapes via les endpoints `POST`/`PATCH`/`DELETE` stages.

### 4.1 Ajout d'etape

- Clic sur "+ Add stage" entre les etapes N et N+1
- Affiche un formulaire inline avec 2 `LocationCombobox` (depart, arrivee)
- Soumission -> `POST /trips/{tripId}/stages` avec `{ position: N+1, startPoint, endPoint }`
- Reponse 202 + events SSE de recalcul
- Focus automatique sur le champ depart de la nouvelle etape
- Les dayNumbers sont re-indexes automatiquement par le backend

### 4.2 Modification d'etape

- Edition des champs depart/arrivee via `EditableField` -> `LocationCombobox`
- Selection d'un lieu -> `PATCH /trips/{tripId}/stages/{index}` avec
  `{ startPoint, endPoint }` (content-type `application/merge-patch+json`)
- Le backend recalcule distance, elevation, GPX, POIs, accommodations, alertes
- Les donnees recalculees arrivent via les events SSE

### 4.3 Modification du label

- Edition du label via `EditableField` (si le backend le supporte)
- `PATCH /trips/{tripId}/stages/{index}` avec `{ label }`

### 4.4 Suppression d'etape

- Clic sur le bouton `X` de la `StageCard`
- `DELETE /trips/{tripId}/stages/{index}`
- Le backend fusionne l'etape supprimee avec l'etape adjacente
- Minimum 2 etapes : bouton `X` desactive si seulement 2 etapes restantes
  (`stages.length <= 2`)
- Re-indexation automatique des dayNumbers

### 4.5 Deplacement d'etape

- Boutons `ChevronUp` / `ChevronDown` sur chaque `StageCard` (pas de drag-and-drop
  pour le MVP)
- `ChevronUp` desactive sur la 1ere etape, `ChevronDown` desactive sur la derniere
- `PATCH /trips/{tripId}/stages/{index}/move` avec `{ toIndex }` (content-type
  `application/merge-patch+json`)
- Re-indexation et recalcul automatiques cote backend

### 4.6 Gestion locale des hebergements

Les hebergements detectes par le backend (via OSM) sont affiches automatiquement.
L'utilisateur peut egalement :

- **Ajouter** un hebergement via "+ Add accommodation" -> entree vide editable dans le store
- **Modifier** un hebergement (nom, lien, prix) inline via `EditableField` -> mise a jour
  dans le store uniquement (pas d'endpoint API pour les hebergements manuels)
- **Supprimer** un hebergement via bouton `X` -> `removeLocalAccommodation` dans le store

Les modifications locales sont persistees dans localStorage via Zustand persist.

**Livrable Phase 4 :** L'utilisateur peut ajouter, modifier, supprimer et deplacer
des etapes. Les hebergements sont editables localement. Toutes les actions declenchent
les recalculs backend necessaires.

---

## Phase 5 : Export PDF

**Objectif :** Permettre a l'utilisateur de telecharger le roadbook PDF genere par
Gotenberg.

### 5.1 Integration de l'export

Flux :

1. Clic sur "Export as PDF" (`ExportPdfButton`)
2. Construire le payload depuis le store :
   `{ stages: [...stagesFromStore], title: trip.title }`
3. `POST /export-pdf` avec `Content-Type: application/json` (pas `application/ld+json`)
4. Reponse : stream binaire PDF
5. Creer un `Blob` depuis la reponse, generer une URL via `URL.createObjectURL`
6. Creer un element `<a>` temporaire avec `download="Roadbook_{tripTitle}.pdf"`
7. Clic programmatique -> telechargement du fichier
8. Liberer l'URL avec `URL.revokeObjectURL`

### 5.2 Etats du bouton

| Etat                  | Apparence                                               |
|-----------------------|---------------------------------------------------------|
| Inactif (pas de trip) | `disabled opacity-50 cursor-not-allowed`                |
| Computations en cours | `disabled` + texte "Computing..."                       |
| Pret                  | `bg-brand hover:bg-brand-hover cursor-pointer`          |
| Chargement PDF        | Spinner `Loader2` + texte "Generating..."               |
| Erreur                | Toast Sonner "PDF generation failed. Please try again." |

**Livrable Phase 5 :** Le bouton export genere et telecharge un PDF complet du roadbook.

---

## Phase 6 : Tests E2E Playwright

**Objectif :** Suite de tests automatises couvrant les parcours utilisateur critiques,
conformement a ADR-009.

### 6.1 Configuration Playwright

**Fichier a modifier :** `pwa/playwright.config.ts`

```typescript
import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./tests",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [["html"], ["list"]],
  timeout: 60_000,

  use: {
    baseURL: "http://localhost:3000",
    ignoreHTTPSErrors: true,
    screenshot: "only-on-failure",
    trace: "on-first-retry",
  },

  projects: [
    { name: "chromium", use: { ...devices["Desktop Chrome"] } },
    { name: "firefox", use: { ...devices["Desktop Firefox"] } },
    { name: "webkit", use: { ...devices["Desktop Safari"] } },
    { name: "mobile-chrome", use: { ...devices["Pixel 5"] } },
    { name: "mobile-safari", use: { ...devices["iPhone 12"] } },
  ],

  webServer: {
    command: "npm run dev",
    url: "http://localhost:3000",
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
});
```

### 6.2 Strategie de mock

**Approche mock-first** (pas de dependance au backend reel pendant les tests E2E) :

**Fichier :** `pwa/tests/fixtures/api-mocks.ts`

- Fonctions utilitaires basees sur `page.route()` pour intercepter les appels API
- Chaque mock retourne des donnees conformes au schema OpenAPI
- Exemple : `mockCreateTrip(page)` intercepte `POST /trips` et retourne un
  payload 202 avec un `tripId` fixe

**Fichier :** `pwa/tests/fixtures/sse-helpers.ts`

- Fonction `injectMercureEvent(page, event: MercureEvent)` qui execute
  `page.evaluate()` pour dispatcher un `CustomEvent` sur `window.__test_mercure_event`
- Permet de simuler les events Mercure sans serveur reel
- Sequence `simulateFullTripComputation(page)` : enchaine tous les events dans l'ordre
  (`route_parsed` -> `stages_computed` -> `weather_fetched` -> ... -> `trip_complete`)

**Fichier :** `pwa/tests/fixtures/mock-data.ts`

- Factories typees : `createMockStage()`, `createMockAlert()`,
  `createMockAccommodation()`, `createMockWeather()`
- Donnees realistes (coordonnees en France, distances plausibles)

### 6.3 Fichiers de test

#### `trip-creation.spec.ts`

| Scenario                            | Description                                                                     |
|-------------------------------------|---------------------------------------------------------------------------------|
| Saisie URL valide Komoot Tour       | Coller URL, verifier spinner, simuler events SSE, verifier affichage progressif |
| Saisie URL valide Komoot Collection | Idem avec multi-etapes                                                          |
| URL invalide                        | Verifier message erreur inline                                                  |
| Erreur reseau                       | Simuler `page.route` qui echoue, verifier toast erreur                          |
| Events SSE progressifs              | Injecter events un par un, verifier que chaque section apparait                 |

#### `stage-management.spec.ts`

| Scenario            | Description                                           |
|---------------------|-------------------------------------------------------|
| Ajout d'etape       | Clic "+ Add stage", saisir lieux, verifier apparition |
| Suppression d'etape | Clic X, verifier disparition et re-indexation         |
| Deplacement d'etape | Clic chevron up/down, verifier nouvel ordre           |
| Minimum 2 etapes    | Verifier que X est desactive avec 2 etapes            |

#### `trip-editing.spec.ts`

| Scenario                  | Description                                                |
|---------------------------|------------------------------------------------------------|
| Modifier le titre         | Clic, saisir, Enter, verifier nouveau texte                |
| Modifier les dates        | Clic calendrier, selectionner dates, verifier PATCH envoye |
| Modifier une localisation | Clic, autocompletion, selection, verifier PATCH stage      |

#### `pdf-export.spec.ts`

| Scenario         | Description                                                        |
|------------------|--------------------------------------------------------------------|
| Export reussi    | Clic export, intercepter POST /export-pdf, verifier telechargement |
| Bouton desactive | Verifier disabled quand pas de trip                                |
| Erreur export    | Simuler erreur 500, verifier toast                                 |

#### `error-handling.spec.ts`

| Scenario              | Description                                         |
|-----------------------|-----------------------------------------------------|
| Erreur validation 422 | Simuler reponse 422, verifier messages par champ    |
| Erreur computation    | Injecter event `computation_error`, verifier toast  |
| Deconnexion SSE       | Simuler fermeture EventSource, verifier reconnexion |

#### `local-persistence.spec.ts`

| Scenario                     | Description                                                      |
|------------------------------|------------------------------------------------------------------|
| Persistence apres reload     | Generer trip, `page.reload()`, verifier donnees intactes         |
| Corruption localStorage      | Injecter JSON invalide dans localStorage, verifier wipe gracieux |
| Nouveau trip ecrase l'ancien | Generer 2 trips, verifier que seul le dernier est affiche        |

### 6.4 Conventions data-testid

| Element                     | data-testid                     |
|-----------------------------|---------------------------------|
| Champ magic link            | `magic-link-input`              |
| Distance totale             | `total-distance`                |
| Elevation totale            | `total-elevation`               |
| Titre du trip               | `trip-title`                    |
| Localisation depart (trip)  | `trip-departure`                |
| Localisation arrivee (trip) | `trip-arrival`                  |
| Carte d'etape N             | `stage-card-{N}`                |
| Depart etape N              | `stage-{N}-departure`           |
| Arrivee etape N             | `stage-{N}-arrival`             |
| Bouton ajout etape          | `add-stage-button-{afterIndex}` |
| Bouton suppression etape N  | `delete-stage-{N}`              |
| Bouton export PDF           | `export-pdf-button`             |
| Spinner global              | `loading-spinner`               |
| Toast erreur                | `error-toast`                   |

**Livrable Phase 6 :** Suite E2E verte sur 3 navigateurs desktop (Chromium, Firefox,
WebKit) + 2 viewports mobiles (Pixel 5, iPhone 12).

---

## Phase 7 : Polish et accessibilite

**Objectif :** Finaliser l'experience utilisateur avec une gestion robuste des etats
de chargement, des erreurs, et de l'accessibilite.

### 7.1 Accessibilite (WCAG 2.1 AA)

- `role="grid"` + navigation clavier (fleches) sur le `CalendarWidget`
- `role="list"` / `role="listitem"` sur la `Timeline` / `StageCards`
- `role="alert"` pour les `AlertBadge` de type `critical`
- `aria-label` descriptif sur tous les elements interactifs (boutons, champs editables)
- `aria-live="polite"` sur les zones qui se mettent a jour en temps reel (stage cards)
- Focus management : apres ajout d'etape, focus automatique sur le champ depart de la
  nouvelle etape
- Contraste couleurs : verifier que les combinaisons brand/blanc respectent le ratio 4.5:1
- Skip link "Skip to timeline" en haut de page pour les lecteurs d'ecran

### 7.2 Etats de chargement

| Contexte | Indicateur |
|---|---|
| Hydratation Zustand (localStorage) | `Skeleton` pleine page (< 50ms) |
| Traitement magic link | `Loader2` anime dans le champ |
| Events SSE en cours | `Skeleton` par section (etapes, meteo, POIs) |
| Recalcul apres PATCH | `Skeleton` sur les sections affectees |
| Export PDF | `Loader2` dans le bouton + texte "Generating..." |

### 7.3 Gestion d'erreurs

| Erreur | Traitement |
|---|---|
| URL invalide (format) | Message inline rouge sous le MagicLinkInput |
| 400/422 API | Toast Sonner avec messages de violation |
| 404 Trip introuvable | Toast Sonner + proposition de creer un nouveau trip |
| Erreur reseau | Toast Sonner "Network error. Please check your connection." |
| `validation_error` SSE | Toast Sonner avec le message du backend |
| `computation_error` SSE | Toast Sonner "Computation failed: {message}" |
| Deconnexion SSE | Reconnexion automatique (backoff exponentiel), indicateur visuel discret |
| localStorage corrompu | Wipe gracieux (ADR-003), retour a l'ecran initial |

**Livrable Phase 7 :** Application accessible (clavier, lecteur d'ecran), robuste
(erreurs gerees gracieusement), avec etats de chargement progressifs et feedback
utilisateur clair.

---

## Recapitulatif des fichiers

### Backend (API) — 4 fichiers a creer, 4 a modifier

| Fichier                                                | Action                             |
|--------------------------------------------------------|------------------------------------|
| `api/src/ApiResource/GeocodeResult.php`                | Creer                              |
| `api/src/Controller/GeocodeController.php`             | Creer                              |
| `api/tests/Functional/GeocodeTest.php`                 | Creer                              |
| `api/config/packages/framework.php`                    | Modifier (scoped client Nominatim) |
| `api/src/MessageHandler/FetchAndParseRouteHandler.php` | Modifier (publier title)           |
| `api/src/MessageHandler/GenerateStagesHandler.php`     | Modifier (stages complets)         |
| `api/src/MessageHandler/FetchWeatherHandler.php`       | Modifier (meteo par etape)         |
| `api/src/Repository/TripRequestRepository.php`         | Modifier (storeTitle/getTitle)     |

### Frontend (PWA) — ~35 fichiers a creer, 5 a modifier

| Fichier                                           | Action                     |
|---------------------------------------------------|----------------------------|
| `pwa/package.json`                                | Modifier (script typegen)  |
| `pwa/.env.local`                                  | Creer                      |
| `pwa/next.config.ts`                              | Modifier (rewrites, env)   |
| `pwa/playwright.config.ts`                        | Modifier (config complete) |
| `pwa/src/app/globals.css`                         | Modifier (variables brand) |
| `pwa/src/app/layout.tsx`                          | Modifier (metadata, lang)  |
| `pwa/src/app/page.tsx`                            | Remplacer                  |
| `pwa/src/lib/api/client.ts`                       | Creer                      |
| `pwa/src/lib/mercure/client.ts`                   | Creer                      |
| `pwa/src/lib/mercure/types.ts`                    | Creer                      |
| `pwa/src/lib/geocode/client.ts`                   | Creer                      |
| `pwa/src/lib/validation/schemas.ts`               | Creer                      |
| `pwa/src/store/trip-store.ts`                     | Creer                      |
| `pwa/src/store/ui-store.ts`                       | Creer                      |
| `pwa/src/hooks/use-editable.ts`                   | Creer                      |
| `pwa/src/hooks/use-calendar.ts`                   | Creer                      |
| `pwa/src/hooks/use-mercure.ts`                    | Creer                      |
| `pwa/src/hooks/use-hydration.ts`                  | Creer                      |
| `pwa/src/components/hydration-boundary.tsx`       | Creer                      |
| `pwa/src/components/trip-planner.tsx`             | Creer                      |
| `pwa/src/components/magic-link-input.tsx`         | Creer                      |
| `pwa/src/components/trip-summary.tsx`             | Creer                      |
| `pwa/src/components/trip-header.tsx`              | Creer                      |
| `pwa/src/components/trip-title.tsx`               | Creer                      |
| `pwa/src/components/location-fields.tsx`          | Creer                      |
| `pwa/src/components/location-combobox.tsx`        | Creer                      |
| `pwa/src/components/weather-indicator.tsx`        | Creer                      |
| `pwa/src/components/calendar-widget.tsx`          | Creer                      |
| `pwa/src/components/editable-field.tsx`           | Creer                      |
| `pwa/src/components/timeline.tsx`                 | Creer                      |
| `pwa/src/components/timeline-marker.tsx`          | Creer                      |
| `pwa/src/components/stage-card.tsx`               | Creer                      |
| `pwa/src/components/stage-locations.tsx`          | Creer                      |
| `pwa/src/components/stage-metadata.tsx`           | Creer                      |
| `pwa/src/components/alert-list.tsx`               | Creer                      |
| `pwa/src/components/alert-badge.tsx`              | Creer                      |
| `pwa/src/components/accommodation-panel.tsx`      | Creer                      |
| `pwa/src/components/accommodation-item.tsx`       | Creer                      |
| `pwa/src/components/add-stage-button.tsx`         | Creer                      |
| `pwa/src/components/add-accommodation-button.tsx` | Creer                      |
| `pwa/src/components/export-pdf-button.tsx`        | Creer                      |
| `pwa/tests/fixtures/mock-data.ts`                 | Creer                      |
| `pwa/tests/fixtures/api-mocks.ts`                 | Creer                      |
| `pwa/tests/fixtures/sse-helpers.ts`               | Creer                      |
| `pwa/tests/trip-creation.spec.ts`                 | Creer                      |
| `pwa/tests/stage-management.spec.ts`              | Creer                      |
| `pwa/tests/trip-editing.spec.ts`                  | Creer                      |
| `pwa/tests/pdf-export.spec.ts`                    | Creer                      |
| `pwa/tests/error-handling.spec.ts`                | Creer                      |
| `pwa/tests/local-persistence.spec.ts`             | Creer                      |

### Fichiers existants reutilises

| Fichier                                 | Reutilisation                                       |
|-----------------------------------------|-----------------------------------------------------|
| `pwa/src/lib/utils.ts`                  | Fonction `cn()` (clsx + tailwind-merge)             |
| `pwa/src/components/theme-provider.tsx` | ThemeProvider (next-themes)                         |
| `pwa/components.json`                   | Config shadcn/ui (style new-york, base sky, lucide) |
| `openapi.json`                          | Source pour la generation des types TypeScript      |

---

## Verification

### Tests automatises

```bash
make qa           # PHPStan + PHP-CS-Fixer + ESLint + Prettier + TypeScript strict
make test-php     # PHPUnit (endpoints geocodage + handlers modifies)
make test-e2e     # Playwright (6 fichiers de test, 5 projets navigateur)
```

### Verification manuelle

1. `make start-dev` puis ouvrir `https://localhost`
2. Coller `https://www.komoot.com/tour/123456` dans le champ magic link
3. Verifier le chargement progressif (spinner -> distance/elevation -> etapes ->
   meteo -> POIs -> accommodations -> alertes -> "trip complete")
4. Verifier les noms de villes dans les localisations (geocodage inverse)
5. Tester l'autocompletion dans un champ de localisation (taper "Paris")
6. Ajouter une etape via "+ Add stage" et verifier le recalcul
7. Supprimer une etape via X et verifier la fusion
8. Deplacer une etape via les chevrons et verifier la re-indexation
9. Modifier les dates dans le calendrier et verifier le recalcul
10. Cliquer "Export as PDF" et verifier le telechargement du roadbook
11. Recharger la page et verifier la persistence localStorage
12. Tester sur viewport mobile (375px) et verifier le layout responsive
13. Verifier la navigation clavier (Tab, Enter, Escape, fleches dans le calendrier)
14. Ouvrir les DevTools React et verifier l'absence de re-renders inutiles
