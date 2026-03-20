# Contribuer

*[English version](contributing.md)*

Ce guide couvre tout ce dont vous avez besoin pour contribuer a Bike Trip Planner : configurer votre environnement de developpement, utiliser l'outillage assiste par IA, et respecter les standards de qualite du projet.

---

## Environnement de developpement local

Suivez le [Demarrage rapide](getting-started.fr.md) pour installer et lancer l'application. Pour le developpement, utilisez :

```bash
make start-dev
```

Cela demarre plusieurs services en mode developpement :

| Service | URL | Description |
|---------|-----|-------------|
| `php` | `https://localhost/docs` | Backend API Platform |
| `pwa` | `https://localhost` | Frontend Next.js |
| `worker` | Interne uniquement | Worker de messages asynchrones |
| `mercure` | `https://localhost/.well-known/mercure/ui/` | Microservice de push serveur |
| `redis` | Interne uniquement | Microservice de cache |
| `caddy` | Interne uniquement | Microservice serveur web |

> **TLS :** Caddy genere un certificat auto-signe pour `localhost`. Acceptez l'avertissement du navigateur au premier chargement, ou installez le certificat dans le magasin de confiance de votre systeme.

Une fois demarre, vous disposez d'un environnement de developpement pleinement fonctionnel -- le backend PHP et le frontend Next.js supportent tous deux le hot-reload nativement.

### Commandes de developpement utiles

```bash
make php-shell    # Entrer dans le conteneur PHP
make pwa-shell    # Entrer dans le conteneur Node
make qa           # Lancer le pipeline QA complet
make test         # Lancer QA + PHPUnit + Playwright
```

Consultez `make help` pour la liste complete des cibles disponibles.

---

## Workflow de developpement

### 1. Travailler sur une branche de fonctionnalite

```bash
git checkout -b feat/ma-fonctionnalite
```

### 2. Faire des modifications, puis verifier

```bash
make qa           # Doit passer avant chaque commit
```

Le hook pre-commit execute `make qa` automatiquement. Un commit est rejete si le QA echoue.

### 3. Lancer des tests cibles

```bash
# Tests unitaires PHP uniquement
make test-php -- --filter=MaClasseDeTest

# Un test Playwright specifique
make test-e2e -- tests/mon-test.spec.ts
```

#### Architecture des tests E2E

Les tests Playwright sont repartis en deux categories sous `pwa/tests/` :

| Repertoire | Objectif | Backend requis ? |
|------------|----------|------------------|
| `mocked/` | Tests deterministes avec API + SSE mockes | Non |
| `integration/` | Test de fumee contre le vrai backend | Oui |

Les **tests mockes** constituent la strategie E2E principale. Ils interceptent tous les appels HTTP et injectent des evenements Mercure de maniere programmatique, les rendant rapides, deterministes et adaptes a la CI.

##### Fixtures (`pwa/tests/fixtures/`)

Tous les tests mockes etendent une fixture Playwright personnalisee definie dans `base.fixture.ts` qui fournit :

| Fixture | Description |
|---------|-------------|
| `mockedPage` | Une `Page` avec toutes les routes API pre-mockees et navigant vers `/` |
| `injectEvent` | Injecte un seul evenement Mercure SSE dans la page |
| `injectSequence` | Injecte une sequence ordonnee d'evenements avec un delai configurable |
| `submitUrl` | Remplit le champ URL et soumet, en attendant l'apparition du squelette de voyage |
| `createFullTrip` | Raccourci : soumet une URL puis injecte la sequence complete d'evenements |

##### Mocking API avec `page.route()`

`api-mocks.ts` enregistre des handlers de route Playwright pour chaque endpoint backend (POST/PATCH/DELETE trips, stages, geocodage, hebergements, etc.). Chaque handler retourne une reponse JSON statique, et des options permettent de simuler des erreurs :

```typescript
// Exemple : creer un test avec une suppression d'etape en echec
test.use({ mockOptions: { deleteStageFail: true } });
```

Le vrai endpoint SSE Mercure (`/.well-known/mercure`) est **annule** via `page.route()` pour empecher le frontend d'ouvrir une vraie connexion EventSource.

##### Injection SSE via `CustomEvent`

Puisque la vraie connexion SSE est annulee, les evenements sont injectes depuis le code de test via l'API `CustomEvent` du navigateur :

```typescript
// sse-helpers.ts -- injecte un MercureEvent type dans la page
await page.evaluate((evt) => {
  window.dispatchEvent(
    new CustomEvent("__test_mercure_event", { detail: evt }),
  );
}, event);
```

La classe `MercureClient` dans `src/lib/mercure/client.ts` ecoute ces evenements personnalises en plus des vrais messages SSE, permettant une injection de test transparente sans modification du code de production.

##### Donnees mock (`mock-data.ts`)

Fournit des fonctions factory pour chaque type d'evenement Mercure (`routeParsedEvent()`, `stagesComputedEvent()`, `weatherFetchedEvent()`, etc.), assemblees en une `fullTripEventSequence()` qui simule un cycle complet de calcul de voyage.

##### Ecrire un nouveau test E2E mocke

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

### 4. Apres avoir modifie les DTO backend, regenerer les types

```bash
make start-dev                  # Le backend doit tourner
make typegen
```

La compilation TypeScript echouera tant que les types ne sont pas regeneres -- c'est intentionnel. Le contrat de types (voir [ADR-002](adr/adr-002-interface-contract-and-strict-typing.md)) impose la coherence frontend/backend au moment de la compilation.

---

## Standards de qualite du code

### PHP

| Outil | Standard | Commande |
|-------|----------|----------|
| PHPStan | Niveau 9 (strict) | `make phpstan` |
| PHP-CS-Fixer | PSR-12 + regles Symfony | `make php-cs-fixer` |
| Rector | Refactoring automatise | `make rector` |
| PHPUnit | Tests unitaires + integration | `make test-php` |

**Conventions cles :**

- State Providers et Processors, jamais de controllers avec repositories (backend sans etat)
- Les DTO utilisent les suffixes `Request`/`Response` (`TripRequest`, `TripResponse`)
- Les nouvelles regles d'alerte implementent `StageAnalyzerInterface` et sont taguees avec `#[AutoconfigureTag('app.stage_analyzer')]` ; aucune autre inscription necessaire
- Les clients HTTP doivent etre scopes a une URI de base -- jamais de recuperation d'URL libre (prevention SSRF)

### TypeScript / Frontend

| Outil | Standard | Commande |
|-------|----------|----------|
| TypeScript | Mode strict | `make tsc` |
| ESLint | Ruleset Next.js | `make eslint` |
| Prettier | Configuration du projet | `make prettier -- --write .` |
| Playwright | Tests E2E | `make test-e2e` |

**Conventions cles :**

- Tous les appels API passent par le client `openapi-fetch` -- ne jamais utiliser `fetch` directement
- L'etat reside dans les stores Zustand (en memoire, middleware Immer) ; jamais dans l'etat des composants pour les donnees de voyage
- Les resultats de calcul arrivent via des evenements Mercure SSE et sont dispatches via `CustomEvent('__test_mercure_event')` dans les tests
- Les schemas Zod dans `src/lib/validation/` doivent rester manuellement alignes avec les DTO PHP

### Decisions d'architecture

Avant de proposer un changement architectural significatif, lisez l'ADR correspondant dans `docs/adr/`. Si aucun ADR ne couvre votre changement, ecrivez-en un. Les ADR documentent le contexte, les alternatives envisagees et la justification -- pas uniquement la decision.

---

## Configuration Claude Code (developpement assiste par IA)

Le projet est configure pour [Claude Code](https://claude.ai/code). La configuration ci-dessous donne a Claude un contexte precis et specifique au projet et automatise les taches repetitives.

### Hooks (auto-configures)

Le fichier `.claude/settings.json` configure trois hooks qui s'executent automatiquement :

| Hook | Declencheur | Effet |
|------|-------------|-------|
| `PostToolUse` (PHP CS Fixer) | Tout fichier `.php` ecrit/edite | Execute `php-cs-fixer` sur le fichier |
| `PostToolUse` (Rector) | Tout fichier `.php` ecrit/edite | Execute `rector` sur le fichier |
| `PostToolUse` (Prettier) | Tout fichier `.ts`/`.tsx` ecrit/edite | Execute `prettier` sur le fichier |
| `PreToolUse` (guard) | Toute ecriture/edition | Bloque les edits sur `.env`, `schema.d.ts`, `vendor/`, `node_modules/` |

Ces hooks sont scopes au projet et s'appliquent a tous les contributeurs qui utilisent Claude Code sur ce projet.

### Skills (commandes slash)

Deux skills personnalises sont disponibles dans Claude Code :

| Commande | Description |
|----------|-------------|
| `/pick <numero-issue> [branche-base]` | Implemente une issue GitHub de bout en bout (branche, code, test, PR, monitoring CI) |
| `/sprint <numero-sprint>` | Implemente toutes les issues du sprint en parallele via des agents worktree |

### Automatisation GitHub

Claude est aussi disponible directement depuis GitHub, sans session Claude Code locale :

| Declencheur | Ou | Ce qui se passe |
|-------------|-----|-----------------|
| `@claude pick [branche-base]` | Commentaire d'issue | Claude implemente l'issue de bout en bout : cree la branche, code, ouvre une PR, surveille la CI |
| `@claude <instruction>` | Commentaire d'issue/PR | Claude suit l'instruction libre |
| Automatique (a l'ouverture/sync de PR) | Pull requests | Claude effectue une revue de code automatisee (`claude-code-review.yml`) |

Les workflows sont definis dans `.github/workflows/claude.yml` et `.github/workflows/claude-code-review.yml`.

### Serveurs MCP

Le `.mcp.json` a la racine du projet configure le **serveur MCP Apidog**, qui charge la spec OpenAPI live depuis `https://localhost/docs.json` comme contexte Claude. Cela permet :

- Valider le code frontend par rapport au contrat API actuel
- Generer du code client API type-safe a partir des endpoints
- Detecter les derives DTO/TypeScript sans lancer le pipeline QA complet

> **Prerequis :** Le backend PHP doit tourner (`make start-dev`) pour que le serveur MCP Apidog puisse recuperer la spec.

### Outils supplementaires recommandes

Voir [docs/claude-code-tooling.fr.md](claude-code-tooling.fr.md) pour le guide complet, incluant :

- **GitHub MCP Server** -- gerer les PR et issues depuis Claude Code
- **Context7** -- interroger la documentation a jour de Symfony, Next.js et API Platform (deja installe)
- **Playwright MCP** -- automatisation navigateur pour la validation UI et le debug E2E (deja installe)

---

## Reference de structure du projet

<!-- markdownlint-disable MD040 -->
```
bike-trip-planner/
+-- api/                          # Backend PHP
|   +-- src/
|   |   +-- ApiResource/          # DTO API Platform (source unique de verite)
|   |   +-- State/                # State Providers & Processors
|   |   +-- Spatial/              # Parsing GPX, decimation
|   |   +-- Pacing/               # Algorithme de generation d'etapes
|   |   +-- Osm/                  # Requetes API Overpass
|   |   +-- Pricing/              # Tarification heuristique des hebergements
|   |   +-- Analyzer/             # Moteur d'alertes (Chain of Responsibility)
|   |   +-- Weather/              # Fournisseur meteo Open-Meteo
|   |   +-- Scanner/              # Scanner OSM (hebergements, POI)
|   |   +-- Serializer/           # Encodeurs GPX/FIT + WaypointMapper
|   |   +-- RouteFetcher/         # Fetchers de routes (Komoot, Strava, RWGPS)
|   |   +-- Engine/               # Moteurs de calcul (distance, elevation, rythme)
|   |   +-- MessageHandler/       # Handlers de messages asynchrones
|   |   +-- Mercure/              # Publication d'evenements Mercure
|   |   +-- Geo/                  # Outils geospatiaux
|   |   +-- Enum/                 # Enumerations
|   |   +-- Controller/           # Controleurs speciaux (export fichiers)
|   |   +-- Service/              # Services metier
|   |   +-- Routing/              # Routage Valhalla
|   |   +-- Repository/           # Repositories Redis
|   |   +-- OpenApi/              # Decorateurs OpenAPI
|   |   +-- Command/              # Commandes CLI
|   |   +-- Accommodation/        # Logique d'hebergement
|   |   +-- ComputationTracker/   # Suivi de l'avancement des calculs
|   |   +-- Symfony/              # Extensions Symfony
|   +-- templates/                # Templates Twig
+-- pwa/                          # Frontend Next.js
|   +-- src/
|   |   +-- app/                  # Pages App Router Next.js
|   |   +-- store/                # Stores Zustand (en memoire, Immer)
|   |   +-- lib/
|   |   |   +-- api/              # Types generes (schema.d.ts) + client openapi-fetch
|   |   |   +-- validation/       # Schemas Zod
|   |   |   +-- mercure/          # Client Mercure SSE
|   |   +-- components/           # Composants React
|   |   |   +-- Map/              # Carte interactive + profil d'elevation
|   |   |   +-- ViewModeToggle/   # Bascule timeline/carte/divise
|   |   |   +-- SupplyTimeline/   # Timeline de ravitaillement
|   |   |   +-- ui/               # Composants shadcn/ui
|   |   +-- hooks/                # Hooks React personnalises
|   +-- messages/                 # Fichiers i18n (en.json, fr.json)
|   +-- tests/                    # Tests E2E Playwright
|       +-- fixtures/             # Fixtures de test, mocks API, helpers SSE
|       +-- mocked/               # Tests deterministes (API + SSE mockes)
|       +-- integration/          # Test de fumee contre le vrai backend
+-- docs/
|   +-- adr/                      # Architecture Decision Records
|   +-- getting-started.md        # Guide de demarrage (EN)
|   +-- getting-started.fr.md     # Guide de demarrage (FR)
|   +-- contributing.md           # Guide de contribution (EN)
|   +-- contributing.fr.md        # Guide de contribution (FR)
|   +-- claude-code-tooling.md    # Outillage Claude Code (EN)
|   +-- claude-code-tooling.fr.md # Outillage Claude Code (FR)
+-- .github/
|   +-- workflows/
|       +-- claude.yml              # @claude pick + free-form sur issues/PR
|       +-- claude-code-review.yml  # Revue de code automatisee des PR
+-- .claude/
|   +-- settings.json             # Hooks (auto-formatting, protection de fichiers)
|   +-- skills/                   # Commandes slash personnalisees (pick, sprint)
+-- .mcp.json                     # Configuration serveur MCP (Apidog OpenAPI)
```

---

## Soumettre des modifications

1. Assurez-vous que `make qa` passe en local
2. Ecrivez ou mettez a jour les tests pour tout comportement modifie
3. Si vous avez modifie des DTO backend, incluez le `pwa/src/lib/api/schema.d.ts` regenere
4. Ouvrez une pull request avec une description claire de ce qui a change et pourquoi
5. Referencez tout ADR pertinent si le changement affecte l'architecture

Pour des changements significatifs, ouvrez d'abord une issue pour discuter de l'approche avant d'investir dans l'implementation.
