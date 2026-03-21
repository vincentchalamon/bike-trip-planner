# Bike Trip Planner

*[English version](README.md)*

Un planificateur de voyages bikepacking local-first. Collez une URL Komoot, Strava ou RideWithGPS (ou importez un fichier GPX) et obtenez un roadbook jour par jour avec rythme, alertes d'élévation et suggestions d'hébergement — le tout sans compte ni stockage cloud.

---

## Fonctionnalités

### Ingestion de routes

- **Magic Link** — Collez une URL de tour/collection Komoot, de route Strava ou RideWithGPS ; le backend récupère la route, analyse les données d'élévation et calcule un plan de voyage complet de manière asynchrone.
- **Import GPX** — Glissez-déposez ou sélectionnez un fichier GPX directement (jusqu'à 15 Mo). Le fichier est analysé en streaming avec une consommation mémoire constante.
- **Lien partageable** — Un paramètre `?link=` dans l'URL crée automatiquement un voyage à partir de n'importe quelle URL supportée, permettant de partager un lien direct vers un plan de voyage.

### Planification du voyage

- **Moteur de rythme** — Répartit la distance sur les jours en tenant compte de la fatigue cumulative et du gain d'élévation, avec une distance maximale par jour, un facteur de fatigue, une pénalité d'élévation et une vitesse moyenne configurables.
- **Mode e-bike** — Mode dédié qui ajuste les calculs d'autonomie pour les vélos électriques (autonomie effective = 80 km moins élévation/25).
- **Sélecteur de dates** — Définissez les dates de départ et de retour pour activer les alertes calendaires (jours fériés, dimanches) et les prévisions météo.
- **Heure de départ** — Configurez l'heure de départ quotidienne pour activer les alertes d'arrivée au coucher du soleil.
- **Insertion de jours de repos** — Ajoutez des jours de repos entre les étapes ; une alerte vous rappelle tous les N jours consécutifs de vélo.
- **Édition des étapes** — Divisez, fusionnez, ajoutez ou supprimez des étapes. Renommez les lieux de départ et d'arrivée via l'édition en ligne avec géocodage. Ajustez les distances des étapes avec un éditeur visuel.
- **Annuler/rétablir** — Historique complet d'annulation/rétablissement pour toutes les modifications de voyage (Ctrl+Z / Ctrl+Y).

### Carte interactive

- **Visualisation de la route** — Carte interactive MapLibre GL affichant la route complète avec des étapes codées par couleur.
- **Profil d'élévation** — Graphique d'élévation synchronisé avec un référencement croisé au survol entre la carte et le profil.
- **Marqueurs d'étapes** — Cliquez sur une étape sur la carte ou la timeline pour la mettre en focus ; cliquez à nouveau pour revenir à la vue globale.
- **Modes d'affichage** — Trois dispositions : timeline seule, carte seule, ou divisé (timeline + carte côte à côte). Par défaut en timeline sur mobile, divisé sur desktop.

### Météo et environnement

- **Prévisions météo** — Intégration Open-Meteo fournissant température, précipitations, vitesse du vent et conditions météo par étape.
- **Indice de confort** — Score combiné (0-100) de température, vent, humidité et pluie pour chaque étape.
- **Vent relatif** — Calcul du vent de face/arrière/latéral basé sur le cap de l'étape et la direction du vent.

### Moteur d'alertes

Le backend exécute un pipeline d'analyseurs sur chaque étape. Trois niveaux de sévérité :

| Niveau | Couleur | Description |
|--------|---------|-------------|
| `critical` | Rouge | Problème bloquant nécessitant une attention immédiate |
| `warning` | Orange | Problème significatif à surveiller |
| `nudge` | Bleu | Suggestion informative |

Les règles sont exécutées par ordre de priorité (inférieur = plus prioritaire) :

| Règle | Priorité | Sévérité | Déclencheur |
|-------|----------|----------|-------------|
| **Continuité** | 5 | critical | Écart > 500 m entre deux étapes consécutives |
| **Continuité** | 5 | warning | Écart 100-500 m entre deux étapes |
| **Élévation** | 10 | warning | Gain d'élévation > 1 200 m sur une étape |
| **Pente raide** | 20 | warning | Pente >= 8 % soutenue sur >= 500 m |
| **Surface** | 20 | warning | Section non goudronnée >= 500 m (gravier, terre, boue, herbe, sable...) |
| **Surface** | 20 | warning | Données de surface OSM manquantes sur >= 30 % des chemins |
| **Trafic** | 20 | critical | Route primaire/nationale sans infrastructure cyclable >= 500 m |
| **Trafic** | 20 | warning | Route secondaire, pas de piste cyclable, limite > 50 km/h |
| **Trafic** | 20 | nudge | Route secondaire, limite <= 50 km/h |
| **Autonomie e-bike** | 20 | warning | Distance du jour > autonomie effective (80 km - élévation / 25) |
| **Coucher de soleil** | 20 | warning | Heure d'arrivée estimée dépasse la fin du crépuscule civil au point d'arrivée |
| **Jour de repos** | 100 | nudge | Tous les N jours consécutifs de vélo sans jour de repos (défaut : tous les 3 jours) |
| **Calendrier** | — | nudge | L'étape tombe un jour férié français |
| **Calendrier** | — | nudge | L'étape tombe un dimanche (commerces potentiellement fermés) |
| **Vent** | — | warning | Vent de face >= 25 km/h sur >= 60 % des étapes avec données météo |
| **Confort** | — | warning | Indice de confort faible (< 40/100) sur au moins une étape |
| **Ateliers vélo** | — | nudge | Pas d'atelier de réparation dans un rayon de 2 km du milieu de l'étape (voyages > 5 étapes) |
| **Ateliers vélo** | — | nudge | L'atelier à proximité vend des vélos mais n'offre pas de service de réparation |
| **Ravitaillement** | — | nudge | Étape >= 40 km sans POI de ravitaillement/alimentation le long de la route |
| **Ravitaillement** | — | warning | Tous les POI de ravitaillement de l'étape sont fermés à l'heure de passage estimée |
| **Hébergement** | — | warning | Tous les hébergements détectés sur l'étape sont probablement fermés en raison de la saisonnalité |
| **Points d'eau** | — | nudge | Tronçon > 30 km sans source d'eau potable détectée |
| **POI culturels** | — | nudge | Musée, monument, château, église, point de vue ou attraction à moins de 500 m de la route — inclut une action "ajouter à l'itinéraire" déclenchant un recalcul de route |

**Règles terrain** (Continuité, Élévation, Pente raide, Surface, Trafic, Autonomie e-bike, Coucher de soleil, Jour de repos) implémentent `StageAnalyzerInterface` et sont auto-découvertes via `#[AutoconfigureTag('app.stage_analyzer')]`. Les règles avec une priorité `—` (Calendrier, Vent + Confort, Ateliers vélo, Ravitaillement, Hébergement, Points d'eau, POI culturels) sont des handlers de messages Symfony asynchrones séparés ; Confort est co-localisé avec Vent dans `AnalyzeWindHandler`.

### Points d'intérêt

- **Scanner d'hébergements** — Interroge OpenStreetMap Overpass pour trouver des bivouacs, refuges et gîtes à proximité de chaque fin d'étape, avec un prix heuristique. Filtrage des hébergements par type.
- **Timeline de ravitaillement** — Timeline visuelle montrant les points d'eau et de ravitaillement le long de chaque étape, avec clustering pour la lisibilité.
- **Ateliers vélo** — Détection des ateliers de réparation à proximité du milieu de chaque étape.
- **POI culturels** — Musées, monuments, châteaux, églises, points de vue et attractions à proximité de la route avec une action "ajouter à l'itinéraire".

### Exports

- **Export GPX** — Téléchargez chaque étape en fichier GPX individuel avec des waypoints enrichis (POI, points d'eau, ravitaillement, hébergements).
- **Export FIT** — Téléchargez chaque étape en fichier FIT compatible Garmin avec des points de parcours.
- **GPX voyage complet** — Téléchargez l'ensemble du voyage en un seul fichier GPX.
- **Export texte** — Résumé en texte brut du voyage complet (étapes, distances, élévations, hébergements), prêt à copier-coller.

### Expérience utilisateur

- **Visite guidée** — Tour guidé en 4 étapes lors de la première visite via driver.js, présentant le workflow principal.
- **Raccourcis clavier** — Naviguer entre les étapes (J/K), annuler/rétablir (Ctrl+Z/Y), afficher l'aide (?), fermer les panneaux (Esc).
- **Mode sombre** — Bascule de thème avec détection de la préférence système.
- **Internationalisation** — Interface complète en français et en anglais via next-intl.
- **Design responsive** — Mobile-first avec mode d'affichage adaptatif (timeline/carte/divisé).
- **Navigation par balayage** — Balayage entre les étapes sur les appareils mobiles.

---

## Vue d'ensemble de l'architecture

<!-- markdownlint-disable MD040 -->
```
Navigateur (Next.js 16)        Backend PHP (API Platform 4.2)
  Zustand + Immer (en mémoire)   Calcul sans état
  Validation Zod                 Parsing GPX + moteur de rythme
  openapi-fetch (typé)           APIs OSM Overpass + météo
  Mercure SSE (temps réel) <--   Workers asynchrones (Symfony Messenger)
                                 Cache Redis + publisher Mercure
```

Le frontend envoie une requête de voyage via REST ; le backend la traite de manière asynchrone sur plusieurs workers et pousse les mises à jour de statut via Mercure SSE. Pas de base de données — cache Redis pour l'état transitoire, cache filesystem pour les réponses d'API externes.

La sécurité des types est appliquée de bout en bout : les DTO PHP définissent le schéma -> API Platform exporte une spec OpenAPI -> `npm run typegen` génère les types TypeScript -> `openapi-fetch` fournit des appels API typés. Un changement de schéma côté backend provoque intentionnellement une erreur de compilation TypeScript.

---

## Stack technique

| Couche | Technologie |
|--------|-------------|
| Backend | PHP 8.5, Symfony 8, API Platform 4.2, Caddy |
| Frontend | Next.js 16 (App Router), React 19, TypeScript (strict) |
| État | Zustand + Immer (en mémoire), Mercure SSE (temps réel) |
| Carte | MapLibre GL |
| Style | Tailwind CSS, shadcn/ui |
| Tests | PHPUnit 13 (backend), Playwright 1.58 (E2E) |
| Qualité | PHPStan niveau 9, PHP-CS-Fixer, Rector, ESLint, Prettier |
| Asynchrone | Symfony Messenger, transport Redis, 5 workers |
| Runtime | Docker (Caddy, Mercure, Redis, Node) |

---

## Documentation

| Document | Description |
|----------|-------------|
| [Démarrage rapide](docs/getting-started.fr.md) | Prérequis, installation et configuration locale |
| [Contribuer](docs/contributing.fr.md) | Workflow de développement, standards et outillage |
| [Décisions d'architecture](docs/adr/) | ADR expliquant chaque choix technique majeur |
| [Outillage Claude Code](docs/claude-code-tooling.fr.md) | Serveurs MCP, hooks et skills pour le développement assisté par IA |

---

## Démarrage rapide

```bash
git clone https://github.com/vincentchalamon/bike-trip-planner.git
cd bike-trip-planner
make start-dev
```

L'application est disponible sur `https://localhost` (PWA) et `https://localhost/docs` (API).

Voir [Démarrage rapide](docs/getting-started.fr.md) pour les prérequis et la configuration détaillée.

---

## Sources de routes supportées

| Source | Format d'URL |
|--------|--------------|
| Tour Komoot | `https://www.komoot.com/[xx-xx/]tour/<id>` |
| Collection Komoot | `https://www.komoot.com/[xx-xx/]collection/<id>` |
| Route Strava | `https://www.strava.com/routes/<id>` |
| Route RideWithGPS | `https://ridewithgps.com/routes/<id>` |
| Import de fichier GPX | Glisser-déposer ou sélecteur de fichiers (jusqu'à 15 Mo) |

---

## Licence

Ce projet est sous licence [GNU Affero General Public License v3.0](LICENSE).
