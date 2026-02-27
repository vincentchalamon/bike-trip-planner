# Plan de developpement API — Bike Trip Planner (Lot 1)

## Contexte

L'infrastructure backend (Docker/Caddy, Makefile, PHPStan Level 9, PHP-CS-Fixer,
PHPUnit 13, Rector, Gotenberg) est configuree. Le fichier `api/src/Kernel.php` est le seul
code PHP — toute la logique metier est a developper.

**Architecture choisie : Pipeline Mercure temps reel (async, event-driven)**

- `POST /trips` cree le trip, dispatche des jobs Messenger, retourne 202 Accepted
- Les workers traitent les calculs en parallele et publient les resultats via Mercure SSE
- `PATCH /trips/{id}` met a jour les parametres, ne re-declenche que les calculs affectes
- Le frontend souscrit a un topic Mercure unique et recoit les updates progressivement

**Sources d'URL supportees :**

- **Komoot Tour** : `https://www.komoot.com/(fr-fr/)?tour/\d+` — 1 itineraire GPX
- **Komoot Collection** : `https://www.komoot.com/(fr-fr/)?collection/\d+` — chaque tour = 1
  etape (bypass PacingEngine)
- **Google My Maps** : `https://www.google.com/maps/d/...` — export KML
- **Google Maps short links** : `https://maps.app.goo.gl/...` — resolution redirect — KML

**Choix de design :**

- Pas de `bikeType` — les alertes terrain sont generiques (surface, elevation, trafic)
- Dates = `startDate` + `endDate` (le nombre de jours est calcule)
- Fatigue cumulative (`fatigueFactor`) et penalite d'elevation (`elevationPenalty`)
  configurables par l'utilisateur
- Chaque etape genere un fichier GPX valide decoupe depuis l'itineraire original
- **Manipulation des etapes** : l'utilisateur peut ajouter, modifier, reordonner et supprimer
  des etapes. Les modifications declenchent le recalcul des donnees induites (GPX, POIs,
  hebergements, alertes, meteo)
- **Validation async** : les erreurs metier detectees pendant le calcul (ex: 1 seule etape,
  route trop courte) sont publiees via Mercure comme events `validation_error`
- **Alertes extensibles** : architecture taggee + documentation pour faciliter l'ajout de
  nouvelles regles d'alerte

---

## Graphe de dependances des calculs

```text
POST /trips (sourceUrl)
  |
  v
FetchAndParseRoute --publish--> route_parsed
  |    (Komoot GPX / KML Google My Maps / Komoot Collection multi-GPX)
  |
  +-- [Komoot Tour / Google My Maps] --> GenerateStages (PacingEngine)
  |                                         |
  +-- [Komoot Collection] --> Stages pre-definies (1 tour = 1 etape)
                                |
                      --publish--> stages_computed
                              |
  +-----------------------------+
  |                             |
  v                             v
GenerateStageGpx          ScanPois --> CheckResupply
  |                             |
  publish: stage_gpx_ready      publish: pois_scanned / resupply_nudges
  |
  +--> ScanAccommodations --publish--> accommodations_found
  +--> AnalyzeTerrain --publish--> terrain_alerts
  +--> CheckBikeShops --publish--> bike_shop_alerts (si jours > 5)

PATCH /trips/{id} (startDate et/ou endDate)
  +--> [si endDate change] GenerateStages --cascade--> tout le sous-arbre
  +--> FetchWeather --publish--> weather_fetched
  |       +--> AnalyzeWind --publish--> wind_alerts
  +--> CheckCalendar --publish--> calendar_alerts

PATCH /trips/{id} (fatigueFactor / elevationPenalty)
  +--> GenerateStages --cascade--> tout le sous-arbre

POST /trips/{id}/stages (position, startPoint, endPoint)
  +--> Ajout etape manuelle -> GPX (vide), POIs, Accommodations, Terrain, Continuite
       +--> Weather/Calendar si dates presentes

PATCH /trips/{id}/stages/{index} (startPoint, endPoint, ...)
  +--> Modification etape -> recalcul GPX, POIs, Accommodations, Terrain, Continuite
       +--> Weather/Calendar si dates presentes

PATCH /trips/{id}/stages/{index}/move (toIndex)
  +--> Deplacement etape -> re-indexation dayNumber, Continuite
       +--> Weather/Calendar pour toutes les etapes si dates presentes

DELETE /trips/{id}/stages/{index}
  +--> Fusion avec etape adjacente -> recalcul etape fusionnee, Continuite

---- Validation async (publiee via Mercure) ----
GenerateStages     -> si < 2 etapes    -> publish validation_error "Minimum 2 etapes requises"
FetchAndParseRoute -> si route vide    -> publish validation_error "Itineraire vide"
TripPatchProcessor -> si endDate < startDate -> 422 sync (pas async)
```

---

## Etape 1 — Infrastructure Docker + dependances Composer

**Objectif :** Ajouter Mercure hub, Redis et les packages Symfony.

### Fichiers a modifier

| Fichier                  | Modification                                                                                                                                     |
|--------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------|
| `compose.yaml`           | Services `mercure` (`dunglas/mercure`, port 9090) et `redis` (`redis:7-alpine`, port 6379)                                                       |
| `api/composer.json`      | `composer require symfony/mercure-bundle symfony/messenger symfony/redis-messenger symfony/uid mjaschen/phpgeo azuyalabs/yasumi`                 |
| `api/config/bundles.php` | Ajouter `MercureBundle::class => ['all' => true]`                                                                                                |
| `api/.env`               | `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET`, `MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages`, `REDIS_URL=redis://redis:6379` |
| `api/.env.test`          | `MESSENGER_TRANSPORT_DSN=in-memory://`, `MERCURE_JWT_SECRET=test-secret`                                                                         |
| `Makefile`               | Target `worker` : `messenger:consume async --time-limit=3600 -vv`                                                                                |

### Verification

```bash
docker compose up --wait   # 5 services healthy
docker compose exec php php bin/console about
```

---

## Etape 2 — Configuration Symfony (Mercure, Messenger, Cache, HTTP clients)

**Objectif :** Configurer Mercure hub, transport Messenger Redis, pools cache, clients HTTP
scopes.

### Fichiers a creer

| Fichier                         | Contenu                                                                                                           |
|---------------------------------|-------------------------------------------------------------------------------------------------------------------|
| `config/packages/mercure.php`   | Hub default, JWT secret, publish `['*']`                                                                          |
| `config/packages/messenger.php` | Transport Redis async, failure transport, retry 3x (1s/2s/4s), routing des 12 messages. Test env : `in-memory://` |

### Fichiers a modifier

| Fichier                         | Modification                                                                                                                                                                                                                                 |
|---------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `config/packages/cache.php`     | Pool `cache.trip_state` (Redis, TTL 30min), `cache.osm` (filesystem, 24h), `cache.weather` (filesystem, 3h)                                                                                                                                  |
| `config/packages/framework.php` | Ajouter dans `scoped_clients` : `overpass.client` (base_uri `overpass-api.de`, timeout 30s), `weather.client` (base_uri `openweathermap.org`, timeout 10s), `google_mymaps.client` (base_uri `www.google.com`, timeout 15s, max_redirects 5) |

### Details routing Messenger

```php
'routing' => [
    'App\Message\FetchAndParseRoute' => 'async',
    'App\Message\GenerateStages' => 'async',
    'App\Message\GenerateStageGpx' => 'async',
    'App\Message\ScanPois' => 'async',
    'App\Message\ScanAccommodations' => 'async',
    'App\Message\AnalyzeTerrain' => 'async',
    'App\Message\FetchWeather' => 'async',
    'App\Message\CheckCalendar' => 'async',
    'App\Message\AnalyzeWind' => 'async',
    'App\Message\CheckResupply' => 'async',
    'App\Message\CheckBikeShops' => 'async',
    'App\Message\RecalculateStages' => 'async',
],
```

### Verification

```bash
make phpstan
php bin/console debug:config framework messenger
php bin/console debug:config mercure
```

---

## Etape 3 — DTOs : Enums, Model objects, ApiResource

**Objectif :** Contrat de donnees complet pour OpenAPI (ADR-002).

### Fichiers a creer

| Fichier                                     | Role                                                                                                                                                                                               |
|---------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `src/Enum/SourceType.php`                   | Enum : `komoot_tour`, `komoot_collection`, `google_mymaps`                                                                                                                                         |
| `src/Enum/AlertType.php`                    | Enum : `critical`, `warning`, `nudge`                                                                                                                                                              |
| `src/Enum/ComputationName.php`              | Enum : `route`, `stages`, `stage_gpx`, `pois`, `accommodations`, `terrain`, `weather`, `calendar`, `wind`, `resupply`, `bike_shops`                                                                |
| `src/ApiResource/Model/Coordinate.php`      | Value object readonly : `lat`, `lon`, `ele` (float, defaut 0.0)                                                                                                                                    |
| `src/ApiResource/Model/Alert.php`           | Readonly : `AlertType $type`, `string $message`, `?float $lat`, `?float $lon`                                                                                                                      |
| `src/ApiResource/Model/PointOfInterest.php` | Readonly : `name`, `category`, `lat`, `lon`, `?distanceFromStart`                                                                                                                                  |
| `src/ApiResource/Model/Accommodation.php`   | Readonly : `name`, `type`, `lat`, `lon`, `estimatedPriceMin`, `estimatedPriceMax`, `isExactPrice`                                                                                                  |
| `src/ApiResource/Model/WeatherForecast.php` | Readonly : `icon`, `description`, `tempMin`, `tempMax`, `windSpeed`, `windDirection`, `precipitationProbability`                                                                                   |
| `src/ApiResource/Model/Stage.php`           | `dayNumber`, `distance`, `elevation`, `startPoint`, `endPoint`, `geometry`. Arrays mutables via `addAlert()`, `addPoi()`, `addAccommodation()`. `?WeatherForecast $weather`, `?string $gpxContent` |
| `src/ApiResource/TripRequest.php`           | Voir details ci-dessous                                                                                                                                                                            |
| `src/ApiResource/TripResponse.php`          | Readonly : `id` (UUID), `mercureTopic`, `mercureHubUrl`, `computationStatus` map                                                                                                                   |

### Details TripRequest

```php
#[ApiResource(
    shortName: 'Trip',
    operations: [
        new Post(uriTemplate: '/trips', status: 202, output: TripResponse::class,
                 processor: TripCreateProcessor::class),
        new Patch(uriTemplate: '/trips/{id}', status: 202, output: TripResponse::class,
                  provider: TripStateProvider::class, processor: TripPatchProcessor::class),
    ],
)]
final class TripRequest
{
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Url]
    public ?string $sourceUrl = null;
    // Validation custom : Komoot tour/collection OU Google My Maps OU maps.app.goo.gl

    public ?\DateTimeImmutable $startDate = null;

    #[Assert\GreaterThan(propertyPath: 'startDate', message: 'End date must be after start date.')]
    public ?\DateTimeImmutable $endDate = null;
    // Nombre de jours calcule : endDate - startDate + 1
    // Si endDate absent, defaut auto-calcule depuis la distance (ceil(distance/80))

    #[Assert\Range(min: 0.5, max: 1.0)]
    public float $fatigueFactor = 0.9;
    // Facteur de fatigue cumulative (0.9 = -10%/jour). Configurable par l'utilisateur.

    #[Assert\Positive]
    public float $elevationPenalty = 50.0;
    // Diviseur de penalite d'elevation (50 = -1km par 50m D+). Configurable.
}
```

**Validation d'URL custom** (`#[Callback]` ou custom Constraint) :

- Komoot Tour : `^https://www\.komoot\.com/([a-z]{2}-[a-z]{2}/)?tour/\d+`
- Komoot Collection : `^https://www\.komoot\.com/([a-z]{2}-[a-z]{2}/)?collection/\d+`
- Google My Maps : `^https://www\.google\.com/maps/d/`
- Google short link : `^https://maps\.app\.goo\.gl/`

**Validation synchrone** (retour 422 immediat) :

- `endDate < startDate` — erreur Symfony Validator `#[GreaterThan]`
- URL hors domaines autorises — erreur custom Constraint
- `fatigueFactor` hors range — erreur `#[Range]`

**Validation async** (publiee via Mercure comme `validation_error`) :

- Route vide (GPX/KML sans trackpoints) — detectee par `FetchAndParseRouteHandler`
- Moins de 2 etapes generees — detectee par `GenerateStagesHandler`
- Route trop courte pour le nombre de jours — detectee par `PacingEngine`

### Ressource pour la manipulation des etapes

```php
#[ApiResource(
    shortName: 'Stage',
    operations: [
        new Post(uriTemplate: '/trips/{tripId}/stages', status: 202,
                 processor: StageCreateProcessor::class,
                 openapi: new Operation(summary: 'Add a manual stage at a given position.')),
        new Patch(uriTemplate: '/trips/{tripId}/stages/{index}', status: 202,
                  processor: StageUpdateProcessor::class,
                  openapi: new Operation(summary: 'Update stage data (start/end points, etc.).')),
        new Patch(uriTemplate: '/trips/{tripId}/stages/{index}/move', status: 202,
                  processor: StageMoveProcessor::class,
                  openapi: new Operation(summary: 'Move a stage to a new position.')),
        new Delete(uriTemplate: '/trips/{tripId}/stages/{index}', status: 202,
                   processor: StageDeleteProcessor::class,
                   openapi: new Operation(summary: 'Delete a stage (merge with adjacent).')),
    ],
)]
final class StageOperation
{
    #[Assert\PositiveOrZero]
    public ?int $position = null;

    public ?Coordinate $startPoint = null;
    public ?Coordinate $endPoint = null;
    public ?string $label = null;

    #[Assert\PositiveOrZero]
    public ?int $toIndex = null;
}
```

### Tests

- `tests/ApiResource/TripRequestValidationTest.php` — URLs valides/invalides,
  endDate < startDate — 422, ranges. `#[DataProvider]`
- `tests/ApiResource/Model/StageTest.php` — `addAlert()`, `addPoi()`, `addAccommodation()`

### Verification

```bash
make qa
php bin/console debug:router
```

---

## Etape 4 — Couche d'etat Redis (TripStateManager, ComputationTracker)

**Objectif :** Etat temporaire du trip entre handlers Messenger via Redis cache (TTL 30min).

### Fichiers a creer

| Fichier                                       | Role                                                                                                                                                                                                                                                                                |
|-----------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `src/State/TripStateManager.php`              | CRUD Redis : cles separees (`trip.{id}.request`, `trip.{id}.stages`, `trip.{id}.raw_points`, etc.). TTL 30min rafraichi a chaque acces. Methodes : `initializeTrip()`, `getRequest()`, `storeRawPoints()`, `storeDecimatedPoints()`, `storeStages()`, `getStages()`, `refreshTtl()` |
| `src/State/ComputationTracker.php`            | Statut par calcul (pending/running/done/failed). `initializeComputations()`, `markRunning()`, `markDone()`, `markFailed()`, `isAllComplete()`, `resetComputation()`                                                                                                                 |
| `src/State/IdempotencyChecker.php`            | Hash des parametres — skip si inchange                                                                                                                                                                                                                                              |
| `src/State/ComputationDependencyResolver.php` | Graphe de dependances parametres — calculs                                                                                                                                                                                                                                          |

### Details ComputationDependencyResolver

```php
private const array PARAMETER_DEPENDENCIES = [
    'sourceUrl'        => [ComputationName::Route],  // cascade tout
    'endDate'          => [ComputationName::Stages],  // cascade sous-arbre
    'startDate'        => [ComputationName::Weather, ComputationName::Calendar],
    'fatigueFactor'    => [ComputationName::Stages],  // cascade sous-arbre
    'elevationPenalty' => [ComputationName::Stages],  // cascade sous-arbre
];
```

Methode `resolve(TripRequest $old, TripRequest $new): list<ComputationName>` — retourne les
racines a re-dispatcher. Deduplique : si `sourceUrl` change, `Route` suffit (cascade tout).

### Tests

- `tests/State/ComputationDependencyResolverTest.php` — Meme URL + nouvelle endDate —
  `[Stages]`. Nouvelle URL — `[Route]`. Nouvelles dates seules — `[Weather, Calendar]`. Rien
  change — `[]`
- `tests/State/TripStateManagerTest.php` — Mock CacheInterface

### Verification

```bash
make qa && make phpunit -- --filter=State
```

---

## Etape 5 — Mercure Publisher + Messages Messenger

**Objectif :** Service de publication Mercure + toutes les classes Message.

### Fichiers a creer

| Fichier                                             | Role                                                                                                                                                                                                                                                                        |
|-----------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `src/Mercure/MercureEventType.php`                  | Enum : `route_parsed`, `stages_computed`, `stage_gpx_ready`, `pois_scanned`, `accommodations_found`, `terrain_alerts`, `weather_fetched`, `calendar_alerts`, `wind_alerts`, `resupply_nudges`, `bike_shop_alerts`, `validation_error`, `computation_error`, `trip_complete` |
| `src/Mercure/TripUpdatePublisher.php`               | Publie sur topic `/trips/{tripId}` avec payload `{type, data}`                                                                                                                                                                                                              |
| `src/Message/FetchAndParseRoute.php`                | `readonly class { string $tripId }`                                                                                                                                                                                                                                         |
| `src/Message/GenerateStages.php`                    | idem                                                                                                                                                                                                                                                                        |
| `src/Message/GenerateStageGpx.php`                  | idem                                                                                                                                                                                                                                                                        |
| `src/Message/ScanPois.php`                          | idem                                                                                                                                                                                                                                                                        |
| `src/Message/ScanAccommodations.php`                | idem                                                                                                                                                                                                                                                                        |
| `src/Message/AnalyzeTerrain.php`                    | idem                                                                                                                                                                                                                                                                        |
| `src/Message/FetchWeather.php`                      | idem                                                                                                                                                                                                                                                                        |
| `src/Message/CheckCalendar.php`                     | idem                                                                                                                                                                                                                                                                        |
| `src/Message/AnalyzeWind.php`                       | idem                                                                                                                                                                                                                                                                        |
| `src/Message/CheckResupply.php`                     | idem                                                                                                                                                                                                                                                                        |
| `src/Message/CheckBikeShops.php`                    | idem                                                                                                                                                                                                                                                                        |
| `src/Message/RecalculateStages.php`                 | `readonly class { string $tripId, list<int> $affectedIndices, bool $checkContinuity }`                                                                                                                                                                                      |
| `src/MessageHandler/AbstractTripMessageHandler.php` | `executeWithTracking()` : markRunning — execute — markDone / markFailed + publishError. Re-throw pour retry. Verifie `isAllComplete()` pour `trip_complete`                                                                                                                 |

### Events Mercure (topic unique `/trips/{tripId}`)

```json
{
    "type": "route_parsed",
    "data": {
        "totalDistance": 250.3,
        "totalElevation": 3200,
        "sourceType": "komoot_tour"
    }
}
{
    "type": "stages_computed",
    "data": {
        "stages": [
            {
                "dayNumber": 1,
                "distance": 85.2
            }
        ]
    }
}
{
    "type": "stage_gpx_ready",
    "data": {
        "stageIndex": 0,
        "gpxContent": "<?xml version=\"1.0\"...>"
    }
}
{
    "type": "pois_scanned",
    "data": {
        "stageIndex": 0,
        "pois": []
    }
}
{
    "type": "validation_error",
    "data": {
        "code": "MIN_STAGES",
        "message": "Minimum 2 etapes requises."
    }
}
{
    "type": "computation_error",
    "data": {
        "computation": "weather",
        "message": "Timeout",
        "retryable": true
    }
}
{
    "type": "trip_complete",
    "data": {
        "computationStatus": {
            "route": "done",
            "stages": "done"
        }
    }
}
```

### Tests

- `tests/Mercure/TripUpdatePublisherTest.php` — Mock HubInterface

### Verification

```bash
make qa && make phpunit -- --filter=Mercure
```

---

## Etape 6 — Parseurs de routes (GPX, KML) + fetchers multi-sources

**Objectif :** Support Komoot Tour, Komoot Collection, Google My Maps avec strategy pattern.
Parseurs GPX (ADR-004) et KML.

### Fichiers a creer

| Fichier                                 | Role                                                                                                                                                   |
|-----------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|
| `src/Spatial/GpxStreamParser.php`       | XMLReader `LIBXML_NONET\|LIBXML_NOENT`. Retourne `list<Coordinate>`                                                                                    |
| `src/Spatial/KmlParser.php`             | XMLReader pour KML Google My Maps. Extrait `<coordinates>` (lon,lat,ele). Memes protections XXE                                                        |
| `src/Spatial/GpxWriter.php`             | Genere un fichier GPX valide a partir d'une `list<Coordinate>`. Format GPX 1.1 avec `<trk><trkseg><trkpt>`                                             |
| `src/Spatial/ElevationCalculator.php`   | Seuil 3m (ADR-004). `calculateTotalAscent()`, `calculateTotalDescent()`                                                                                |
| `src/Spatial/RouteSimplifier.php`       | Douglas-Peucker custom preservant l'elevation. Tolerance 20m, `phpgeo` Vincenty                                                                        |
| `src/Spatial/DistanceCalculator.php`    | Somme des distances Vincenty. Retourne km                                                                                                              |
| `src/Route/RouteFetcherInterface.php`   | Interface : `supports(string $url): bool` + `fetch(string $url): RouteFetchResult`                                                                     |
| `src/Route/RouteFetchResult.php`        | DTO : `SourceType $sourceType`, `list<list<Coordinate>> $tracks`, `?string $title`                                                                     |
| `src/Route/KomootTourFetcher.php`       | Client HTTP scope `komoot.client`. Fetch GPX tour unique — 1 track                                                                                     |
| `src/Route/KomootCollectionFetcher.php` | Fetch metadata collection — liste des tour IDs — fetch chaque GPX — N tracks (1 par tour = 1 etape)                                                    |
| `src/Route/GoogleMyMapsFetcher.php`     | Client `google_mymaps.client`. Resolve short links (`maps.app.goo.gl` — redirect). Export KML via `/maps/d/{id}/export?format=kml`. Parse KML — tracks |
| `src/Route/RouteFetcherRegistry.php`    | Itere les fetchers via `#[TaggedIterator]`, trouve celui qui `supports($url)`                                                                          |
| `tests/fixtures/valid-route.gpx`        | Fixture GPX ~20 trackpoints                                                                                                                            |
| `tests/fixtures/valid-route.kml`        | Fixture KML Google My Maps                                                                                                                             |
| `tests/fixtures/xxe-attack.gpx`         | Payload Billion Laughs                                                                                                                                 |

### Details RouteFetchResult

```php
final readonly class RouteFetchResult
{
    /** @param list<list<Coordinate>> $tracks */
    public function __construct(
        public SourceType $sourceType,
        public array $tracks,     // 1 track pour Tour/MyMaps, N tracks pour Collection
        public ?string $title = null,
    ) {}
}
```

Pour une **Komoot Collection** : chaque tour de la collection produit un track separe. Le
`GenerateStagesHandler` detecte `sourceType === komoot_collection` et bypass le PacingEngine :
chaque track devient directement une etape.

### Details GpxWriter

```php
final class GpxWriter
{
    /** @param list<Coordinate> $points */
    public function generate(array $points, string $trackName = ''): string
    // Retourne un string XML GPX 1.1 valide
}
```

Format de sortie :

```xml
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="BikeTripPlanner">
    <trk>
        <name>{trackName}</name>
        <trkseg>
            <trkpt lat="45.123" lon="5.456">
                <ele>350</ele>
            </trkpt>
        </trkseg>
    </trk>
</gpx>
```

### Librairies

- `mjaschen/phpgeo` — Vincenty, Coordinate
- `ext-xmlreader`

### Tests

| Test                                          | Contenu                                                   |
|-----------------------------------------------|-----------------------------------------------------------|
| `tests/Spatial/GpxStreamParserTest.php`       | Parse valide, XXE rejete, track vide, elevation manquante |
| `tests/Spatial/KmlParserTest.php`             | Parse KML valide, extraction coordonnees lon/lat/ele      |
| `tests/Spatial/GpxWriterTest.php`             | Generer GPX, valider XML bien forme, verifier trackpoints |
| `tests/Spatial/ElevationCalculatorTest.php`   | Plat, montee, bruit < 3m                                  |
| `tests/Spatial/RouteSimplifierTest.php`       | Droite — 2 pts, 25k — <2k, elevation preservee            |
| `tests/Route/KomootTourFetcherTest.php`       | MockHttpClient : succes, 404, 403                         |
| `tests/Route/KomootCollectionFetcherTest.php` | Mock : collection 3 tours — 3 tracks                      |
| `tests/Route/GoogleMyMapsFetcherTest.php`     | Mock : short link redirect + KML export                   |

### Verification

```bash
make qa && make phpunit -- --filter=Spatial && make phpunit -- --filter=Route
```

---

## Etape 7 — Pacing Engine + handlers Route et Stages

**Objectif :** Moteur de progression avec fatigue/penalite configurables (ADR-006). Handlers
pour fetch + generation d'etapes.

### Fichiers a creer

| Fichier                                            | Role                                                                                                                                                                                                  |
|----------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `src/Pacing/PacingEngine.php`                      | Formule : `Dn = base * fatigueFactor^(n-1) - (D+/elevationPenalty)`. Min 30km (BR-03). Parametres injectes depuis TripRequest                                                                         |
| `src/MessageHandler/FetchAndParseRouteHandler.php` | Fetch via `RouteFetcherRegistry` — parse — elevation — decimate — store — publish `route_parsed` — dispatch `GenerateStages`                                                                          |
| `src/MessageHandler/GenerateStagesHandler.php`     | **Deux modes :** (A) Komoot Tour / Google My Maps — PacingEngine pour decouper. (B) Komoot Collection — 1 tour = 1 etape, bypass PacingEngine. — store — publish `stages_computed` — dispatch cascade |
| `src/MessageHandler/GenerateStageGpxHandler.php`   | Pour chaque etape, extrait le segment de points depuis les raw points — `GpxWriter::generate()` — publish `stage_gpx_ready` avec gpxContent                                                           |

### Details PacingEngine

```php
final class PacingEngine
{
    private const float MINIMUM_STAGE_DISTANCE_KM = 30.0;

    /** @param list<Coordinate> $points Points decimes
     *  @return list<Stage> */
    public function generateStages(
        array $points,
        int $numberOfDays,         // calcule depuis endDate - startDate + 1
        float $totalDistanceKm,
        float $fatigueFactor = 0.9,
        float $elevationPenalty = 50.0,
    ): array
}
```

La formule utilise `$fatigueFactor` et `$elevationPenalty` du TripRequest au lieu de constantes
fixes.

### Details GenerateStagesHandler (mode Collection)

```php
if ($routeResult->sourceType === SourceType::KomootCollection) {
    // Chaque track = 1 etape pre-definie
    foreach ($routeResult->tracks as $i => $trackPoints) {
        $stages[] = new Stage(
            dayNumber: $i + 1,
            distance: $this->distanceCalculator->calculateTotalDistance($trackPoints),
            elevation: $this->elevationCalculator->calculateTotalAscent($trackPoints),
            startPoint: $trackPoints[0],
            endPoint: end($trackPoints),
            geometry: $this->routeSimplifier->simplify($trackPoints),
        );
    }
} else {
    // Single route -> PacingEngine
    $stages = $this->pacingEngine->generateStages($decimatedPoints, $numberOfDays, ...);
}
```

### Tests

| Test                                                     | Contenu                                                                                                                   |
|----------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------|
| `tests/Pacing/PacingEngineTest.php`                      | Plat 300km/3j, montagne, min 30km, reliquat, fatigueFactor custom (0.85), elevationPenalty custom (30). `#[DataProvider]` |
| `tests/MessageHandler/FetchAndParseRouteHandlerTest.php` | Mock RouteFetcherRegistry                                                                                                 |
| `tests/MessageHandler/GenerateStagesHandlerTest.php`     | Mode Tour (pacing) + mode Collection (bypass)                                                                             |
| `tests/MessageHandler/GenerateStageGpxHandlerTest.php`   | Verifier GPX XML valide par etape                                                                                         |

### Verification

```bash
make qa && make phpunit -- --filter=Pacing && make phpunit -- --filter=MessageHandler
```

---

## Etape 8 — Handlers OSM (POIs, Accommodations) + Pricing

**Objectif :** Scanner Overpass avec cache 24h (ADR-005) + pricing heuristique (ADR-013).

### Fichiers a creer

| Fichier                                            | Role                                                                                                                    |
|----------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------|
| `src/Osm/OverpassQueryBuilder.php`                 | Overpass QL avec `around:` et polyligne decimee. `buildPoiQuery()`, `buildAccommodationQuery()`, `buildBikeShopQuery()` |
| `src/Osm/OsmScanner.php`                           | `HttpClientInterface $overpassClient` + `CacheInterface $osmCache` (24h). Degradation gracieuse                         |
| `src/Pricing/PricingHeuristicEngine.php`           | camp_site 8-25 EUR, hostel 20-35 EUR, hotel 50-120 EUR. Tag `charge` = prix exact                                       |
| `src/MessageHandler/ScanPoisHandler.php`           | Par etape : Overpass POIs — store — publish — dispatch `CheckResupply`                                                  |
| `src/MessageHandler/ScanAccommodationsHandler.php` | Par endpoint : Overpass — PricingEngine — store — publish                                                               |
| `src/MessageHandler/CheckBikeShopsHandler.php`     | Si jours <= 5 : skip (BR-06). Sinon : `shop=bicycle` — publish                                                          |

### Tests

| Test                                           | Contenu                                            |
|------------------------------------------------|----------------------------------------------------|
| `tests/Osm/OverpassQueryBuilderTest.php`       | QL valide, < 8KB                                   |
| `tests/Osm/OsmScannerTest.php`                 | Mock HTTP, cache hit, erreur gracieuse             |
| `tests/Pricing/PricingHeuristicEngineTest.php` | Brackets par type, charge exact. `#[DataProvider]` |

### Verification

```bash
make qa && make phpunit -- --filter=Osm && make phpunit -- --filter=Pricing
```

---

## Etape 9 — Moteur d'alertes + handlers

**Objectif :** Chain of Responsibility taggee (ADR-012). Alertes generiques (sans bikeType).

### Fichiers a creer

| Fichier                                         | Role                                                                       | Priorite |
|-------------------------------------------------|----------------------------------------------------------------------------|----------|
| `src/Analyzer/StageAnalyzerInterface.php`       | `#[AutoconfigureTag('app.stage_analyzer')]`                                | —        |
| `src/Analyzer/AnalyzerRegistry.php`             | `#[TaggedIterator]`, execute par priorite                                  | —        |
| `src/Analyzer/Rules/ElevationAlertAnalyzer.php` | D+ > 1200m — warning (US#4)                                                | 10       |
| `src/Analyzer/Rules/SurfaceAlertAnalyzer.php`   | Surface non-pavee > 500m — warning informatif (US#4)                       | 20       |
| `src/Analyzer/Rules/TrafficDangerAnalyzer.php`  | highway=primary/secondary sans cycleway — critical (US#4)                  | 20       |
| `src/Analyzer/Rules/LunchNudgeAnalyzer.php`     | Pas de restaurant/marche — nudge (US#5)                                    | 100      |
| `src/MessageHandler/AnalyzeTerrainHandler.php`  | AnalyzerRegistry sur stages — publish `terrain_alerts`                     |          |
| `src/MessageHandler/FetchWeatherHandler.php`    | OpenWeather, cache 3h — publish `weather_fetched` — dispatch `AnalyzeWind` |          |
| `src/MessageHandler/AnalyzeWindHandler.php`     | Vent > 25km/h oppose > 60% — warning (BR-07)                               |          |
| `src/MessageHandler/CheckCalendarHandler.php`   | Yasumi France — nudge jours feries (BR-08)                                 |          |
| `src/MessageHandler/CheckResupplyHandler.php`   | Gap sans resupply — nudge (US#5)                                           |          |

Note : les alertes de surface sont **informatives** (pas conditionnees par un bikeType). Le
message indique le type de surface detecte pour que l'utilisateur juge lui-meme.

### Tests

| Test                                                  | Contenu                                   |
|-------------------------------------------------------|-------------------------------------------|
| `tests/Analyzer/Rules/ElevationAlertAnalyzerTest.php` | D+ 1100 — rien, D+ 1300 — warning         |
| `tests/Analyzer/Rules/SurfaceAlertAnalyzerTest.php`   | Surface unpaved — warning informatif      |
| `tests/Analyzer/AnalyzerRegistryTest.php`             | Integration : decouverte + ordre priorite |
| `tests/MessageHandler/CheckCalendarHandlerTest.php`   | 1er mai — alerte                          |

### Verification

```bash
make qa && make phpunit -- --filter=Analyzer
```

---

## Etape 10 — State Processors API Platform (POST + PATCH)

**Objectif :** Connecter les operations API Platform au pipeline async.

### Fichiers a creer

| Fichier                             | Role                                                                   |
|-------------------------------------|------------------------------------------------------------------------|
| `src/State/TripCreateProcessor.php` | Genere UUID v7 — initTrip — dispatch `FetchAndParseRoute` — 202        |
| `src/State/TripPatchProcessor.php`  | Compare old/new — `DependencyResolver` — dispatch les calculs affectes |
| `src/State/TripStateProvider.php`   | Lit TripRequest depuis Redis pour PATCH. Expire — 404                  |

### Details TripPatchProcessor

Scenarios PATCH :

- `endDate` change — dispatch `GenerateStages` (cascade sous-arbre complet)
- `startDate` change (sans endDate) — dispatch `FetchWeather` + `CheckCalendar`
- `fatigueFactor` ou `elevationPenalty` change — dispatch `GenerateStages` (cascade)
- Parametres identiques — rien (idempotent)

### Tests

| Test                                    | Contenu                                                                                             |
|-----------------------------------------|-----------------------------------------------------------------------------------------------------|
| `tests/Functional/TripCreationTest.php` | POST `/trips` — 202, `FetchAndParseRoute` dispatche                                                 |
| `tests/Functional/TripPatchTest.php`    | PATCH endDate — `GenerateStages`. PATCH startDate seul — `Weather+Calendar`. PATCH identique — rien |

### Verification

```bash
make qa && make phpunit -- --filter=Functional
```

---

## Etape 11 — Gestion des etapes (CRUD + continuite)

**Objectif :** L'utilisateur peut ajouter, modifier, deplacer (drag and drop) et supprimer des
etapes. Chaque modification recalcule les donnees induites en asynchrone et verifie la
continuite de l'itineraire.

### Routes API pour la gestion des etapes

```text
POST   /trips/{tripId}/stages                  -> Ajouter une etape manuelle
PATCH  /trips/{tripId}/stages/{index}           -> Modifier les donnees d'une etape
PATCH  /trips/{tripId}/stages/{index}/move      -> Deplacer une etape (drag and drop)
DELETE /trips/{tripId}/stages/{index}            -> Supprimer une etape
```

### POST /trips/{tripId}/stages — Ajouter une etape manuelle

**Usage :** L'utilisateur ajoute manuellement une etape a une position donnee dans
l'itineraire. Il renseigne les coordonnees de depart et d'arrivee, et optionnellement un nom
d'etape. L'etape est inseree a la position indiquee ; les `dayNumber` des etapes suivantes sont
re-indexes.

```json
{
    "position": 2,
    "startPoint": {
        "lat": 45.123,
        "lon": 5.456,
        "ele": 350
    },
    "endPoint": {
        "lat": 45.789,
        "lon": 5.012,
        "ele": 420
    },
    "label": "Col du Galibier"
}
```

**Logique backend :**

1. Creer un objet `Stage` avec les coordonnees fournies
2. L'inserer a la position `position` dans la liste des etapes (ou a la fin si `position` est
   `null`)
3. Calculer la distance a vol d'oiseau entre `startPoint` et `endPoint` (pas de trace GPX —
   l'etape est manuelle)
4. Re-indexer les `dayNumber` de toutes les etapes
5. Sauvegarder dans Redis et dispatcher les recalculs async

**Recalculs declenches :** POIs (autour de start/end), Accommodations (autour de endPoint),
Terrain (analyse sur les points start/end). Weather/Calendar si dates presentes. Verification
continuite de tout l'itineraire.

**Validation sync (422) :**

- `startPoint` et `endPoint` requis (`#[Assert\NotNull]`)
- `position` hors bornes (< 0 ou > nombre d'etapes) — 422

### PATCH /trips/{tripId}/stages/{index} — Modifier une etape

**Usage :** L'utilisateur modifie les donnees d'une etape existante : lieu de depart, lieu
d'arrivee, ou nom.

```json
{
    "endPoint": {
        "lat": 45.800,
        "lon": 5.100,
        "ele": 380
    },
    "label": "Col du Galibier (variante)"
}
```

**Logique backend :**

1. Charger l'etape existante depuis Redis
2. Mettre a jour les champs fournis (merge partiel : seuls les champs presents sont modifies)
3. Si `startPoint` ou `endPoint` change : recalculer la distance
4. Sauvegarder et dispatcher les recalculs async

**Recalculs declenches :** GPX (si points changes), POIs, Accommodations, Terrain pour l'etape
modifiee. Verification continuite de tout l'itineraire.

### PATCH /trips/{tripId}/stages/{index}/move — Deplacer une etape

**Usage :** L'utilisateur drag une etape et la drop au-dessus ou en-dessous d'une autre etape
dans la liste. Le frontend envoie l'index de destination.

```json
{
    "toIndex": 1
}
```

**Logique backend :**

1. Retirer l'etape de sa position actuelle (`index`)
2. L'inserer a `toIndex`
3. Re-indexer tous les `dayNumber`
4. Recalculer les dates de chaque etape si `startDate` est definie
5. Sauvegarder et dispatcher les recalculs async

**Recalculs declenches :** Weather et Calendar pour toutes les etapes (car les dates changent).
Verification continuite de tout l'itineraire.

**Validation sync (422) :**

- `toIndex` requis (`#[Assert\NotNull]`)
- `toIndex` hors bornes — 422
- `toIndex === index` (aucun mouvement) — 422

### DELETE /trips/{tripId}/stages/{index} — Supprimer une etape

**Logique backend :**

1. Pour un itineraire continu (Tour/MyMaps) : les points de l'etape supprimee sont fusionnes
   avec l'etape suivante (ou precedente si c'est la derniere). L'etape fusionnee herite des
   points de l'itineraire des 2 etapes
2. Pour une Collection (tours independants) : l'etape est simplement retiree, sans fusion
   (chaque tour est independant)
3. Re-indexer les `dayNumber`
4. Sauvegarder et dispatcher les recalculs async

**Recalculs declenches :** GPX, POIs, Accommodations, Terrain pour l'etape fusionnee (ou toutes
les etapes si collection). Verification continuite.

**Validation sync (422) :**

- Si suppression amenerait < 2 etapes — 422 avec message "Minimum 2 etapes requises"

### Verification de continuite de l'itineraire

**Nouveau Analyzer** : `ContinuityAnalyzer` (priorite 5, la plus haute)

Apres toute manipulation d'etapes, le systeme verifie pour chaque paire d'etapes consecutives
que :

- `stage[n].endPoint` est a moins de **500m** de `stage[n+1].startPoint` (distance Vincenty)
- Si la distance depasse 500m — alerte `critical` : *"Discontinuite de l'itineraire entre
  l'etape {n} et l'etape {n+1} ({distance}km)."*
- Si la distance est entre 100m et 500m — alerte `warning` : *"Ecart de {distance}m entre
  l'etape {n} et l'etape {n+1}."*

```php
final class ContinuityAnalyzer implements StageAnalyzerInterface
{
    public function analyze(Stage $stage, array $context = []): array
    {
        $nextStage = $context['nextStage'] ?? null;
        if ($nextStage === null) {return [];}

        $gap = $this->vincenty->getDistance($stage->endPoint, $nextStage->startPoint);

        if ($gap > 500) {
            return [new Alert(AlertType::Critical, sprintf(
                'Discontinuite: %s km entre etape %d et %d.',
                number_format($gap / 1000, 1), $stage->dayNumber, $nextStage->dayNumber
            ), $stage->endPoint->lat, $stage->endPoint->lon)];
        }
        if ($gap > 100) {
            return [new Alert(AlertType::Warning, sprintf(
                'Ecart de %dm entre etape %d et %d.',
                (int) $gap, $stage->dayNumber, $nextStage->dayNumber
            ), $stage->endPoint->lat, $stage->endPoint->lon)];
        }
        return [];
    }

    public static function getPriority(): int { return 5; }
}
```

### Fichiers a creer

| Fichier                                           | Role                                                                                                                                  |
|---------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------|
| `src/ApiResource/StageOperation.php`              | DTO `#[ApiResource]` avec les 4 operations (create, update, move, delete)                                                             |
| `src/State/StageCreateProcessor.php`              | Ajoute une etape manuelle a la position indiquee                                                                                      |
| `src/State/StageUpdateProcessor.php`              | Modifie les donnees d'une etape (depart, arrivee, label)                                                                              |
| `src/State/StageMoveProcessor.php`                | Deplace une etape (drag and drop `index` — `toIndex`)                                                                                 |
| `src/State/StageDeleteProcessor.php`              | Supprime une etape (fusion ou retrait)                                                                                                |
| `src/Analyzer/Rules/ContinuityAnalyzer.php`       | Verifie la continuite endpoint — startpoint entre etapes consecutives (priorite 5)                                                    |
| `src/Message/RecalculateStages.php`               | `readonly class { string $tripId, list<int> $affectedIndices, bool $checkContinuity }`                                                |
| `src/MessageHandler/RecalculateStagesHandler.php` | Pour chaque index affecte : GPX — POIs — Accommodations — Terrain — (Weather/Calendar si dates). Puis continuite si `checkContinuity` |

### Matrice de recalculs par operation

| Operation  | GPX                   | POIs            | Accomm.         | Terrain         | Weather  | Calendar | Continuite |
|------------|-----------------------|-----------------|-----------------|-----------------|----------|----------|------------|
| **Create** | etape ajoutee (2 pts) | etape ajoutee   | etape ajoutee   | etape ajoutee   | si dates | si dates | oui        |
| **Update** | etape modifiee        | etape modifiee  | etape modifiee  | etape modifiee  | si dates | si dates | oui        |
| **Move**   | non                   | non             | non             | non             | toutes   | toutes   | oui        |
| **Delete** | etape fusionnee       | etape fusionnee | etape fusionnee | etape fusionnee | si dates | si dates | oui        |

### Tests

| Test                                              | Contenu                                                                |
|---------------------------------------------------|------------------------------------------------------------------------|
| `tests/Functional/StageCreateTest.php`            | Create — etape inseree, dayNumber re-indexes, continuite verifiee      |
| `tests/Functional/StageUpdateTest.php`            | Update endPoint — recalcul distance, alerte continuite si ecart        |
| `tests/Functional/StageMoveTest.php`              | Move index 3 — 1 — dayNumber re-indexes, alerte continuite si lineaire |
| `tests/Functional/StageDeleteTest.php`            | Delete — fusion, < 2 etapes — 422                                      |
| `tests/Analyzer/Rules/ContinuityAnalyzerTest.php` | Gap 0m — rien, 200m — warning, 2km — critical                          |

### Verification

```bash
make qa && make phpunit -- --filter=Stage && make phpunit -- --filter=Continuity
```

---

## Etape 12 — Export PDF Roadbook (Gotenberg + Twig)

**Objectif :** Endpoint sync PDF (ADR-008, US#6, BR-09).

### Fichiers a creer

| Fichier                                  | Role                                                                                    |
|------------------------------------------|-----------------------------------------------------------------------------------------|
| `src/Controller/PdfExportController.php` | `POST /export-pdf`. Deserialise JSON trip complet — Twig — Gotenberg — stream PDF       |
| `templates/pdf/roadbook.html.twig`       | A4 portrait, Tailwind CDN, page-break par etape, liens bleus soulignes, pas de QR codes |

Note : le frontend envoie le trip complet (depuis Zustand) car l'etat Redis peut avoir expire.

### Tests

- `tests/Controller/PdfExportControllerTest.php` — `#[Group('integration')]` : body commence
  par `%PDF-`

### Verification

```bash
make qa && make phpunit -- --group=integration
```

---

## Etape 13 — Documentation des alertes extensibles

**Objectif :** Documenter le systeme d'alertes pour permettre l'ajout de nouvelles regles sans
modifier le code existant.

### Fichier a creer

| Fichier                                   | Contenu                                                |
|-------------------------------------------|--------------------------------------------------------|
| `docs/adr/ADR-014-alert-extensibility.md` | ADR documentant le pattern d'extensibilite des alertes |

### Contenu de la documentation

Le document doit couvrir :

**1. Architecture du systeme d'alertes**

- Interface `StageAnalyzerInterface` avec `#[AutoconfigureTag]`
- Pattern Tagged Iterator via `AnalyzerRegistry`
- Systeme de priorites (10=critique, 100=nudge)
- Le contexte `$context` array avec les cles disponibles

**2. Guide "Ajouter une nouvelle alerte"**

```php
// 1. Creer la classe dans src/Analyzer/Rules/
namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;use App\ApiResource\Model\Alert;use App\ApiResource\Stage;use App\Enum\AlertType;

final class MyNewAnalyzer implements StageAnalyzerInterface
{
    public function analyze(Stage $stage, array $context = []): array
    {
        if ($someCondition) {
            return [new Alert(AlertType::Warning, 'Message descriptif', $lat, $lon)];
        }
        return [];
    }

    public static function getPriority(): int
    {
        return 50; // 10=critique, 100=nudge
    }
}
// 2. C'est tout. L'autoconfiguration Symfony decouvre automatiquement la classe.
// 3. Ecrire le test unitaire dans tests/Analyzer/Rules/MyNewAnalyzerTest.php
```

**3. Cles de contexte disponibles**

| Cle           | Type               | Disponibilite        |
|---------------|--------------------|----------------------|
| `tripDays`    | int                | Apres GenerateStages |
| `startDate`   | ?DateTimeImmutable | Apres PATCH dates    |
| `endDate`     | ?DateTimeImmutable | Apres PATCH dates    |
| `osmPois`     | array              | Apres ScanPois       |
| `weatherData` | array              | Apres FetchWeather   |

**4. Conventions**

- Un analyzer = un fichier = un test
- Nommage : `{Concept}Analyzer.php` + `{Concept}AnalyzerTest.php`
- Les messages d'alerte doivent etre actionnables (pas "erreur detectee" mais "Section
  non-pavee de 3km entre km 45 et km 48")
- Toujours fournir lat/lon quand possible pour le rendu carte

### Verification

```bash
make qa  # Le markdown doit passer markdownlint
```

---

## Etape 14 — Tests d'integration end-to-end

**Objectif :** Valider le pipeline complet.

### Tests

| Test                                          | Contenu                                                                                                     |
|-----------------------------------------------|-------------------------------------------------------------------------------------------------------------|
| `tests/Functional/FullPipelineTest.php`       | POST — consume all messages (in-memory) — all computations "done"                                           |
| `tests/Functional/CollectionPipelineTest.php` | POST Collection — 1 tour = 1 etape (bypass pacing)                                                          |
| `tests/Functional/PatchIdempotencyTest.php`   | PATCH identique — aucun message                                                                             |
| `tests/Functional/StageGpxTest.php`           | Chaque etape a un GPX XML valide                                                                            |
| `tests/Functional/StageCrudTest.php`          | Create — insertion, Update — recalcul, Move — reorder, Delete — fusion                                      |
| `tests/Functional/ValidationErrorTest.php`    | Route vide — `validation_error` Mercure. 1 seule etape — `validation_error`. endDate < startDate — 422 sync |

### Verification finale

```bash
make qa          # PHPStan Level 9 + PHP-CS-Fixer
make test-php    # Tous les tests PHPUnit
make typegen     # Regenerer types TypeScript depuis OpenAPI
```

---

## Resume des fichiers

| Categorie                        | Nb       | Details                                                                                                                                                                 |
|----------------------------------|----------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Enums (`src/Enum/`)              | 3        | SourceType, AlertType, ComputationName                                                                                                                                  |
| DTOs (`src/ApiResource/`)        | 3        | TripRequest, TripResponse, StageOperation                                                                                                                               |
| Model (`src/ApiResource/Model/`) | 6        | Coordinate, Stage, Alert, PointOfInterest, Accommodation, WeatherForecast                                                                                               |
| Route (`src/Route/`)             | 6        | RouteFetcherInterface, RouteFetchResult, RouteFetcherRegistry, KomootTourFetcher, KomootCollectionFetcher, GoogleMyMapsFetcher                                          |
| Spatial (`src/Spatial/`)         | 6        | GpxStreamParser, KmlParser, GpxWriter, ElevationCalculator, RouteSimplifier, DistanceCalculator                                                                         |
| Pacing (`src/Pacing/`)           | 1        | PacingEngine                                                                                                                                                            |
| OSM (`src/Osm/`)                 | 2        | OverpassQueryBuilder, OsmScanner                                                                                                                                        |
| Pricing (`src/Pricing/`)         | 1        | PricingHeuristicEngine                                                                                                                                                  |
| Analyzer (`src/Analyzer/`)       | 7        | Interface, Registry, 4 regles + ContinuityAnalyzer                                                                                                                      |
| State (`src/State/`)             | 10       | TripCreate/Patch Processors, TripStateProvider, TripStateManager, ComputationTracker, IdempotencyChecker, DependencyResolver, StageCreate/Update/Move/Delete Processors |
| Mercure (`src/Mercure/`)         | 2        | MercureEventType, TripUpdatePublisher                                                                                                                                   |
| Messages (`src/Message/`)        | 12       | 11 calculs + RecalculateStages                                                                                                                                          |
| Handlers (`src/MessageHandler/`) | 13       | Abstract + 11 handlers + RecalculateStagesHandler                                                                                                                       |
| Controller (`src/Controller/`)   | 1        | PdfExportController                                                                                                                                                     |
| Template (`templates/pdf/`)      | 1        | roadbook.html.twig                                                                                                                                                      |
| Documentation (`docs/adr/`)      | 1        | ADR-014-alert-extensibility.md                                                                                                                                          |
| Config                           | 4        | mercure.php, messenger.php, cache.php, framework.php                                                                                                                    |
| Infra                            | 5        | compose.yaml, .env, .env.test, Makefile, bundles.php                                                                                                                    |
| Tests                            | ~30      | Unit, Integration, Functional                                                                                                                                           |
| Fixtures                         | 3        | valid-route.gpx, valid-route.kml, xxe-attack.gpx                                                                                                                        |
| **Total**                        | **~105** |                                                                                                                                                                         |
