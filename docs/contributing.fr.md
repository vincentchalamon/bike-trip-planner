# Contribuer

*[English version](contributing.md)*

Ce guide couvre tout ce dont vous avez besoin pour contribuer à Bike Trip Planner : configurer votre environnement de développement, utiliser l'outillage assisté par IA, et respecter les standards de qualité du projet.

---

## Environnement de développement local

Suivez le [Démarrage rapide](getting-started.fr.md) pour installer et lancer l'application. Pour le développement, utilisez :

```bash
make start-dev
```

Cela démarre plusieurs services en mode développement :

| Service | URL | Description |
|---------|-----|-------------|
| `php` | `https://localhost/docs` | Backend API Platform |
| `pwa` | `https://localhost` | Frontend Next.js |
| `worker` | Interne uniquement | Worker de messages asynchrones (×5) |
| `mercure` | `https://localhost/.well-known/mercure/ui/` | Microservice de push serveur |
| `redis` | Interne uniquement | Cache et transport Messenger |
| `database` | Interne uniquement | Stockage persistant PostgreSQL 18 |
| `caddy` | Interne uniquement | Serveur web et reverse proxy |
| `valhalla` | Interne uniquement | Moteur de routage Valhalla |
| `mailcatcher` | `http://localhost:1080` | Capture d'emails (développement uniquement) |

> **TLS :** Caddy génère un certificat auto-signé pour `localhost`. Acceptez l'avertissement du navigateur au premier chargement, ou installez le certificat dans le magasin de confiance de votre système.

Une fois démarré, vous disposez d'un environnement de développement pleinement fonctionnel — le backend PHP et le frontend Next.js supportent tous deux le hot-reload nativement.

### Commandes de développement utiles

```bash
make php-shell    # Entrer dans le conteneur PHP
make pwa-shell    # Entrer dans le conteneur Node
make qa           # Lancer le pipeline QA complet
make test         # Lancer QA + PHPUnit + Playwright
```

Consultez `make help` pour la liste complète des cibles disponibles.

---

## Workflow de développement

### 1. Travailler sur une branche de fonctionnalité

```bash
git checkout -b feat/ma-fonctionnalite
```

### 2. Faire des modifications, puis vérifier

```bash
make qa           # Doit passer avant chaque commit
```

### 3. Lancer des tests ciblés

```bash
# Tests unitaires PHP uniquement
make test-php -- --filter=MaClasseDeTest

# Un test Playwright spécifique
make test-e2e -- tests/mon-test.spec.ts
```

#### Architecture des tests E2E

Les tests Playwright sont répartis en deux catégories sous `pwa/tests/` :

| Répertoire | Objectif | Backend requis ? |
|------------|----------|------------------|
| `mocked/` | Tests déterministes avec API + SSE mockés | Non |
| `integration/` | Test de fumée contre le vrai backend | Oui |

Les **tests mockés** constituent la stratégie E2E principale. Ils interceptent tous les appels HTTP et injectent des événements Mercure de manière programmatique, les rendant rapides, déterministes et adaptés à la CI.

##### Fixtures (`pwa/tests/fixtures/`)

Tous les tests mockés étendent une fixture Playwright personnalisée définie dans `base.fixture.ts` qui fournit :

| Fixture | Description |
|---------|-------------|
| `mockedPage` | Une `Page` avec toutes les routes API pré-mockées et naviguant vers `/` |
| `injectEvent` | Injecte un seul événement Mercure SSE dans la page |
| `injectSequence` | Injecte une séquence ordonnée d'événements avec un délai configurable |
| `submitUrl` | Remplit le champ URL et soumet, en attendant l'apparition du squelette de voyage |
| `createFullTrip` | Raccourci : soumet une URL puis injecte la séquence complète d'événements |

##### Mocking API avec `page.route()`

`api-mocks.ts` enregistre des handlers de route Playwright pour chaque endpoint backend (POST/PATCH/DELETE trips, stages, géocodage, hébergements, etc.). Chaque handler retourne une réponse JSON statique, et des options permettent de simuler des erreurs :

```typescript
// Exemple : créer un test avec une suppression d'étape en échec
test.use({ mockOptions: { deleteStageFail: true } });
```

Le vrai endpoint SSE Mercure (`/.well-known/mercure`) est **annulé** via `page.route()` pour empêcher le frontend d'ouvrir une vraie connexion EventSource.

##### Injection SSE via `CustomEvent`

Puisque la vraie connexion SSE est annulée, les événements sont injectés depuis le code de test via l'API `CustomEvent` du navigateur :

```typescript
// sse-helpers.ts — injecte un MercureEvent type dans la page
await page.evaluate((evt) => {
  window.dispatchEvent(
    new CustomEvent("__test_mercure_event", { detail: evt }),
  );
}, event);
```

La classe `MercureClient` dans `src/lib/mercure/client.ts` écoute ces événements personnalisés en plus des vrais messages SSE, permettant une injection de test transparente sans modification du code de production.

##### Données mock (`mock-data.ts`)

Fournit des fonctions factory pour chaque type d'événement Mercure (`routeParsedEvent()`, `stagesComputedEvent()`, `weatherFetchedEvent()`, etc.), assemblées en une `fullTripEventSequence()` qui simule un cycle complet de calcul de voyage.

##### Écrire un nouveau test E2E mocké

```typescript
import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent, stagesComputedEvent } from "../fixtures/mock-data";

test("ma fonctionnalite fonctionne", async ({ mockedPage, submitUrl, injectEvent }) => {
  await submitUrl();
  await injectEvent(routeParsedEvent());
  await injectEvent(stagesComputedEvent());
  // Assertions sur la page rendue
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible();
});
```

### 4. Après avoir modifié les DTO backend, régénérer les types

```bash
make start-dev                  # Le backend doit tourner
make typegen
```

La compilation TypeScript échouera tant que les types ne sont pas régénérés — c'est intentionnel. Le contrat de types (voir [ADR-002](adr/adr-002-interface-contract-and-strict-typing.md)) impose la cohérence frontend/backend au moment de la compilation.

---

## Standards de qualité du code

### PHP

| Outil | Standard | Commande |
|-------|----------|----------|
| PHPStan | Niveau 9 (strict) | `make phpstan` |
| PHP-CS-Fixer | PSR-12 + règles Symfony | `make php-cs-fixer` |
| Rector | Refactoring automatisé | `make rector` |
| PHPUnit | Tests unitaires + intégration | `make test-php` |

**Conventions clés :**

- State Providers et Processors, jamais de controllers avec repositories (backend sans état)
- Les DTO utilisent les suffixes `Request`/`Response` (`TripRequest`, `TripResponse`)
- Les nouvelles règles d'alerte implémentent `StageAnalyzerInterface` et sont taguées avec `#[AutoconfigureTag('app.stage_analyzer')]` ; aucune autre inscription nécessaire
- Les clients HTTP doivent être scopés à une URI de base — jamais de récupération d'URL libre (prévention SSRF)

### TypeScript / Frontend

| Outil | Standard | Commande |
|-------|----------|----------|
| TypeScript | Mode strict | `make tsc` |
| ESLint | Ruleset Next.js | `make eslint` |
| Prettier | Configuration du projet | `make prettier -- --write .` |
| Playwright | Tests E2E | `make test-e2e` |

**Conventions clés :**

- Tous les appels API passent par le client `openapi-fetch` — ne jamais utiliser `fetch` directement
- L'état réside dans les stores Zustand (en mémoire, middleware Immer) ; jamais dans l'état des composants pour les données de voyage
- Les résultats de calcul arrivent via des événements Mercure SSE et sont dispatchés via `CustomEvent('__test_mercure_event')` dans les tests
- Les schémas Zod dans `src/lib/validation/` doivent rester manuellement alignés avec les DTO PHP

### Décisions d'architecture

Avant de proposer un changement architectural significatif, lisez l'ADR correspondant dans `docs/adr/`. Si aucun ADR ne couvre votre changement, écrivez-en un. Les ADR documentent le contexte, les alternatives envisagées et la justification — pas uniquement la décision.

---

## Configuration Claude Code (développement assisté par IA)

Le projet est configuré pour [Claude Code](https://claude.ai/code). La configuration ci-dessous donne à Claude un contexte précis et spécifique au projet et automatise les tâches répétitives.

### Hooks (auto-configurés)

Le fichier `.claude/settings.json` configure trois hooks qui s'exécutent automatiquement :

| Hook | Déclencheur | Effet |
|------|-------------|-------|
| `PostToolUse` (PHP CS Fixer) | Tout fichier `.php` écrit/édité | Exécute `php-cs-fixer` sur le fichier |
| `PostToolUse` (Rector) | Tout fichier `.php` écrit/édité | Exécute `rector` sur le fichier |
| `PostToolUse` (Prettier) | Tout fichier `.ts`/`.tsx` écrit/édité | Exécute `prettier` sur le fichier |
| `PreToolUse` (guard) | Toute écriture/édition | Bloque les edits sur `.env`, `schema.d.ts`, `vendor/`, `node_modules/` |

Ces hooks sont scopés au projet et s'appliquent à tous les contributeurs qui utilisent Claude Code sur ce projet.

### Skills (commandes slash)

Deux skills personnalisés sont disponibles dans Claude Code :

| Commande | Description |
|----------|-------------|
| `/pick <numero-issue> [branche-base]` | Implémente une issue GitHub de bout en bout (branche, code, test, PR, monitoring CI) |
| `/sprint <numero-sprint>` | Implémente toutes les issues du sprint en parallèle via des agents worktree |

### Automatisation GitHub

Claude est aussi disponible directement depuis GitHub, sans session Claude Code locale :

| Déclencheur | Où | Ce qui se passe |
|-------------|-----|-----------------|
| `@claude pick [branche-base]` | Commentaire d'issue | Claude implémente l'issue de bout en bout : crée la branche, code, ouvre une PR, surveille la CI |
| `@claude <instruction>` | Commentaire d'issue/PR | Claude suit l'instruction libre |
| Automatique (à l'ouverture/sync de PR) | Pull requests | Claude effectue une revue de code automatisée (`claude-code-review.yml`) |

Les workflows sont définis dans `.github/workflows/claude.yml` et `.github/workflows/claude-code-review.yml`.

### Outils supplémentaires recommandés

Voir [docs/claude-code-tooling.fr.md](claude-code-tooling.fr.md) pour le guide complet, incluant :

- **GitHub MCP Server** — gérer les PR et issues depuis Claude Code
- **Context7** — interroger la documentation à jour de Symfony, Next.js et API Platform (déjà installé)
- **Playwright MCP** — automatisation navigateur pour la validation UI et le debug E2E (déjà installé)

---

## Référence de structure du projet

<!-- markdownlint-disable MD040 -->
```
bike-trip-planner/
+-- api/                          # Backend PHP
|   +-- src/
|   |   +-- ApiResource/          # DTO API Platform (source unique de vérité)
|   |   +-- State/                # State Providers & Processors
|   |   +-- Spatial/              # Parsing GPX, décimation
|   |   +-- Pacing/               # Algorithme de génération d'étapes
|   |   +-- Osm/                  # Requêtes API Overpass
|   |   +-- Pricing/              # Tarification heuristique des hébergements
|   |   +-- Analyzer/             # Moteur d'alertes (Chain of Responsibility)
|   |   +-- Weather/              # Fournisseur météo Open-Meteo
|   |   +-- Scanner/              # Scanner OSM (hébergements, POI)
|   |   +-- Serializer/           # Encodeurs GPX/FIT + WaypointMapper
|   |   +-- RouteFetcher/         # Fetchers de routes (Komoot, Strava, RWGPS)
|   |   +-- Engine/               # Moteurs de calcul (distance, élévation, rythme)
|   |   +-- MessageHandler/       # Handlers de messages asynchrones
|   |   +-- Mercure/              # Publication d'événements Mercure
|   |   +-- Geo/                  # Outils géospatiaux
|   |   +-- Enum/                 # Énumérations
|   |   +-- Controller/           # Contrôleurs spéciaux (export fichiers)
|   |   +-- Service/              # Services métier
|   |   +-- Routing/              # Routage Valhalla
|   |   +-- Repository/           # Repositories Redis
|   |   +-- OpenApi/              # Décorateurs OpenAPI
|   |   +-- Command/              # Commandes CLI
|   |   +-- Accommodation/        # Logique d'hébergement
|   |   +-- ComputationTracker/   # Suivi de l'avancement des calculs
|   |   +-- Symfony/              # Extensions Symfony
|   +-- templates/                # Templates Twig
+-- pwa/                          # Frontend Next.js
|   +-- src/
|   |   +-- app/                  # Pages App Router Next.js
|   |   +-- store/                # Stores Zustand (en mémoire, Immer)
|   |   +-- lib/
|   |   |   +-- api/              # Types générés (schema.d.ts) + client openapi-fetch
|   |   |   +-- validation/       # Schémas Zod
|   |   |   +-- mercure/          # Client Mercure SSE
|   |   +-- components/           # Composants React
|   |   |   +-- Map/              # Carte interactive + profil d'élévation
|   |   |   +-- ViewModeToggle/   # Bascule timeline/carte/divisé
|   |   |   +-- SupplyTimeline/   # Timeline de ravitaillement
|   |   |   +-- ui/               # Composants shadcn/ui
|   |   +-- hooks/                # Hooks React personnalisés
|   +-- messages/                 # Fichiers i18n (en.json, fr.json)
|   +-- tests/                    # Tests E2E Playwright
|       +-- fixtures/             # Fixtures de test, mocks API, helpers SSE
|       +-- mocked/               # Tests déterministes (API + SSE mockés)
|       +-- integration/          # Test de fumée contre le vrai backend
+-- docs/
|   +-- adr/                      # Architecture Decision Records
|   +-- getting-started.md        # Guide de démarrage (EN)
|   +-- getting-started.fr.md     # Guide de démarrage (FR)
|   +-- contributing.md           # Guide de contribution (EN)
|   +-- contributing.fr.md        # Guide de contribution (FR)
|   +-- claude-code-tooling.md    # Outillage Claude Code (EN)
|   +-- claude-code-tooling.fr.md # Outillage Claude Code (FR)
+-- .github/
|   +-- workflows/
|       +-- claude.yml              # @claude pick + free-form sur issues/PR
|       +-- claude-code-review.yml  # Revue de code automatisée des PR
+-- .claude/
|   +-- settings.json             # Hooks (auto-formatting, protection de fichiers)
|   +-- skills/                   # Commandes slash personnalisées (pick, sprint)
```

---

## Soumettre des modifications

1. Assurez-vous que `make qa` passe en local
2. Écrivez ou mettez à jour les tests pour tout comportement modifié
3. Si vous avez modifié des DTO backend, incluez le `pwa/src/lib/api/schema.d.ts` régénéré
4. Ouvrez une pull request avec une description claire de ce qui a changé et pourquoi
5. Référencez tout ADR pertinent si le changement affecte l'architecture

Pour des changements significatifs, ouvrez d'abord une issue pour discuter de l'approche avant d'investir dans l'implémentation.
