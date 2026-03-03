# ADR-018: Garmin Export and Device Sync Strategy

- **Status:** Proposed
- **Date:** 2026-03-04
- **Depends on:** ADR-004 (GPX decimation), Export GPX enrichi (waypoints)

## Context and Problem Statement

Le Bike Trip Planner génère des itinéraires multi-étapes pour le bikepacking. Actuellement, l'export se limite à un fichier GPX par étape (trace `<trk>/<trkpt>` avec lat/lon/ele, ~1 500 points après décimation Douglas-Peucker). Le rider doit télécharger le GPX puis l'importer manuellement dans son GPS Garmin.

Deux besoins identifiés :

1. **Format natif Garmin** — Le GPX fonctionne mais le FIT (binaire propriétaire Garmin) est le format natif des courses Garmin : plus compact, supporte les Course Points (POIs sur le parcours), et évite une conversion côté GPS.
2. **Push automatique vers le GPS** — Éliminer l'étape manuelle d'import en envoyant directement l'itinéraire sur le compte Garmin Connect de l'utilisateur, avec sync automatique vers le périphérique.

### Clarification ADR-004

L'ADR-004 mentionne en conséquence neutre que les données décimées ne conviennent pas pour "re-export high-fidelity GPS traces for Garmin devices". Cette remarque concerne le re-export de traces haute fidélité (enregistrement d'activité). Pour la **navigation de course** (suivre la ligne violette sur un Edge/Fenix), ~1 500 points/étape est largement suffisant — Komoot et Strava envoient des courses avec une densité similaire.

## Considered Options

### Option A : Téléchargement GPX uniquement (statu quo amélioré)

Conserver le format GPX, enrichir avec des waypoints (`<wpt>`) pour les hébergements, points d'eau et commerces. Ajouter un bouton de téléchargement GPX global (toutes les étapes concaténées).

| Critère | Évaluation |
|---------|------------|
| Compatibilité GPS | Universelle (Garmin, Wahoo, Hammerhead, Coros...) |
| Compacité | Faible (XML verbeux, ~5× plus lourd que FIT) |
| Course Points / POIs | Supportés via `<wpt>`, mais conversion nécessaire côté GPS |
| Effort d'implémentation | Minimal (le `GpxWriter` existe, ajout de `<wpt>`) |
| UX | Import manuel obligatoire |

### Option B : Téléchargement FIT (format binaire Garmin)

Nouveau `FitWriter` backend générant le format binaire FIT via `pack()` natif PHP. Le FIT encode directement des Course Points typés (Food, Water, Summit, Generic...) reconnus nativement par les GPS Garmin.

Structure du fichier :

```
Header (14 bytes)
├── FILE_ID     (type=course, manufacturer, product)
├── COURSE      (nom, sport=cycling)
├── EVENT       (timer start)
├── RECORD[]    (lat/lon en semicircles, altitude, distance cumulative)
├── COURSE_POINT[]  (POIs : hébergements, points d'eau, commerces)
├── LAP         (résumé start/end, distance totale)
└── EVENT       (timer stop)
CRC16
```

| Critère | Évaluation |
|---------|------------|
| Compatibilité GPS | Garmin uniquement (Wahoo/Hammerhead acceptent aussi le FIT, mais le GPX reste plus universel) |
| Compacité | ~5× plus compact que GPX |
| Course Points / POIs | Natifs, typés (Food, Water, Summit...), affichés directement sur le GPS |
| Effort d'implémentation | Moyen (~200-300 lignes, `pack()` natif, aucune dépendance externe) |
| UX | Import manuel, mais fichier plus léger et POIs natifs |

Coordonnées en "semicircles" : `round(degrees / 180 × 2^31)`. Descriptions des Course Points limitées à 16 bytes.

### Option C : Push via Garmin Connect Courses API

Le backend pousse le fichier FIT directement sur le compte Garmin Connect de l'utilisateur via l'API Courses. Le périphérique reçoit la course automatiquement au prochain sync (Bluetooth/WiFi/USB). C'est le mécanisme utilisé par Komoot et Strava.

| Critère | Évaluation |
|---------|------------|
| UX | Optimale : 1 clic, sync automatique vers le GPS |
| Effort d'implémentation | Élevé (OAuth 2.0 PKCE, gestion des tokens, refresh automatique) |
| Prérequis | Persistance BDD (stockage tokens), infra de production (callback OAuth HTTPS), approbation Garmin Developer Program |
| Garmin Developer Program | Gratuit, approbation ~2 jours, ouvert aux développeurs individuels |
| Contrainte OAuth | OAuth 1.0 retiré le 31/12/2026 → implémenter directement en OAuth 2.0 PKCE |
| Spécifications API | Disponibles uniquement après approbation au programme |

### Option D : Push via Strava comme intermédiaire (Strava → Garmin Connect → GPS)

Pousser l'itinéraire vers Strava, qui synchronise ensuite automatiquement vers Garmin Connect.

**Non viable.** L'API Strava v3 est read-only pour les routes :

- `GET /routes/{id}` — consulter
- `GET /routes/{id}/export/gpx` — exporter
- `GET /athletes/{id}/routes` — lister

Il n'existe aucun endpoint `POST` pour créer une route programmatiquement. Seul l'upload d'*activités* (sorties enregistrées) est supporté, pas les *routes planifiées*. De plus, même si l'endpoint existait, cela ajouterait un intermédiaire inutile par rapport au push direct vers Garmin Connect (Option C).

## Decision Outcome

**Retenu : Options A + B (Phase 1) puis Option C (Phase 2), séquentiellement. Option D rejetée.**

### Phase 1 : GPX enrichi + FIT (téléchargement)

Les deux formats sont complémentaires :
- **GPX enrichi** (Option A) : compatibilité universelle, utile pour les GPS non-Garmin
- **FIT** (Option B) : format natif Garmin, plus compact, Course Points typés

Boutons de téléchargement :
- Par étape : GPX + FIT dans la `stage-card`
- Itinéraire global : GPX + FIT dans le `trip-summary`

Le `FitWriter` est implémentable sans dépendance externe (`pack()` natif PHP). Le GPX enrichi nécessite l'ajout de `<wpt>` dans le `GpxWriter` existant.

### Phase 2 : Push Garmin Connect (Option C)

Une fois en place : persistance BDD (tokens OAuth), infrastructure de production (callback HTTPS), et approbation au Garmin Developer Program.

### Rejet de l'Option D (Strava)

L'API Strava ne permet pas de créer des routes. L'option est techniquement impossible.

## Consequences

### Positive

- **Valeur immédiate (Phase 1)** — Le FIT en téléchargement apporte le format natif Garmin sans aucune dépendance externe ni infrastructure supplémentaire.
- **Couverture universelle** — GPX pour tous les GPS, FIT pour l'expérience Garmin optimale.
- **Réutilisation (Phase 2)** — Le fichier FIT généré par le `FitWriter` est réutilisé tel quel pour le push Garmin Connect.

### Negative

- **FIT est un format binaire propriétaire** — Pas de SDK FIT officiel en PHP ; l'encodage via `pack()` nécessite une implémentation manuelle du protocole (header, définitions de messages, CRC16). Risque d'erreurs subtiles sur des cas limites.
- **Phase 2 : couplage Garmin** — L'intégration OAuth crée une dépendance sur un service tiers (disponibilité, changements d'API, processus d'approbation).

### Neutral

- La Phase 2 est bloquée par des prérequis structurels (BDD, infra de production, approbation Garmin) qui seront traités indépendamment.

## Sources

- [Garmin FIT SDK](https://developer.garmin.com/fit/protocol/)
- [Strava API v3 Reference](https://developers.strava.com/docs/reference/) — confirme l'absence d'endpoint de création de route
- [Strava Routes to Garmin Device](https://support.strava.com/hc/en-us/articles/115000919304-Syncing-Strava-Routes-to-your-Garmin-Device)
- [Garmin Connect Developer Program](https://www.garmin.com/en-US/forms/GarminConnectDeveloperAccess/)
