<h1 align="center">Bike Trip Planner</h1>

<p align="center">
  <strong>Planifiez vos aventures bikepacking en toute confiance.</strong>
</p>

<p align="center">
  Collez une URL Komoot ou importez un fichier GPX, et obtenez un roadbook structuré<br />
  jour par jour avec rythme intelligent, alertes de sécurité et suggestions d'hébergement.
</p>

<p align="center">
  <em><a href="README.md">English version</a></em>
</p>

<p align="center">
  <a href="https://github.com/vincentchalamon/bike-trip-planner/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-blue.svg" alt="Licence" /></a>
  <img src="https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white" alt="PHP 8.5" />
  <img src="https://img.shields.io/badge/Symfony-8-000000?logo=symfony&logoColor=white" alt="Symfony 8" />
  <img src="https://img.shields.io/badge/Next.js-16-000000?logo=next.js&logoColor=white" alt="Next.js 16" />
  <img src="https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black" alt="React 19" />
  <img src="https://img.shields.io/badge/TypeScript-strict-3178C6?logo=typescript&logoColor=white" alt="TypeScript" />
  <img src="https://img.shields.io/badge/API%20Platform-4.3-38B2AC?logo=api-platform&logoColor=white" alt="API Platform 4.3" />
  <img src="https://img.shields.io/badge/Docker-ready-2496ED?logo=docker&logoColor=white" alt="Docker" />
</p>

---

## Captures d'écran

> **Desktop** — Vue divisée avec timeline jour par jour, alertes contextuelles et carte interactive.

![Desktop - Vue divisée](docs/assets/screenshots/desktop-split-view.png)

> **Mobile** — Timeline responsive avec météo, badge de difficulté et points de ravitaillement.

<p align="center">
  <img src="docs/assets/screenshots/mobile-timeline.png" alt="Mobile - Timeline" width="300" />
</p>

---

## Fonctionnalités

**Importez votre itinéraire en quelques secondes** — Collez un lien Komoot, Strava ou RideWithGPS, ou importez directement un fichier GPX. Le backend récupère, analyse et traite tout de manière asynchrone.

**Moteur de rythme intelligent** — Répartit automatiquement la distance sur les jours en tenant compte de la fatigue cumulée et du dénivelé. Objectifs journaliers configurables avec un plancher de sécurité.

**20+ alertes de sécurité et de confort** — Un moteur de règles analyse chaque étape : pentes raides, trafic dangereux, vent de face, qualité de surface, autonomie e-bike, coucher de soleil, ravitaillement, et plus — avec trois niveaux de sévérité (critical, warning, nudge).

**Recherche d'hébergements** — Détecte bivouacs, refuges et gîtes à proximité de chaque fin d'étape via OpenStreetMap, avec une estimation de prix heuristique.

**Points d'intérêt culturels** — Repère musées, monuments, châteaux, points de vue et autres attractions le long de l'itinéraire, avec une action « ajouter à l'itinéraire ».

**Traitement temps réel** — Des workers asynchrones calculent votre voyage en parallèle ; les mises à jour de statut sont poussées vers le navigateur via Mercure SSE. Aucun rechargement de page.

**Analyse IA (optionnelle, à clé personnelle)** — Désactivée par défaut, entièrement opt-in. Activez-la dans les réglages de votre compte en choisissant un fournisseur — Anthropic (Claude), Google (Gemini) ou OpenAI — et en collant votre propre clé API. Votre clé alimente un résumé par étape et pour l'ensemble du voyage, ainsi qu'un assistant conversationnel context-aware — y compris un mode « in-ride » qui repère les points d'intérêt à proximité. La clé est chiffrée au repos et n'est jamais renvoyée par l'API. Quand l'IA est active, les données du voyage (tracé, villes, dates) sont envoyées au fournisseur que vous avez choisi avec votre propre clé et facturées sur votre compte ; rien n'est transmis à un tiers sans votre opt-in. Dégradation gracieuse : sans clé, avec une clé invalide, un quota atteint ou un fournisseur indisponible, les alertes basées sur les règles restent affichées.

**Export multi-format** — Exportez des fichiers GPX enrichis de waypoints (hébergements, points d'eau, POI), prêts pour votre GPS. Téléchargez les fichiers FIT par étape pour Garmin, ou générez un résumé texte du roadbook.

**Votre compte, vos données** — Connexion sans mot de passe par magic link. Exportez toutes vos données en JSON ou supprimez votre compte (anonymisation irréversible) à tout moment. Analytics respectueux de la vie privée, sans cookie (Plausible auto-hébergé) — aucun traceur tiers.

---

## Sources de routes supportées

| Plateforme | Formats d'URL supportés |
|---|---|
| **Komoot** | `komoot.com/[xx-xx/]tour/123` et `komoot.com/[xx-xx/]collection/123` |
| **Strava** | `strava.com/routes/123` |
| **RideWithGPS** | `ridewithgps.com/routes/123` |
| **Import GPX** | Upload direct (jusqu'à 30 Mo) |

---

## Tags d'hébergement OSM supportés

| Type logique | Requête OSM | Tarif heuristique |
|---|---|---|
| `hotel` | `tourism=hotel` | 50–120 € |
| `motel` | `tourism=motel` | 45–90 € |
| `guest_house` | `tourism=guest_house` | 40–80 € |
| `chalet` | `tourism=chalet` | 30–70 € |
| `hostel` | `tourism=hostel` | 20–35 € |
| `alpine_hut` | `tourism=alpine_hut` | 25–45 € |
| `camp_site` | `tourism=camp_site` | 8–25 € (8–15 € si `backpack=yes` ou `tents=yes`) |
| `wilderness_hut` | `tourism=wilderness_hut` | gratuit / donation (0–10 €) |
| `shelter` | `amenity=shelter` + `shelter_type~basic_hut\|weather_shelter\|lean_to` | gratuit (0 €) |

---

## Démarrage rapide

```bash
git clone https://github.com/vincentchalamon/bike-trip-planner.git
cd bike-trip-planner
make start-dev
```

L'application est disponible sur :

- **<https://localhost>** — Application web
- **<https://localhost/docs>** — Documentation de l'API (Swagger UI)

Voir [Démarrage rapide](docs/getting-started.fr.md) pour les prérequis et la configuration détaillée.

---

## Moteur d'alertes

Le backend exécute un pipeline d'analyseurs sur chaque étape. Trois niveaux de sévérité :

| Niveau | Badge | Description |
|--------|-------|-------------|
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
| **Calendrier** | — | nudge | L'étape tombe un jour férié français |
| **Calendrier** | — | nudge | L'étape tombe un dimanche (commerces potentiellement fermés) |
| **Vent** | — | warning | Vent de face >= 25 km/h sur >= 60 % des étapes avec données météo |
| **Confort** | — | warning | Indice de confort faible (< 40/100) sur au moins une étape |
| **Ateliers vélo** | — | nudge | Pas d'atelier de réparation dans un rayon de 2 km du milieu de l'étape (voyages > 5 étapes) |
| **Ateliers vélo** | — | nudge | L'atelier à proximité vend des vélos mais n'offre pas de service de réparation |
| **Ravitaillement** | — | nudge | Étape >= 40 km sans POI de ravitaillement/alimentation le long de la route |
| **Ravitaillement** | — | warning | Tous les POI de ravitaillement de l'étape sont fermés à l'heure de passage estimée |
| **Hébergement** | — | warning | Tous les hébergements détectés sur l'étape sont probablement fermés (saisonnalité) |
| **Points d'eau** | — | nudge | Tronçon > 30 km sans source d'eau potable détectée |
| **Jour de repos** | 100 | nudge | Tous les N jours consécutifs de vélo sans jour de repos (défaut : tous les 3 jours) |
| **POI culturels** | — | nudge | Musée, monument, château, église, point de vue ou attraction à moins de 500 m — enrichi (horaires, prix, description) quand la source est DataTourisme |
| **Gare** | — | nudge | Aucune gare ferroviaire dans 10 km d'un point d'étape (évacuation d'urgence) |
| **Services de santé** | — | nudge | Aucune pharmacie, hôpital ou clinique dans 15 km d'une étape |
| **Passage de frontière** | — | nudge | La route franchit une frontière internationale (changement de pays détecté via Overpass `is_in`) |

**Règles terrain** (Continuité, Élévation, Pente raide, Surface, Trafic, Autonomie e-bike, Coucher de soleil, Jour de repos) implémentent `StageAnalyzerInterface` et sont auto-découvertes via `#[AutoconfigureTag('app.stage_analyzer')]`. Les règles avec une priorité `—` (Calendrier, Vent + Confort, Ateliers vélo, Ravitaillement, Hébergement, Points d'eau, POI culturels, Gare, Services de santé, Passage de frontière) sont des handlers de messages Symfony asynchrones séparés ; Confort est co-localisé avec Vent dans `AnalyzeWindHandler`.

> Table de référence à jour et canonique : [README.md](README.md#alert-engine) (EN). Cette version FR est une traduction de confort.

---

## Vue d'ensemble de l'architecture

<!-- markdownlint-disable MD040 -->
```
Navigateur (Next.js 16)        Backend PHP (API Platform 4.3)
  Zustand + Immer (en mémoire)   Calcul sans état
  Validation Zod                 Parsing GPX + moteur de rythme
  openapi-fetch (typé)           APIs OSM Overpass + météo
  Mercure SSE (temps réel) <--   Workers asynchrones (Symfony Messenger)
                                 PostgreSQL + Redis + publisher Mercure
```

Le frontend envoie une requête de voyage via REST ; le backend la traite de manière asynchrone sur plusieurs workers et pousse les mises à jour de statut via Mercure SSE. PostgreSQL 18 (Doctrine ORM) persiste la configuration des voyages et les étapes ; Redis gère l'état de calcul transitoire, le transport Messenger et les caches d'API externes.

La sécurité des types est appliquée de bout en bout : les DTO PHP définissent le schéma -> API Platform exporte une spec OpenAPI -> `npm run typegen` génère les types TypeScript -> `openapi-fetch` fournit des appels API typés. Un changement de schéma côté backend provoque intentionnellement une erreur de compilation TypeScript.

---

## Stack technique

| Couche | Technologie |
|--------|-------------|
| Backend | PHP 8.5, Symfony 8, API Platform 4.3, Caddy |
| Frontend | Next.js 16 (App Router), React 19, TypeScript (strict) |
| État | Zustand + Immer (en mémoire), Mercure SSE (temps réel) |
| Carte | MapLibre GL |
| Style | Tailwind CSS, shadcn/ui |
| Persistance | PostgreSQL 18 (Doctrine ORM), Redis (transitoire + caches) |
| IA | `symfony/ai-platform` (Anthropic, Gemini, OpenAI — BYO token) |
| Tests | PHPUnit 13 (backend), Playwright 1.60 (E2E) |
| Qualité | PHPStan niveau 9, PHP-CS-Fixer, Rector, ESLint, Prettier |
| Asynchrone | Symfony Messenger, transport Redis, 5 workers |
| Runtime | Docker (Caddy, Mercure, Redis, PostgreSQL, Node) |

---

## Documentation

| Document | Description |
|----------|-------------|
| [Index de la documentation](docs/README.fr.md) | Trouver la doc selon le besoin |
| [Démarrage rapide](docs/getting-started.fr.md) | Prérequis, installation et configuration locale |
| [Contribuer](docs/contributing.fr.md) | Workflow de développement, standards et outillage |
| [Déploiement](docs/deployment.md) | CI/CD, secrets, rollback (EN) |
| [Décisions d'architecture](docs/adr/) | ADR expliquant chaque choix technique majeur (EN) |
| [Architecture](docs/architecture.md) | Vue d'ensemble du système (EN) |
| [Légal & licences](docs/legal-and-licensing.fr.md) | Licence, attribution des données, RGPD |
| [Outillage Claude Code](docs/claude-code-tooling.fr.md) | Serveurs MCP, hooks et skills pour le développement assisté par IA |

---

## Sources de données externes

| Source | Rôle | Licence | Couverture | Prérequis |
|--------|------|---------|------------|-----------|
| **OpenStreetMap** | Principale : routes, infra cyclable, eau, ateliers, ravitaillement, POI et hébergements de base | [ODbL](https://opendatacommons.org/licenses/odbl/) | Mondiale | Aucun |
| **DataTourisme** | Complémentaire : hébergements et POI culturels enrichis ; exclusif : événements datés | [Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence) | France | `DATATOURISME_API_KEY` |
| **Wikidata** | Enrichissement transverse : descriptions multilingues, images, liens Wikipedia via Q-IDs | [CC0](https://creativecommons.org/publicdomain/zero/1.0/) | Europe | Aucun |
| **Open-Meteo** | Prévisions météo | [CC-BY](https://creativecommons.org/licenses/by/4.0/) | Mondiale | Aucun |

Détails de configuration (clés API, provisioning OSM via Valhalla, refresh manuel) : voir [README.md](README.md#external-data-sources) (EN) et [docs/deployment.md](docs/deployment.md). Attribution OSM obligatoire : « © les contributeurs OpenStreetMap ».

---

## Contribuer

Les contributions sont les bienvenues ! Merci de lire le [guide de contribution](docs/contributing.fr.md) avant de soumettre une pull request.

```bash
make start-dev    # Démarre l'environnement Docker
make qa           # Suite QA complète (lint, analyse statique, formatage)
make test         # Tous les tests (QA + PHPUnit + Playwright)
```

---

## Licence

Ce projet est sous licence [GNU Affero General Public License v3.0](LICENSE).
