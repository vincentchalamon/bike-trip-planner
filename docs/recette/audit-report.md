# Rapport d'audit non-fonctionnel & qualité — Sprint 35.2

> **Statut : rapport d'audit, AUCUN fix de code applicatif.** Les findings ci-dessous sont destinés à être
> ouverts en issues GitHub (milestone `Sprint 35.4`, labels `security`/`perf`/`a11y`/`seo`/`i18n`/`quality`/`privacy`
> et sévérité `P0`-`P3`). La création des issues est différée à validation utilisateur. Seuls l'**outillage de
> recette** (PR #612) et la **dégradation gracieuse DataTourisme** (PR #613) ont été livrés pendant l'audit.

**Date :** 2026-06-03

**Périmètre :** features livrées sur `main` (sprints 1-33, design S25-27, IA S28-32, S34/34.5),
hors S18 #313/#314 (abandonnés) et osm-cron nightly (#575).

**Dépend de :** Sprint 35 (outillage) — livré (#601-605).

## Méthodologie & environnement

L'audit a été conduit sur une stack **iso-prod fidèle, rebâtie depuis le code courant de `main`** (une première
image `:ci` périmée masquait des régressions et a été écartée), complétée par une stack **dev** pour la seule
couverture de tests (besoin de xdebug). Les verdicts ci-dessous reflètent l'**état courant**, pas l'historique
des passes.

| Stack | Configuration | Ce qui y est vérifié |
|---|---|---|
| **iso-prod (recette)** | `compose.yaml` + `compose.recette.yaml` + `compose.ollama.yaml` (`make start-recette`) : `APP_ENV=prod`, `read_only`, clés JWT locales, **Mailcatcher** (capture magic-link) + **Ollama** (tier IA), images rebâties depuis `main` | Golden path authentifié réel, headers Caddy, suppression stack-trace, isolation Mercure, OG/SEO, HTML rendu (a11y/SEO), DataTourisme, Lighthouse, intégrations externes |
| **dev** | `compose.yaml` + `compose.dev.yaml` (`make start-dev`), xdebug présent, clés régénérées via `make jwt-keypair-test` (`JWT_PASSPHRASE=test` forcé par `phpunit.dist.xml`) | Couverture PHPUnit chiffrée, `i18n-check`, audits de dépendances |

**Golden path réel exercé :** compte créé via `app:create-user` -> magic-link capturé dans Mailcatcher ->
`POST /auth/verify` (`application/ld+json`) -> JWT -> création de trips depuis des liens **Komoot réels** (dont
« Entre Sensée et Escaut », zone Lille) -> calcul async -> partage.

**Outillage livré pendant l'audit (PR #612) :** `make start-recette`, réparation `make lighthouse`
(`CHROME_PATH` + workflow manuel `lighthouse.yml`), `make jwt-keypair-test`, `compose.recette.yaml`,
`compose.ollama.yaml`, `scripts/recette-seed.sh`.

**Sévérités :** P0 critique exploitable · P1 élevé · P2 moyen (durcissement impactant) · P3 faible (hygiène).

---

## Synthèse des findings

| ID | Titre | Sévérité | Label | Statut |
|---|---|---|---|---|
| F1 | `LOCK_DSN` absent de `compose.yaml` -> fallback `redis://localhost` injoignable -> **création de trip 500 en prod** | **P0** | quality | Confirmé |
| SEO-001 | Pages de partage `/s/[code]` sans `generateMetadata` (aperçu social cassé) | P1 | seo | Confirmé |
| F3 | Intégration **Ollama prod absente de `compose.yaml`** + divergence ADR-028 (mode dégradé silencieux) | P1 | quality | Confirmé |
| F4 | **Rate-limit `/auth/request-link` inopérant** : 6× même email -> `202`, aucun `429` | P1 | security | Confirmé |
| F2 | `DataTourismeClient` `TypeError` si clé absente/vide -> `ScanAccommodations`/`ScanEvents` crashent | ~~P1~~ | quality | **Corrigé (PR #613)** |
| SEC-001 | Pas de Content-Security-Policy sur les réponses HTML | P2 | security | Confirmé |
| SEC-002 | Pas de Strict-Transport-Security (HSTS) | P2 | security | Confirmé |
| SEC-003 | Pas de X-Frame-Options ni X-Content-Type-Options sur les pages PWA | P2 | security | Confirmé |
| SEO-002 | `robots.txt` et `sitemap.xml` absents (404) | P2 | seo | Confirmé |
| SEO-003 | Aucune balise Open Graph / Twitter Card sur les pages publiques | P2 | seo | Confirmé |
| A11Y-001 | Landing `/` sans landmark `<main>` dans le HTML rendu | P2 | a11y | Confirmé |
| A11Y-002 | Landing `/` sans `<h1>` dans le HTML rendu (SSR) | P2 | a11y | Confirmé |
| LH-PERF-HOME | Landing `/` score Lighthouse Performance **0.65** (< 0.80) | P2 | perf | Confirmé |
| LH-A11Y-HOME | Landing `/` score Lighthouse Accessibility **0.84** (< 0.90) | P2 | a11y | Confirmé |
| DT-LIVE | DataTourisme mode live cassé : scoped client sans `base_uri` + endpoint obsolète | P2 | quality | Confirmé |
| F5 | APIs externes flaky : **Overpass `429`/timeout** -> POI/alertes dégradés | P2 | perf | Confirmé |
| QUAL-001 | Aucun seuil de couverture PHPUnit (`fail-under`) | P2 | quality | Confirmé |
| QUAL-002 | Aucun seuil de couverture Vitest (frontend) | P2 | quality | Confirmé |
| COV-API | Couverture PHPUnit API **71,8 %** statements (< 80 % DoD) | P2 | quality | Confirmé |
| CI-UNIT | Tests unitaires front (Vitest) **non exécutés en CI** | P2 | quality | Confirmé |
| QUAL-004 | `make lighthouse` non exécutable (Chrome introuvable dans l'image lhci) | ~~P2~~ | quality | **Corrigé (PR #612)** |
| SEC-004 | Pas de Referrer-Policy | P3 | security | Confirmé |
| SEC-005 | En-tête `x-powered-by: Next.js` exposé en prod | P3 | security | Confirmé |
| PERF-001 | `maplibre-gl` importé statiquement dans `trip-planner.tsx` | P3 | perf | Confirmé |
| I18N-001 | Pas de handler `onError` sur `NextIntlClientProvider` | P3 | i18n | Confirmé |
| QUAL-003 | Dette de suppressions statiques (7 `@phpstan-ignore`, 5 front) | P3 | quality | Confirmé |
| COV-PROV | Couverture provisioner non mesurable (xdebug absent du conteneur) | P3 | quality | Confirmé |

**Total : 1×P0, 3×P1 actifs (+1 corrigé), 15×P2 (+1 corrigé), 6×P3.** Le « aucun P0 » d'une première lecture
est **caduc** : F1 casse la création de trip en prod par défaut.

---

## Findings détaillés

### Configuration de production & intégrations externes

#### F1 — `LOCK_DSN` absent de `compose.yaml` -> création de trip 500 en prod — P0

`compose.yaml` (iso-prod) n'expose pas `LOCK_DSN` ; le `framework.lock` retombe sur la valeur d'`api/.env`
(`redis://localhost:6379`), injoignable depuis le conteneur (Redis est le service `redis`). À la création d'un
trip, l'acquisition de lock échoue :

```text
LockAcquiringException: Failed to acquire the "..." lock — Redis connection refused (localhost:6379)
# POST /trips -> 500 ; OK après override LOCK_DSN=redis://redis:6379
```

**Reco (35.4) :** ajouter `LOCK_DSN: redis://redis:6379` à `compose.yaml`. (Contourné en recette via
`compose.recette.yaml` pour débloquer le golden path.)

#### F3 — Intégration Ollama prod absente + divergence ADR-028 — P1

`grep OLLAMA compose.yaml` = ∅ : le tier IA (analyse d'étapes, overview, chat) n'a aucune définition dans la
stack iso-prod. De plus `HealthController::$required` n'inclut pas Ollama -> health vert même IA absente, un
**mode dégradé silencieux** contraire à l'ADR-028 (« hard dependency »).
**Reco (35.4) :** versionner Ollama en prod (`compose.ollama.yaml` livré PR #612 sert de base) ; aligner ADR-028
(soit Ollama dans `$required`, soit acter le mode dégradé dans l'ADR).

#### F4 — Rate-limit `/auth/request-link` inopérant — P1

6 requêtes successives sur le même email renvoient toutes `202`, **aucun `429`** :

```text
$ for i in $(seq 1 6); do curl -sk -o /dev/null -w "%{http_code} " -X POST https://localhost/auth/request-link \
    -H 'Content-Type: application/ld+json' -d '{"email":"flood@example.com"}'; done
202 202 202 202 202 202
```

Les limiters sont pourtant configurés (`magic_link_email` 3/900s, cf. conformités Ordre 1). Piste : le storage du
limiter en prod `read_only` (filesystem/cache) n'est pas writable, le limiter no-op silencieusement.
**Reco (35.4) :** investiguer le storage du limiter sous `read_only` (Redis plutôt que cache filesystem).

#### DT-LIVE — DataTourisme mode live cassé — P2 (feature OFF par défaut)

La clé d'API est valide (`GET https://api.datatourisme.fr/v1/catalog` -> 200), mais en mode live
(`DATATOURISME_ENABLED=true` + clé) **tous les appels échouent** :

```text
worker | WARNING [app] DataTourisme request failed, returning empty result.
        ["error" => "Invalid URL: scheme is missing in "/api/v1/places?filters[0]...". Did you forget to add "http(s)://"?"]
```

Deux défauts : **(1)** le scoped client `datatourisme.client` (`api/config/packages/framework.php:81`) déclare un
`scope` (regex SSRF) **sans `base_uri`** (contrairement à komoot/strava/overpass) -> `request('/api/v1/places')`
(path relatif) n'a aucune base à préfixer ; **(2)** l'endpoint `/api/v1/places` est **obsolète** (l'API v1 actuelle
expose `/v1/catalog` et `/v1/placeOfInterest`).
**Reco (35.4) :** ajouter `base_uri: https://api.datatourisme.fr` au scoped client + migrer endpoints/format vers
l'API v1. (Le mode **dégradé** — clé absente/vide — est OK depuis PR #613, cf. ci-dessous.)

#### F5 — APIs externes flaky : Overpass `429`/timeout — P2

```text
worker | WARNING [app] Overpass query failed: 429 Too Many Requests
```

POI et alertes terrain dégradés quand Overpass throttle. Tiers, hors de notre contrôle direct.
**Reco :** durcir cache + retry/backoff, documenter le comportement dégradé.

#### Conformités intégrations vérifiées

+ **DataTourisme — dégradé OK (fix PR #613)** : avec `ENABLED=true` et clé **vide** (le cas du bug F2),
  `ScanAccommodations` est « handled successfully », **aucun `TypeError`** (`?string $apiKey` + `isEnabled()`
  null-safe -> source skippée par les registries). Avant le fix, ce cas crashait en boucle de retry.
+ **Golden path** : le trip « Entre Sensée et Escaut » (Komoot, zone Lille) est **calculé** (stages,
  distance 70,2 km, dénivelé) — fetch source + pacing OK.
+ **Auth réelle** : magic-link via Mailcatcher -> JWT (`/auth/verify` en `application/ld+json`) -> création de
  trip authentifiée -> partage.

---

### Sécurité (Ordre 1)

#### SEC-001 — Pas de Content-Security-Policy — P2

Les réponses HTML servies par la PWA ne portent aucun en-tête `Content-Security-Policy`.
Le `.docker/php/Caddyfile` (l.64-67) n'ajoute que `Link` et `Permissions-Policy`.

```text
$ curl -skD - -o /dev/null -H 'Accept: text/html' https://localhost/
# en-têtes présents : alt-svc, cache-control, content-type, date, link,
#                      permissions-policy, vary, via, x-powered-by
# absent : content-security-policy
```

**Impact :** pas de défense en profondeur contre l'injection de scripts.
**Reco :** CSP même restrictive **dans le Caddyfile** (socle pérenne FrankenPHP, cf. décision projet — pas à
l'edge Coolify remplaçable).

#### SEC-002 — Pas de HSTS — P2

Aucun `Strict-Transport-Security` sur les réponses (HTTP et HTTPS).
**Reco :** `Strict-Transport-Security: max-age=31536000; includeSubDomains` dans le Caddyfile.

#### SEC-003 — Pas de X-Frame-Options / X-Content-Type-Options sur les pages PWA — P2

Les pages HTML de la PWA (surface de clickjacking / MIME sniffing) ne portent ni `X-Frame-Options` ni
`X-Content-Type-Options` (dump d'en-têtes SEC-001). API Platform pose ces en-têtes sur certaines réponses
d'erreur, mais **pas** sur les documents HTML applicatifs : le finding ne vaut que pour la PWA.
**Reco :** `X-Frame-Options: DENY` + `X-Content-Type-Options: nosniff` dans le Caddyfile.

#### SEC-004 — Pas de Referrer-Policy — P3

Aucun `Referrer-Policy`. Hygiène. **Reco :** `Referrer-Policy: strict-origin-when-cross-origin`.

#### SEC-005 — `x-powered-by: Next.js` exposé — P3

```text
$ curl -skD - -o /dev/null -H 'Accept: text/html' https://localhost/ | grep -i x-powered-by
x-powered-by: Next.js
```

Fingerprinting du framework (facilite le ciblage de CVE). **Reco :** `poweredByHeader: false` dans `next.config`.

#### Conformités sécurité vérifiées

+ **Rate limiting (config)** : `magic_link_email` (3/900s), `magic_link_ip` (10/900s), `access_request_ip`
  (3/3600s) — `rate_limiter.php` + appliqués dans `AuthRequestLinkProcessor` / `AccessRequestCreateProcessor`.
  **Mais inopérant au runtime prod (cf. F4).**
+ **XSS (statique)** : aucun `dangerouslySetInnerHTML` dans `pwa/src` (React échappe par défaut). Test payload
  sur champ éditable = à exécuter (cf. « Reste à exécuter »).
+ **Auth 401 / IDOR** : `/trips` et endpoints protégés renvoient `401 JWT Token not found` non authentifié ;
  IDOR couvert par `TripVoter` (`is_granted('TRIP_VIEW'/'TRIP_EDIT', object)`).
+ **Stack-trace prod — confirmé empiriquement** : de vrais `500` (lock F1) et `415` (mauvais content-type)
  renvoient un `problem+json` propre, **sans trace ni nom de classe** exposés ; `/_profiler` -> 404,
  `web_profiler` désactivé en prod.
+ **Isolation Mercure — confirmée empiriquement** : un abonné **anonyme** au topic privé `/trips/{id}` ne reçoit
  **aucune donnée** (seulement le heartbeat SSE `:`). Les updates `private: true` (`TripUpdatePublisher`) ne sont
  délivrés qu'aux porteurs d'un JWT subscriber scopé (`MercureTokenIssuer`, cookie `Secure`+`HttpOnly`+
  `SameSite=strict`) ; le `anonymous` du hub autorise la connexion mais pas la lecture des topics privés.
+ **`composer audit`** : `No security vulnerability advisories found` (conteneur prod).

---

### Performance (Ordre 2)

#### PERF-001 — `maplibre-gl` importé statiquement — P3

`pwa/src/components/trip-planner.tsx:30` importe `MapPanel` directement (chaîne `MapPanel -> MapView ->
maplibre-gl ~5.24`), sans `next/dynamic`. Atténué : `TripPlanner` n'est monté que sur les routes éditeur
(`/trips/new`, `/trips/[id]`, `/s/[code]`), elles-mêmes en `dynamic()`.
**Reco :** lazy-load `MapPanel` pour alléger le 1er chunk de la route éditeur.

#### Conformités performance vérifiées

+ **N+1 Doctrine (statique)** : `TripCollectionProvider` (`leftJoin('t.stages')+addSelect` +
  `Paginator(fetchJoinCollection: true)`) ; `DoctrineTripRequestRepository::storeStages` bulk DELETE + flush
  unique ; updates JSONB par étape atomiques sans charger l'agrégat. Profiling runtime seedé = à exécuter.
+ **Caches** : OSM 24h, météo 3h (ADR-022), points bruts/décimés 30 min.
+ **Async** : `FetchWeatherHandler` (cache->batch uncached->calcul), `OsmScanner::queryBatch` (Overpass
  concurrent), `ScanAccommodationsHandler` (multiplexage HTTP 2 vagues, SPARQL Wikidata batch).
+ **Profil altimétrique** : SVG custom O(n) + recherche binaire, sans lib de charting lourde.

---

### Accessibilité (Ordre 3)

#### A11Y-001 / A11Y-002 — Landing `/` sans `<main>` ni `<h1>` dans le HTML rendu — P2

```text
$ curl -sk -H 'Accept: text/html' https://localhost/ | grep -oE "<(h1|h2|main|nav|header)[ >]"
# (aucun résultat)
$ curl -sk -H 'Accept: text/html' https://localhost/login | grep -oE "<(h1|main)[ >]"
<h1
<main
```

La landing rend son `<h1>` (`landing/hero.tsx`) et sa structure **côté client** après un contrôle d'auth ; le
HTML initial n'expose donc ni `<h1>` ni landmark `<main>` — pénalise lecteurs d'écran et SEO. La page `/login`
est conforme (preuve ci-dessus).
**Reco :** garantir un `<main>` + `<h1>` dans le rendu serveur de la landing.

#### LH-A11Y-HOME — Lighthouse Accessibility `/` = 0.84 (< 0.90) — P2

Score Lighthouse confirmant A11Y-001/002 (`<h1>`/`<main>` absents du HTML SSR). Mêmes corrections que
A11Y-001/002.

#### Conformités a11y vérifiées

+ `lang="fr"` sur `<html>` ; `/faq`, `/legal`, `/privacy` ont hiérarchie h1->h2 correcte + landmarks
  (Lighthouse A11y >= 0.90 sur ces pages).
+ Formulaires (login, early-access) : `<label htmlFor>`, `aria-describedby`, `aria-invalid`.
+ Dialogs via Radix UI (a11y intégrée) ; images avec `alt` ; boutons carousel avec `aria-label`.
+ Helper `expectNoCriticalA11yViolations` (@axe-core/playwright) câblé dans les fixtures (#601). axe runtime sur
  pages **authentifiées** = à exécuter (cf. « Reste à exécuter »).

---

### SEO (Ordre 4)

#### SEO-001 — Pages de partage `/s/[code]` sans métadonnées dynamiques — P1

`pwa/src/app/s/[code]/page.tsx` et `shared-trip-page.tsx` n'exportent ni `metadata` ni `generateMetadata`.
Confirmé empiriquement sur un trip réellement partagé : `/s/kETacKMK` rend `<title>Planificateur de voyage
vélo</title>` (titre global) + meta description générique, **aucun `og:`/`twitter:`** -> aperçu social/messagerie
cassé. **C'est le cœur de l'Ordre 4.**
**Reco :** `generateMetadata` par token de partage (titre du trip, distance/dénivelé, image de carte).

#### SEO-002 — `robots.txt` / `sitemap.xml` absents — P2

```text
$ curl -sk -o /dev/null -w "%{http_code}" https://localhost/robots.txt
404
$ curl -sk -o /dev/null -w "%{http_code}" https://localhost/sitemap.xml
404
```

Le Caddyfile route `/robots.txt` et `/sitemap*` vers la PWA, mais aucun `robots.ts`/`sitemap.ts` n'existe.
**Reco :** ajouter `pwa/src/app/robots.ts` + `sitemap.ts` (App Router).

#### SEO-003 — Aucune balise Open Graph / Twitter sur les pages publiques — P2

```text
$ curl -sk -H 'Accept: text/html' https://localhost/ | grep -oiE "<meta[^>]*(og:|twitter:)[^>]*>"
# (aucun résultat) ; seul <meta name="description"> est présent
```

**Reco :** enrichir `pwa/src/app/layout.tsx` (`openGraph`, `twitter` dans `metadata`).

---

### i18n (Ordre 5)

#### I18N-001 — Pas de `onError` sur `NextIntlClientProvider` — P3

`pwa/src/app/layout.tsx` instancie `NextIntlClientProvider` sans `onError` : une clé manquante au runtime
s'affiche en littéral sans signal.
**Reco :** `onError` qui loggue (et throw en dev).

#### Conformités i18n vérifiées

+ **`make i18n-check` : PASS** — `i18n-check OK: 848 keys in sync across fr, en`.
+ Formatage dates/nombres via `toLocaleDateString(undefined, …)` (délégué au locale) — `infographic.ts:632`,
  `StageDetailPanel.tsx:56`, `timeline.tsx:55`, `text-export.ts:24`.
+ ~119 composants via `useTranslations()`.

---

### Qualité (Ordre 6)

#### QUAL-001 / QUAL-002 — Pas de seuil de couverture (PHPUnit & Vitest) — P2

`api/phpunit.dist.xml` : bloc `<coverage>` sans `<limit minimum="…">`. `pwa/vitest.config.ts` : aucune clé
`coverage`/thresholds. La couverture n'est jamais un gate CI.
**Reco :** seuil >= 80 % (cf. DoD) appliqué en CI, des deux côtés.

#### COV-API — Couverture PHPUnit API 71,8 % statements (< 80 %) — P2

Mesurée sur la stack dev (xdebug, clés régénérées `make jwt-keypair-test` ; le mismatch passphrase/clés qui
causait 151 `JWTEncodeFailureException` est corrigé via `JWT_PASSPHRASE` forcé dans `phpunit.dist.xml`).

```text
$ make coverage-ci   # api
OK, but some tests were skipped! Tests: 1310, Assertions: 3819, Skipped: 1
# clover api/coverage/api/clover.xml : statements 6769/9432 = 71,8 % ; methods 561/959 = 58,5 %
```

**Reco :** remonter la couverture API vers le seuil 80 % du DoD (couplé à QUAL-001).

#### CI-UNIT — Tests unitaires front non exécutés en CI — P2

`ci.yml` ne lance que `npm run test:ts` (TypeScript), **jamais `vitest`** : les tests unitaires front existent mais
ne sont vérifiés par aucun pipeline. La mesure locale est en outre bloquée (conteneur `pwa` plafonné à 512 Mo
-> OOM-kill, provider `@vitest/coverage-v8` absent, volume `node_modules` désynchronisé -> `jsdom`
`MODULE_NOT_FOUND`). Couverture front **non chiffrable en l'état**.
**Reco :** ajouter un job `vitest run` en CI (+ provider de couverture pour QUAL-002).

#### COV-PROV — Couverture provisioner non mesurable — P3

Le conteneur `provisioner` n'embarque pas de driver xdebug -> `make coverage-ci` échoue sur cette jambe avec
*« No code coverage driver available »*.
**Reco :** aligner l'image provisioner sur l'API pour la couverture, ou exclure explicitement cette jambe.

#### QUAL-004 — `make lighthouse` non exécutable — Corrigé (PR #612)

Cause : `lhci autorun` tournait dans `mcr.microsoft.com/playwright` où `chrome-launcher` ne trouvait pas Chrome,
**et aucun job CI ne lançait lhci**. Fix livré : `CHROME_PATH` pointant le chromium Playwright + workflow manuel
`lighthouse.yml`. La cible s'exécute désormais (5 URLs × 3 runs) et a permis de collecter LH-PERF-HOME /
LH-A11Y-HOME (cf. Perf/A11y).

#### QUAL-003 — Dette de suppressions statiques — P3

+ 7 × `@phpstan-ignore` : `api/src/Story/AppStory.php` (×5, types Foundry), `AnalyzeTerrainHandler.php`
  (×2, nullsafe).
+ 5 × suppressions front : 3 `react-hooks/exhaustive-deps`, 1 `@ts-expect-error` (test),
  1 `@next/next/no-img-element` (images Wikimedia).

Volume faible et justifié, mais à tracer comme dette.

#### Conformités qualité vérifiées

+ **Suite QA/CI complète verte** (source de vérité = CI de cette PR, 21/21 checks) : PHP-CS-Fixer, Rector,
  PHPStan **Level 9** + `banned_code`, ESLint strict (`no-explicit-any`, `no-console`), Prettier, TS,
  Markdownlint, OpenAPI lint, Hadolint, i18n, PHPUnit (api + provisioner), Playwright, BDD recette, APK.
+ PHPUnit : `failOnWarning`/`failOnRisky`/`failOnDeprecation` = true.
+ `npm audit` : **8 modérées, 0 haute/critique** -> gate CI `--audit-level=high` vert.

---

### Privacy / anonymisation (Ordre 7)

Aucune non-conformité. Conformités vérifiées :

+ **Page `/privacy`** complète : responsable, base légale, finalités, données, rétention, droits, sous-traitants,
  analytics, contact. Plausible auto-hébergé, cookieless, sans PII vers tiers.
+ **Gating Plausible par env** : `pwa/src/components/plausible-script.tsx:39-41` retourne `null` si
  `NEXT_PUBLIC_PLAUSIBLE_DOMAIN`/`SRC` indéfinis -> en iso-prod (vars vides), script absent, 0 requête analytics.
+ **Pas de consentement** (ADR-034 : Plausible cookieless ; #385 bannière cookies abandonnée).
+ **0 cookie** sur pages publiques :

```text
$ for p in / /login /privacy; do curl -skD - -o /dev/null -H 'Accept: text/html' https://localhost$p | grep -i set-cookie || echo "$p: none"; done
/: none   /login: none   /privacy: none
```

+ **Purge user (RGPD)** : `DELETE /users/me` -> `AccountDeleteProcessor` : transaction, cascade FK
  (trips/stages/chat/shares), révocation des refresh tokens, anonymisation de l'email — irréversible, immédiat,
  pas de cron (décision projet). Vérification DB/Redis après suppression = à exécuter (cf. ci-dessous).

---

## Reste à exécuter (empirique — recette ultérieure / Sprint 35.3+)

Items non bloquants pour la publication de ce rapport, à exercer sur stack seedée (certains bridés par la machine
d'audit : conteneur pwa cappé, OOM `tsc`/`vitest`) :

| Sujet | Pourquoi pas encore fait | Où |
|---|---|---|
| Matrice 403 inter-utilisateurs | second compte seedé requis | iso-prod recette |
| XSS payload sur champ éditable d'un trip réel | nécessite trip seedé + inspection DOM rendu | iso-prod recette |
| Purge RGPD vérifiée en DB/Redis/logs | requêtes SQL + `redis-cli KEYS` post-`DELETE /users/me` | iso-prod recette |
| F4 rate-limit : root-cause du storage sous `read_only` | investigation infra | iso-prod recette |
| N+1 Doctrine profilé au runtime | profiler/logger sur endpoints seedés | iso-prod recette |
| Analyse de bundle / code-splitting | `next build` (OOM local) | CI / machine dédiée |
| axe runtime + nav clavier pages authentifiées | stack dev seedée + Playwright | dev seedé |
| Couverture front Vitest chiffrée | provider absent + OOM + node_modules désync (cf. CI-UNIT) | CI à outiller |
| Matrice multi-device (viewports × navigateurs × thèmes × langues) | Playwright lourd (OOM local) | CI / machine dédiée |
| Chaos (kill/pause worker, coupure réseau -> retry/backoff, reconnexion SSE) | orchestration dédiée | machine dédiée |
