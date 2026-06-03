# Rapport d'audit non-fonctionnel & qualité — Sprint 35.2

> **Statut : rapport d'audit, AUCUN fix.** Les findings ci-dessous sont destinés à être
> ouverts en issues GitHub (milestone `Sprint 35.4`, labels `security`/`perf`/`a11y`/`seo`/`i18n`/`quality`/`privacy`
> et sévérité `P0`-`P3`). La création des issues est différée à validation utilisateur.

**Date :** 2026-06-02

**Périmètre :** features livrées sur `main` (sprints 1-33, design S25-27, IA S28-32, S34/34.5),
hors S18 #313/#314 (abandonnés) et osm-cron nightly (#575).

**Dépend de :** Sprint 35 (outillage) — livré (#601-605).

## Méthodologie & environnement

L'audit a été conduit sur deux boots locaux successifs de la stack (port 80/443 partagé, donc séquentiels) :

| Phase | Stack | Usage |
|---|---|---|
| A | **iso-prod** (`compose.yaml`, `APP_ENV=prod`, image `:ci`) bootée avec une paire de clés JWT locales générées, `MAILER_DSN=null://` | Headers Caddy, suppression stack-trace, matrice auth 401/403, isolation Mercure, cookies, OG/SEO, HTML rendu (a11y/SEO) |
| B | **dev** (`compose.yaml`+`compose.dev.yaml`, `APP_ENV=dev`, Mailcatcher + fixtures) | Outillage qualité : `i18n-check`, `npm audit`, tentative de couverture |

**Sévérités :** P0 critique exploitable · P1 élevé · P2 moyen (durcissement impactant) · P3 faible (hygiène).

**Limites locales (verdicts marqués « différé ») :**

+ La stack iso-prod locale n'a **ni Mailcatcher ni fixtures** : les flux **authentifiés** (matrice 403 inter-utilisateurs, XSS sur champs éditables d'un trip réel, purge DB après suppression de compte, axe sur pages authentifiées) n'ont pas pu être exercés sous `APP_ENV=prod`. Ils sont vérifiés par **revue de code** et marqués « À confirmer iso-prod seedé ».
+ Les **clés JWT d'environnement `test`** ne sont pas provisionnées localement → 151 erreurs `JWTEncodeFailureException` au lancement de la couverture. La **couverture PHPUnit chiffrée est donc déléguée au CI** (autorité). Le finding de configuration (absence de seuil `fail-under`) reste valide et confirmé par lecture de config.
+ **Lighthouse n'a pas pu s'exécuter** (voir QUAL-004) : l'image `mcr.microsoft.com/playwright` utilisée par `lhci` ne contient pas l'installation Chrome attendue. Aucun score Performance/A11y/SEO/Best-Practices n'a donc été collecté.

---

## Recette v2 — exécution sur iso-prod fidèle (mise à jour)

> 2e passe sur une stack iso-prod **rebâtie depuis le code courant** (l'image `:ci` initiale
> était périmée et masquait des régressions), avec **Mailcatcher + Ollama** (outillage PR #612)
> et un **golden path authentifié réel** (compte via `app:create-user`, magic-link, liens Komoot
> dont « Entre Sensée et Escaut » en zone Lille). Cette section corrige et complète la v1.

### Nouveaux findings (prouvés empiriquement)

| ID | Titre | Sévérité | Preuve | Suite |
|---|---|---|---|---|
| F1 | `LOCK_DSN` absent de `compose.yaml` → fallback `redis://localhost` injoignable → **création de trip 500 en prod** | **P0** | `LockAcquiringException: Redis connection refused` sur `POST /trips` (OK après override) | fix 35.4 : ajouter `LOCK_DSN` à `compose.yaml` |
| F2 | `DataTourismeClient` `TypeError` si clé absente/vide (défaut prod) → **`ScanAccommodations`/`ScanEvents` crashent** | **P1** | log worker `Argument #5 ($apiKey) must be of type string, null given` | **corrigé : PR #613** |
| F3 | Intégration **Ollama prod absente de `compose.yaml`** + divergence ADR-028 (health hors `$required` → mode dégradé silencieux contraire à l'ADR « hard dependency ») | P1 | `grep OLLAMA compose.yaml`=∅ ; `HealthController::$required` sans ollama | **outillé : `compose.ollama.yaml` (PR #612)** ; aligner ADR-028 |
| F4 | **Rate-limit `/auth/request-link` inopérant** : 6× même email → `202`, aucun `429` | P1 | boucle curl | investiguer le storage du limiter (prod `read_only`) |
| F5 | APIs externes flaky : **Overpass `429`/timeout** → POI/alertes dégradés | P2 | logs worker `429 Too Many Requests` | tiers ; cache + retry |

### Corrections aux findings v1

+ **SEO-001 (P1) — confirmé empiriquement** : la page de partage `/s/kETacKMK` rend `<title>Planificateur de voyage vélo</title>` (titre global) + meta description générique, **aucun `og:`/`twitter:`** → aperçu social cassé prouvé sur un trip réellement partagé.
+ **QUAL-004 (Lighthouse) — corrigé (PR #612)** : cause = `chrome-launcher` ne trouve pas Chrome dans l'image Playwright **et** aucun job CI ne lançait lhci. Fix : `CHROME_PATH` + workflow manuel `lighthouse.yml`. Scores réels à collecter sur la stack recette.
+ **Couverture — cause corrigée** : pas « clés JWT `test` non provisionnées » mais un **mismatch passphrase/clés** (le CI régénère + force `JWT_PASSPHRASE=test`). Fix : `JWT_PASSPHRASE` forcé dans `phpunit.dist.xml` + cible `make jwt-keypair-test` (PR #612).
+ **SEC-003 — à nuancer** : `X-Frame-Options: deny` et `X-Content-Type-Options: nosniff` sont **présents sur les réponses API Platform** mais **absents des pages HTML PWA** (la surface clickjacking). Le finding ne vaut que pour la PWA.
+ **Stack-trace prod — conformité confirmée empiriquement** : de vrais `500` (lock) et `415` (mauvais content-type) renvoient un `problem+json` propre, **sans trace ni nom de classe** exposés.
+ **Faux positif écarté** : un crash `HealthController` (`RateLimiterFactory` injecté pour `$mercureClient`) au 1er boot venait d'un **cache prod périmé dans le volume `php_var`** réutilisé entre boots — **pas** un bug de `main` (résolu en recréant le volume). Ne PAS reporter.

### Conformités confirmées

+ **Golden path** : le trip « Entre Sensée et Escaut » (Komoot, zone Lille) est **calculé** (stages, distance 70,2 km, dénivelé) — fetch source + pacing OK.
+ **Auth réelle** : magic-link via Mailcatcher → JWT (`/auth/verify` en `application/ld+json`) → création de trip authentifiée → partage.

### Révision du verdict « aucun P0 »

Caduc : **F1 (`LOCK_DSN`) est un P0** (création de trip cassée en prod par défaut).

### Reste à exécuter (recette stable ultérieure, outillage prêt PR #612)

Lighthouse (scores), axe runtime authed, **couverture chiffrée** (PHPUnit/Vitest via stack dev + `make jwt-keypair-test`), N+1 profilé, **isolation Mercure empirique**, **purge RGPD en DB**, XSS payload, analyse de bundle, multi-device, chaos (worker/réseau).

---

## Synthèse des findings

| ID | Titre | Sévérité | Label | Statut |
|---|---|---|---|---|
| SEC-001 | Pas de Content-Security-Policy sur les réponses HTML | P2 | security | Confirmé |
| SEC-002 | Pas de Strict-Transport-Security (HSTS) | P2 | security | Confirmé |
| SEC-003 | Pas de X-Frame-Options ni X-Content-Type-Options sur les pages PWA | P2 | security | Confirmé |
| SEC-004 | Pas de Referrer-Policy | P3 | security | Confirmé |
| SEC-005 | En-tête `x-powered-by: Next.js` exposé en prod | P3 | security | Confirmé |
| SEO-001 | Pages de partage `/s/[code]` sans `generateMetadata` (aperçu social cassé) | P1 | seo | Confirmé |
| SEO-002 | `robots.txt` et `sitemap.xml` absents (404) | P2 | seo | Confirmé |
| SEO-003 | Aucune balise Open Graph / Twitter Card sur les pages publiques | P2 | seo | Confirmé |
| A11Y-001 | Landing `/` sans landmark `<main>` dans le HTML rendu | P2 | a11y | Confirmé |
| A11Y-002 | Landing `/` sans `<h1>` dans le HTML rendu (SSR) | P2 | a11y | Confirmé |
| QUAL-001 | Aucun seuil de couverture PHPUnit (`fail-under`) | P2 | quality | Confirmé |
| QUAL-002 | Aucun seuil de couverture Vitest (frontend) | P2 | quality | Confirmé |
| QUAL-004 | `make lighthouse` non exécutable (Chrome introuvable dans l'image lhci) | P2 | quality | Confirmé |
| PERF-001 | `maplibre-gl` importé statiquement dans `trip-planner.tsx` | P3 | perf | Confirmé |
| I18N-001 | Pas de handler `onError` sur `NextIntlClientProvider` | P3 | i18n | Confirmé |
| QUAL-003 | Dette de suppressions statiques (7 `@phpstan-ignore`, 5 front) | P3 | quality | Confirmé |

**16 findings v1 confirmés** : 1×P1, 8×P2, 7×P3. **À compléter par la Recette v2 ci-dessus** : **1×P0 (F1 `LOCK_DSN`)**, 3×P1 (F2–F4), 1×P2 (F5) + corrections. Le « aucun P0 » initial est **caduc**.

---

## Findings détaillés

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
**Reco :** CSP même restrictive au niveau edge (Caddy ou reverse-proxy Coolify).

#### SEC-002 — Pas de HSTS — P2

Aucun `Strict-Transport-Security` sur les réponses (HTTP et HTTPS).
**Reco :** `Strict-Transport-Security: max-age=31536000; includeSubDomains` à l'edge en prod.

#### SEC-003 — Pas de X-Frame-Options / X-Content-Type-Options sur les pages PWA — P2

Les pages HTML de la PWA (surface de clickjacking / MIME sniffing) ne portent ni `X-Frame-Options` ni `X-Content-Type-Options` (dump d'en-têtes SEC-001). API Platform pose ces en-têtes sur certaines réponses d'erreur, mais **pas** sur les documents HTML applicatifs.
**Reco :** `X-Frame-Options: DENY` + `X-Content-Type-Options: nosniff` à l'edge.

#### SEC-004 — Pas de Referrer-Policy — P3

Aucun `Referrer-Policy`. Hygiène. **Reco :** `Referrer-Policy: strict-origin-when-cross-origin`.

#### SEC-005 — `x-powered-by: Next.js` exposé — P3

```text
$ curl -skD - -o /dev/null -H 'Accept: text/html' https://localhost/ | grep -i x-powered-by
x-powered-by: Next.js
```

Fingerprinting du framework (facilite le ciblage de CVE). **Reco :** `poweredByHeader: false` dans `next.config`.

#### Conformités sécurité vérifiées

+ **Rate limiting auth** : `magic_link_email` (3/900s), `magic_link_ip` (10/900s), `access_request_ip` (3/3600s) — `rate_limiter.php` + appliqués dans `AuthRequestLinkProcessor` / `AccessRequestCreateProcessor`.
+ **XSS** : aucun `dangerouslySetInnerHTML` dans `pwa/src` (React échappe par défaut).
+ **Auth 401** : `/trips` et endpoints protégés renvoient `401 JWT Token not found` non authentifié ; IDOR couvert par `TripVoter` (`is_granted('TRIP_VIEW'/'TRIP_EDIT', object)`).
+ **Stack-trace prod** : 404/erreurs renvoient un `problem+json` propre, `/_profiler` → 404, `web_profiler` désactivé en prod.
+ **`composer audit`** : `No security vulnerability advisories found` (conteneur prod).
+ **Isolation Mercure** : malgré `anonymous` dans le Caddyfile, les updates de trip sont publiés avec `private: true` (`TripUpdatePublisher`) et l'abonnement requiert un JWT subscriber scopé au topic (`MercureTokenIssuer`, cookie `Secure`+`HttpOnly`+`SameSite=strict`). Un abonné anonyme ouvre le hub (HTTP 200) mais **ne reçoit pas** les updates privés. Confirmation empirique différée iso-prod seedé.

---

### Performance (Ordre 2)

#### PERF-001 — `maplibre-gl` importé statiquement — P3

`pwa/src/components/trip-planner.tsx:30` importe `MapPanel` directement (chaîne `MapPanel → MapView → maplibre-gl ~5.24`), sans `next/dynamic`. Atténué : `TripPlanner` n'est monté que sur les routes éditeur (`/trips/new`, `/trips/[id]`, `/s/[code]`), elles-mêmes en `dynamic()`.
**Reco :** lazy-load `MapPanel` pour alléger le 1er chunk de la route éditeur.

#### Conformités performance vérifiées

+ **N+1 Doctrine** : `TripCollectionProvider` (`leftJoin('t.stages')+addSelect` + `Paginator(fetchJoinCollection: true)`) ; `DoctrineTripRequestRepository::storeStages` bulk DELETE + flush unique ; updates JSONB par étape atomiques sans charger l'agrégat.
+ **Caches** : OSM 24h, météo 3h (ADR-022), points bruts/décimés 30 min.
+ **Async** : `FetchWeatherHandler` (cache→batch uncached→calcul), `OsmScanner::queryBatch` (Overpass concurrent), `ScanAccommodationsHandler` (multiplexage HTTP 2 vagues, SPARQL Wikidata batch).
+ **Profil altimétrique** : SVG custom O(n) + recherche binaire, sans lib de charting lourde.

#### Différé performance

+ Scores Lighthouse : **non collectés** (voir QUAL-004).
+ Coût de sérialisation DTO `TripDetailProvider` : profiling runtime seedé requis.
+ Latence chaîne de calcul async réelle : load-test iso-prod requis.

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

La landing rend son `<h1>` (`landing/hero.tsx`) et sa structure **côté client** après un contrôle d'auth ; le HTML initial n'expose donc ni `<h1>` ni landmark `<main>` — pénalise lecteurs d'écran et SEO. La page `/login` est conforme (preuve ci-dessus).
**Reco :** garantir un `<main>` + `<h1>` dans le rendu serveur de la landing.

#### Conformités a11y vérifiées

+ `lang="fr"` sur `<html>` ; `/faq`, `/legal`, `/privacy` ont hiérarchie h1→h2 correcte + landmarks.
+ Formulaires (login, early-access) : `<label htmlFor>`, `aria-describedby`, `aria-invalid`.
+ Dialogs via Radix UI (a11y intégrée) ; images avec `alt` ; boutons carousel avec `aria-label`.
+ Helper `expectNoCriticalA11yViolations` (@axe-core/playwright) câblé dans les fixtures (#601).

#### Différé a11y

+ Navigation clavier manuelle (focus trap modales, drag&drop timeline) et axe runtime sur pages **authentifiées** : stack dev seedée + Playwright requis.

---

### SEO (Ordre 4)

#### SEO-001 — Pages de partage `/s/[code]` sans métadonnées dynamiques — P1

`pwa/src/app/s/[code]/page.tsx` et `shared-trip-page.tsx` n'exportent ni `metadata` ni `generateMetadata`. Les liens de partage (raison d'être de la feature) n'ont donc ni `og:title`, ni `og:description`, ni `og:image` : l'aperçu social/messagerie est cassé. **C'est le cœur de l'Ordre 4.**
**Reco :** `generateMetadata` par token de partage (titre du trip, distance/dénivelé, image de carte).

#### SEO-002 — `robots.txt` / `sitemap.xml` absents — P2

```text
curl -sk -o /dev/null -w "%{http_code}" https://localhost/robots.txt
404
curl -sk -o /dev/null -w "%{http_code}" https://localhost/sitemap.xml
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

`pwa/src/app/layout.tsx` instancie `NextIntlClientProvider` sans `onError` : une clé manquante au runtime s'affiche en littéral sans signal.
**Reco :** `onError` qui loggue (et throw en dev).

#### Conformités i18n vérifiées

+ **`make i18n-check` : PASS** — `i18n-check OK: 848 keys in sync across fr, en`.
+ Formatage dates/nombres via `toLocaleDateString(undefined, …)` (délégué au locale) — `infographic.ts:632`, `StageDetailPanel.tsx:56`, `timeline.tsx:55`, `text-export.ts:24`.
+ ~119 composants via `useTranslations()`.

---

### Qualité (Ordre 6)

#### QUAL-001 — Pas de seuil de couverture PHPUnit — P2

`api/phpunit.dist.xml` : bloc `<coverage>` sans `<limit minimum="…">`. La couverture n'est jamais un gate CI.
**Reco :** ajouter un seuil (>= 80%, cf. DoD) et l'appliquer en CI.

#### QUAL-002 — Pas de seuil de couverture Vitest — P2

`pwa/vitest.config.ts` n'a aucune clé `coverage`/thresholds.
**Reco :** configurer un seuil front (>= 80%).

#### QUAL-004 — `make lighthouse` non exécutable — P2

```text
$ make lighthouse
…
❌  Chrome installation not found
Healthcheck failed!
make: *** [Makefile:129 : lighthouse] Erreur 1
```

La cible lance `lhci autorun` dans `mcr.microsoft.com/playwright`, mais `lhci` ne trouve pas Chrome dans cette image. **Aucun score n'a pu être collecté** (perf/a11y/seo/best-practices).
**Reco :** installer/pointer Chrome dans le job (`npx playwright install chrome` ou `CHROME_PATH`), sinon le gate Lighthouse est inopérant. Bloque la mesure des seuils Performance ≥ 80 / A11y ≥ 90 / SEO ≥ 90 du DoD recette.

#### QUAL-003 — Dette de suppressions statiques — P3

+ 7 × `@phpstan-ignore` : `api/src/Story/AppStory.php` (×5, types Foundry), `AnalyzeTerrainHandler.php` (×2, nullsafe).
+ 5 × suppressions front : 3 `react-hooks/exhaustive-deps`, 1 `@ts-expect-error` (test), 1 `@next/next/no-img-element` (images Wikimedia).

Volume faible et justifié, mais à tracer comme dette.

#### Conformités qualité vérifiées

+ PHPUnit : `failOnWarning`/`failOnRisky`/`failOnDeprecation` = true.
+ PHPStan **Level 9** + `banned_code` (echo/dd/eval/exit/shell_exec…), Rector PHP 8.5, ESLint strict (`no-explicit-any`, `no-console`).
+ `npm audit` : **8 modérées, 0 haute/critique** (relevé pendant `npm ci` du job lighthouse) → gate CI `--audit-level=high` vert.

#### Différé qualité

+ **Couverture chiffrée** : non mesurable localement (clés JWT `test` non provisionnées → 151 `JWTEncodeFailureException`). **Autorité = CI.**
+ `make qa` complet : non rejoué ici (pré-commit + CI verts sur `main`).

---

### Privacy / anonymisation (Ordre 7)

Aucune non-conformité. Conformités vérifiées :

+ **Page `/privacy`** complète : sections responsable, base légale, finalités, données, rétention, droits, sous-traitants, **analytics**, contact. Mentionne Plausible auto-hébergé, cookieless, sans PII vers tiers.
+ **Gating Plausible par env** : `pwa/src/components/plausible-script.tsx:39-41` retourne `null` si `NEXT_PUBLIC_PLAUSIBLE_DOMAIN`/`SRC` indéfinis → en iso-prod (vars vides), script absent, 0 requête analytics.
+ **Pas de consentement** (ADR-034 : Plausible cookieless ; #385 bannière cookies abandonnée).
+ **0 cookie** sur pages publiques :

```text
$ for p in / /login /privacy; do curl -skD - -o /dev/null -H 'Accept: text/html' https://localhost$p | grep -i set-cookie || echo "$p: none"; done
/: none   /login: none   /privacy: none
```

+ **Purge user (RGPD)** : `DELETE /users/me` → `AccountDeleteProcessor` : transaction, cascade FK (trips/stages/chat/shares), révocation des refresh tokens, anonymisation de l'email — irréversible, immédiat, pas de cron (décision projet). Vérification DB après suppression différée iso-prod seedé.

---

## Récapitulatif des différés (à exécuter en iso-prod seedé / CI)

| Sujet | Raison | Où |
|---|---|---|
| Scores Lighthouse | `make lighthouse` cassé (QUAL-004) | corriger l'outillage puis iso-prod |
| Couverture PHPUnit/Vitest chiffrée | clés JWT `test` absentes en local | CI |
| Matrice 403 inter-utilisateurs, XSS sur trip réel | pas d'auth (mailer null) en prod local | iso-prod seedé |
| Purge DB post-suppression compte | pas de user seedé | iso-prod seedé |
| axe runtime + nav clavier pages authentifiées | stack dev + Playwright | dev seedé (Sprint 35.3) |
| Confirmation empirique isolation Mercure | publish + subscribe anonyme | iso-prod seedé |

---

## Recette v2 — résultats d'exécution (stack iso-prod rebâtie + golden path réel)

> Exécution sur `make start-recette` (iso-prod `APP_ENV=prod` + Mailcatcher + Ollama), images rebâties
> depuis `main` (fix DataTourisme #613 inclus). Golden path réel : compte via `app:create-user` →
> magic-link Mailcatcher → JWT → trips Komoot (dont « Entre Sensée et Escaut », zone Lille).

### DataTourisme — modes dégradé & live

+ **Mode dégradé (clé absente ou `DATATOURISME_ENABLED=false`) : OK.** Avec `ENABLED=true` et clé **vide**
  (le cas du bug F2), `ScanAccommodations` est « handled successfully », **aucun `TypeError`** : le fix #613
  (`?string $apiKey` + `isEnabled()` null-safe) dégrade gracieusement (source skippée par les registries).
  Avant le fix, ce cas crashait en boucle de retry.
+ **Mode live (clé valide + `ENABLED=true`) : CASSÉ — finding `DT-LIVE` (P2, feature OFF par défaut).** La clé
  est valide (`GET https://api.datatourisme.fr/v1/catalog` → 200), mais deux défauts : **(1)** le scoped client
  `datatourisme.client` (`api/config/packages/framework.php:81`) a un `scope` (regex SSRF) **sans `base_uri`**
  (contrairement à komoot/strava/overpass) → `request('/api/v1/places')` (path relatif) échoue *« Invalid URL:
  scheme is missing »*, tous les appels renvoient un résultat vide ; **(2)** endpoint obsolète `/api/v1/places`
  → **404** alors que l'API v1 actuelle expose `/v1/catalog` et `/v1/placeOfInterest` (→ 200). **Fix 35.4** :
  ajouter `base_uri: https://api.datatourisme.fr` au scoped client + migrer endpoints/format vers l'API v1.

### Lighthouse (pages publiques) — QUAL-004 corrigé, scores collectés

`make lighthouse` (réparé) s'exécute désormais (Chrome trouvé via `CHROME_PATH`, 5 URLs × 3 runs) :

+ **Landing `/` sous les seuils** : Performance **0.65** (< 0.80) et Accessibility **0.84** (< 0.90)
  → findings **`LH-PERF-HOME`** (P2) et **`LH-A11Y-HOME`** (P2, cohérent avec A11Y-001/002 : `<h1>`/`<main>`
  absents du HTML SSR). SEO / Best-Practices OK sur `/`.
+ **`/login`, `/faq`, `/legal`, `/privacy` : conformes** (Perf ≥ 0.80, A11y ≥ 0.90, SEO ≥ 0.90, BP ≥ 0.90).

### Isolation Mercure — confirmée empiriquement

Un abonné **anonyme** au topic privé `/trips/{id}` ne reçoit **aucune donnée** (seulement le heartbeat SSE `:`).
Les updates `private: true` ne sont délivrés qu'aux abonnés porteurs d'un JWT subscriber scopé (`subscriber_jwt`,
Caddyfile) ; le `anonymous` du hub autorise la connexion mais pas la lecture des topics privés. Le finding v1
« isolation Mercure à confirmer » est donc **levé**.
