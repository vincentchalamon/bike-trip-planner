# Bike Trip Planner

*[English version](README.md)*

Un planificateur de voyages bikepacking local-first. Collez une URL Komoot, Strava ou RideWithGPS (ou importez un fichier GPX) et obtenez un roadbook jour par jour avec rythme, alertes d'elevation et suggestions d'hebergement -- le tout sans compte ni stockage cloud.

---

## Fonctionnalites

### Ingestion de routes

- **Magic Link** -- Collez une URL de tour/collection Komoot, de route Strava ou RideWithGPS ; le backend recupere la route, analyse les donnees d'elevation et calcule un plan de voyage complet de maniere asynchrone.
- **Import GPX** -- Glissez-deposez ou selectionnez un fichier GPX directement (jusqu'a 15 Mo). Le fichier est analyse en streaming avec une consommation memoire constante.
- **Lien partageable** -- Un parametre `?link=` dans l'URL cree automatiquement un voyage a partir de n'importe quelle URL supportee, permettant de partager un lien direct vers un plan de voyage.

### Planification du voyage

- **Moteur de rythme** -- Repartit la distance sur les jours en tenant compte de la fatigue cumulative et du gain d'elevation, avec une distance maximale par jour, un facteur de fatigue, une penalite d'elevation et une vitesse moyenne configurables.
- **Mode e-bike** -- Mode dedie qui ajuste les calculs d'autonomie pour les velos electriques (autonomie effective = 80 km moins elevation/25).
- **Selecteur de dates** -- Definissez les dates de depart et de retour pour activer les alertes calendaires (jours feries, dimanches) et les previsions meteo.
- **Heure de depart** -- Configurez l'heure de depart quotidienne pour activer les alertes d'arrivee au coucher du soleil.
- **Insertion de jours de repos** -- Ajoutez des jours de repos entre les etapes ; une alerte vous rappelle tous les N jours consecutifs de velo.
- **Edition des etapes** -- Divisez, fusionnez, ajoutez ou supprimez des etapes. Renommez les lieux de depart et d'arrivee via l'edition en ligne avec geocodage. Ajustez les distances des etapes avec un editeur visuel.
- **Annuler/retablir** -- Historique complet d'annulation/retablissement pour toutes les modifications de voyage (Ctrl+Z / Ctrl+Y).

### Carte interactive

- **Visualisation de la route** -- Carte interactive Leaflet affichant la route complete avec des etapes codees par couleur.
- **Profil d'elevation** -- Graphique d'elevation synchronise avec un referencement croise au survol entre la carte et le profil.
- **Marqueurs d'etapes** -- Cliquez sur une etape sur la carte ou la timeline pour la mettre en focus ; cliquez a nouveau pour revenir a la vue globale.
- **Modes d'affichage** -- Trois dispositions : timeline seule, carte seule, ou divise (timeline + carte cote a cote). Par defaut en timeline sur mobile, divise sur desktop.

### Meteo et environnement

- **Previsions meteo** -- Integration Open-Meteo fournissant temperature, precipitations, vitesse du vent et conditions meteo par etape.
- **Indice de confort** -- Score combine (0-100) de temperature, vent, humidite et pluie pour chaque etape.
- **Vent relatif** -- Calcul du vent de face/arriere/lateral base sur le cap de l'etape et la direction du vent.

### Moteur d'alertes

Le backend execute un pipeline d'analyseurs sur chaque etape. Trois niveaux de severite :

| Niveau | Couleur | Description |
|--------|---------|-------------|
| `critical` | Rouge | Probleme bloquant necessitant une attention immediate |
| `warning` | Orange | Probleme significatif a surveiller |
| `nudge` | Bleu | Suggestion informative |

Les regles sont executees par ordre de priorite (inferieur = plus prioritaire) :

| Regle | Priorite | Severite | Declencheur |
|-------|----------|----------|-------------|
| **Continuite** | 5 | critical | Ecart > 500 m entre deux etapes consecutives |
| **Continuite** | 5 | warning | Ecart 100-500 m entre deux etapes |
| **Elevation** | 10 | warning | Gain d'elevation > 1 200 m sur une etape |
| **Pente raide** | 20 | warning | Pente >= 8 % soutenue sur >= 500 m |
| **Surface** | 20 | warning | Section non goudronnee >= 500 m (gravier, terre, boue, herbe, sable...) |
| **Surface** | 20 | warning | Donnees de surface OSM manquantes sur >= 30 % des chemins |
| **Trafic** | 20 | critical | Route primaire/nationale sans infrastructure cyclable >= 500 m |
| **Trafic** | 20 | warning | Route secondaire, pas de piste cyclable, limite > 50 km/h |
| **Trafic** | 20 | nudge | Route secondaire, limite <= 50 km/h |
| **Autonomie e-bike** | 20 | warning | Distance du jour > autonomie effective (80 km - elevation / 25) |
| **Coucher de soleil** | 20 | warning | Heure d'arrivee estimee depasse la fin du crepuscule civil au point d'arrivee |
| **Jour de repos** | 100 | nudge | Tous les N jours consecutifs de velo sans jour de repos (defaut : tous les 3 jours) |
| **Calendrier** | -- | nudge | L'etape tombe un jour ferie francais |
| **Calendrier** | -- | nudge | L'etape tombe un dimanche (commerces potentiellement fermes) |
| **Vent** | -- | warning | Vent de face >= 25 km/h sur >= 60 % des etapes avec donnees meteo |
| **Confort** | -- | warning | Indice de confort faible (< 40/100) sur au moins une etape |
| **Ateliers velo** | -- | nudge | Pas d'atelier de reparation dans un rayon de 2 km du milieu de l'etape (voyages > 5 etapes) |
| **Ateliers velo** | -- | nudge | L'atelier a proximite vend des velos mais n'offre pas de service de reparation |
| **Ravitaillement** | -- | nudge | Etape >= 40 km sans POI de ravitaillement/alimentation le long de la route |
| **Ravitaillement** | -- | warning | Tous les POI de ravitaillement de l'etape sont fermes a l'heure de passage estimee |
| **Hebergement** | -- | warning | Tous les hebergements detectes sur l'etape sont probablement fermes en raison de la saisonnalite |
| **Points d'eau** | -- | nudge | Troncon > 30 km sans source d'eau potable detectee |
| **POI culturels** | -- | nudge | Musee, monument, chateau, eglise, point de vue ou attraction a moins de 500 m de la route -- inclut une action "ajouter a l'itineraire" declenchant un recalcul de route |

**Regles terrain** (Continuite, Elevation, Pente raide, Surface, Trafic, Autonomie e-bike, Coucher de soleil, Jour de repos) implementent `StageAnalyzerInterface` et sont auto-decouvertes via `#[AutoconfigureTag('app.stage_analyzer')]`. Les regles avec une priorite `--` (Calendrier, Vent + Confort, Ateliers velo, Ravitaillement, Hebergement, Points d'eau, POI culturels) sont des handlers de messages Symfony asynchrones separes ; Confort est co-localise avec Vent dans `AnalyzeWindHandler`.

### Points d'interet

- **Scanner d'hebergements** -- Interroge OpenStreetMap Overpass pour trouver des bivouacs, refuges et gites a proximite de chaque fin d'etape, avec un prix heuristique. Filtrage des hebergements par type.
- **Timeline de ravitaillement** -- Timeline visuelle montrant les points d'eau et de ravitaillement le long de chaque etape, avec clustering pour la lisibilite.
- **Ateliers velo** -- Detection des ateliers de reparation a proximite du milieu de chaque etape.
- **POI culturels** -- Musees, monuments, chateaux, eglises, points de vue et attractions a proximite de la route avec une action "ajouter a l'itineraire".

### Exports

- **Export GPX** -- Telechargez chaque etape en fichier GPX individuel avec des waypoints enrichis (POI, points d'eau, ravitaillement, hebergements).
- **Export FIT** -- Telechargez chaque etape en fichier FIT compatible Garmin avec des points de parcours.
- **GPX voyage complet** -- Telechargez l'ensemble du voyage en un seul fichier GPX.
- **Export texte** -- Resume en texte brut du voyage complet (etapes, distances, elevations, hebergements), pret a copier-coller.

### Experience utilisateur

- **Visite guidee** -- Tour guide en 4 etapes lors de la premiere visite via driver.js, presentant le workflow principal.
- **Raccourcis clavier** -- Naviguer entre les etapes (J/K), annuler/retablir (Ctrl+Z/Y), afficher l'aide (?), fermer les panneaux (Esc).
- **Mode sombre** -- Bascule de theme avec detection de la preference systeme.
- **Internationalisation** -- Interface complete en francais et en anglais via next-intl.
- **Design responsive** -- Mobile-first avec mode d'affichage adaptatif (timeline/carte/divise).
- **Navigation par balayage** -- Balayage entre les etapes sur les appareils mobiles.

---

## Vue d'ensemble de l'architecture

<!-- markdownlint-disable MD040 -->
```
Navigateur (Next.js 16)        Backend PHP (API Platform 4.2)
  Zustand + Immer (en memoire)   Calcul sans etat
  Validation Zod                 Parsing GPX + moteur de rythme
  openapi-fetch (type)           APIs OSM Overpass + meteo
  Mercure SSE (temps reel) <--   Workers asynchrones (Symfony Messenger)
                                 Cache Redis + publisher Mercure
                                 |
                            Chromium headless via Twig (PDF)
```

Le frontend envoie une requete de voyage via REST ; le backend la traite de maniere asynchrone sur plusieurs workers et pousse les mises a jour de statut via Mercure SSE. Pas de base de donnees -- cache Redis pour l'etat transitoire, cache filesystem pour les reponses d'API externes.

La securite des types est appliquee de bout en bout : les DTO PHP definissent le schema -> API Platform exporte une spec OpenAPI -> `npm run typegen` genere les types TypeScript -> `openapi-fetch` fournit des appels API types. Un changement de schema cote backend provoque intentionnellement une erreur de compilation TypeScript.

---

## Stack technique

| Couche | Technologie |
|--------|-------------|
| Backend | PHP 8.5, Symfony 8, API Platform 4.2, Caddy |
| Frontend | Next.js 16 (App Router), React 19, TypeScript (strict) |
| Etat | Zustand + Immer (en memoire), Mercure SSE (temps reel) |
| Carte | Leaflet, react-leaflet |
| Style | Tailwind CSS, shadcn/ui |
| Tests | PHPUnit 13 (backend), Playwright 1.58 (E2E) |
| Qualite | PHPStan niveau 9, PHP-CS-Fixer, Rector, ESLint, Prettier |
| Asynchrone | Symfony Messenger, transport Redis, 5 workers |
| Runtime | Docker (Caddy, Mercure, Redis, Node) |

---

## Documentation

| Document | Description |
|----------|-------------|
| [Demarrage rapide](docs/getting-started.fr.md) | Prerequis, installation et configuration locale |
| [Contribuer](docs/contributing.fr.md) | Workflow de developpement, standards et outillage |
| [Decisions d'architecture](docs/adr/) | ADR expliquant chaque choix technique majeur |
| [Outillage Claude Code](docs/claude-code-tooling.fr.md) | Serveurs MCP, hooks et skills pour le developpement assiste par IA |

---

## Demarrage rapide

```bash
git clone <repo-url> bike-trip-planner
cd bike-trip-planner
make start
```

L'application est disponible sur `https://localhost` (PWA) et `https://localhost/docs` (API).

Voir [Demarrage rapide](docs/getting-started.fr.md) pour les prerequis et la configuration detaillee.

---

## Sources de routes supportees

| Source | Format d'URL |
|--------|--------------|
| Tour Komoot | `https://www.komoot.com/tour/<id>` |
| Collection Komoot | `https://www.komoot.com/collection/<id>` |
| Route Strava | `https://www.strava.com/routes/<id>` |
| Route RideWithGPS | `https://ridewithgps.com/routes/<id>` |
| Import de fichier GPX | Glisser-deposer ou selecteur de fichiers (jusqu'a 15 Mo) |

---

## Licence

MIT
