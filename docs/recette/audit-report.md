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
| IDOR-DETAIL | `GET /trips/{id}/detail` sans autorisation objet -> **lecture du trip d'autrui** (IDOR authentifié) | **P1** | security | Confirmé (empirique) |
| SEO-001 | Pages de partage `/s/[code]` sans `generateMetadata` (aperçu social cassé) | P1 | seo | Confirmé |
| F3 | Ollama prod : wiring `OLLAMA_*` manquant dans `compose.yaml` + arbitrage hard-dep/dégradé (ADR-028) | P1 | quality | **En cours : #616 (wiring/ADR/réseau) + #304 (gating dégradé)** |
| F2 | `DataTourismeClient` `TypeError` si clé absente/vide -> `ScanAccommodations`/`ScanEvents` crashent | ~~P1~~ | quality | **Corrigé (PR #613)** |
| F4 | Rate-limit `/auth/request-link` « sans 429 » : 6× -> `202` | ~~P1~~ | security | **Faux finding** (202 par design anti-énumération ; limiter actif, e-mails supprimés) |
| SEC-001 | Pas de Content-Security-Policy sur les réponses HTML | P2 | security | Confirmé |
| SEC-002 | Pas de Strict-Transport-Security (HSTS) | P2 | security | Confirmé |
| SEC-003 | Pas de X-Frame-Options ni X-Content-Type-Options sur les pages PWA | P2 | security | Confirmé |
| SEO-002 | `robots.txt` et `sitemap.xml` absents (404) | P2 | seo | Confirmé |
| SEO-003 | Aucune balise Open Graph / Twitter Card sur les pages publiques | P2 | seo | Confirmé |
| A11Y-001 | Landing `/` sans landmark `<main>` dans le HTML rendu | P2 | a11y | Confirmé |
| A11Y-002 | Landing `/` sans `<h1>` dans le HTML rendu (SSR) | P2 | a11y | Confirmé |
| LH-PERF-HOME | Landing `/` score Lighthouse Performance **0.65** (< 0.80) | P2 | perf | Confirmé |
| LH-A11Y-HOME | Landing `/` score Lighthouse Accessibility **0.84** (< 0.90) | P2 | a11y | Confirmé |
| LH-PERF-AUTH | Pages authentifiées sous seuil perf (`/trips` 0.73, `/trips/new` 0.52) | P2 | perf | Confirmé (empirique) |
| CHAOS-RESTART | Worker `SIGKILL`/OOM lent/peu fiable à redémarrer (resté down > 2 min en test ; cause à confirmer) | P3 | quality | À confirmer |
| DT-LIVE | DataTourisme mode live cassé : scoped client sans `base_uri` + endpoint obsolète | P2 | quality | Confirmé |
| F5 | APIs externes flaky : **Overpass `429`/timeout** -> POI/alertes dégradés | P2 | perf | Confirmé |
| QUAL-001 | Aucun seuil de couverture PHPUnit (`fail-under`) | P2 | quality | Confirmé |
| QUAL-002 | Aucun seuil de couverture Vitest (frontend) | P2 | quality | Confirmé |
| COV-API | Couverture PHPUnit API **71,8 %** statements (< 80 % DoD) | P2 | quality | Confirmé |
| COV-FRONT | Couverture front Vitest **16,85 %** statements (< 80 % DoD) | P2 | quality | Confirmé |
| CI-UNIT | Tests unitaires front non gatés par la CI (suite cassée + 6 tests rot) | ~~P2~~ | quality | **Corrigé (PR #615)** |
| QUAL-004 | `make lighthouse` non exécutable (Chrome introuvable dans l'image lhci) | ~~P2~~ | quality | **Corrigé (PR #612)** |
| SEC-004 | Pas de Referrer-Policy | P3 | security | Confirmé |
| SEC-005 | En-tête `x-powered-by: Next.js` exposé en prod | P3 | security | Confirmé |
| PERF-001 | `maplibre-gl` importé statiquement dans `trip-planner.tsx` | P3 | perf | Confirmé |
| I18N-001 | Pas de handler `onError` sur `NextIntlClientProvider` | P3 | i18n | Confirmé |
| QUAL-003 | Dette de suppressions statiques (7 `@phpstan-ignore`, 5 front) | P3 | quality | Confirmé |
| COV-PROV | Couverture provisioner (xdebug absent) -> mesurée 84,9 % | ~~P3~~ | quality | **Corrigé (PR #615)** |
| RGPD-MAGIC | `magic_link` non purgés à la suppression de compte (7 rows, dont 2 valides) | P3 | privacy | Confirmé (empirique) |

**Total : 1×P0, 3×P1 actifs (+1 corrigé : F2 ; F4 requalifié faux finding), 16×P2 actifs (+2 corrigés :
QUAL-004, CI-UNIT), 6×P3 actifs (+1 corrigé : COV-PROV) + 1×P3 à confirmer (CHAOS-RESTART).** Le « aucun P0 »
d'une première lecture est **caduc** : F1 casse la création de trip en prod par défaut. **IDOR-DETAIL** (P1) est le
finding sécurité majeur de la passe empirique R4.

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

#### F3 — Intégration Ollama prod : wiring manquant + arbitrage hard-dep/dégradé — P1

Deux constats distincts :

1. **Wiring prod absent** : `grep OLLAMA compose.yaml` = ∅ -> les workers prod n'avaient **aucune** variable
   `OLLAMA_*`, donc `OLLAMA_ENABLED` à `false` par défaut -> **IA OFF en prod**. (La présence d'Ollama lui-même
   dans une stack séparée — `compose.ollama.yaml`, #612/#566 — est **voulue** : ressource lourde dimensionnée à
   part. Ce n'était donc pas « Ollama absent » mais « l'app ne sait pas le joindre ».)
2. **Divergence ADR-028** : `HealthController::$required` n'inclut pas Ollama (health vert même IA down) alors
   qu'ADR-028 affirmait « hard dependency ». **Mais** 2 tests épinglent délibérément ce comportement
   (`readinessReturns200WhenOllamaIsDown`) -> le **code + tests implémentent le mode dégradé**, pas le hard-dep.

**Décision (audit 35.2) : mode dégradé-OK** — l'app doit fonctionner sans IA. Cela **réverse** le hard-dep
Sprint-29 (#375) ; l'objection « dégradé silencieux » est levée en rendant la dégradation **explicite**.

**Correctifs :**

+ **PR #616** : wiring `OLLAMA_*` + `OLLAMA_ENABLED=1` dans `compose.yaml` (dev force 0, recette 1) ; Ollama
  reste **hors** du `$required` readiness ; **ADR-028** réécrit (« Decision Update - Degraded Mode », supersède
  le hard-dep) ; **ADR-027** annoté.
+ **Topologie réseau (ADR-028)** : la stack Ollama **possède** le réseau `bike-trip-planner-llm` ; l'app le
  **rejoint** en `external` (le consommateur rejoint le fournisseur ; seuls php/worker/worker-llm s'y attachent ;
  DB/Redis hors de portée d'Ollama). À câbler au déploiement prod (overlay `external`).
+ **#304 (rouvert)** : feature dédiée — dégradation gracieuse API (skip + logs `critical`) + gating front des
  features IA (génération depuis IA, chat, analyse) + signal capabilities. Couvre les **3 états** (IA activée /
  désactivée / activée-mais-indispo), à valider en recette + 35.3.

#### F4 — Rate-limit « sans 429 » — Faux finding (comportement par design)

6 requêtes successives renvoient toutes `202` (aucun `429`). **Ce n'est pas un bug** : `AuthRequestLinkProcessor`
(l.30 *« Always returns the same neutral message to prevent user enumeration »*, l.62 *« Apply rate limiters --
silently deny if exceeded »*) consomme bien les limiters (`isAccepted()`) puis renvoie **toujours** un `202` neutre,
qu'il envoie l'e-mail ou non. L'absence de `429` est **anti-énumération volontaire**, pas un limiter cassé.

Preuve empirique du limiter actif : sur une rafale de `request-link` au-delà du quota IP (`magic_link_ip` 10/900s),
**aucun e-mail n'arrive dans Mailcatcher** alors que tous renvoient `202` (l'e-mail d'invitation `app:create-user`,
lui, est bien délivré -> le mailer fonctionne). Le limiter supprime donc silencieusement les envois.

> Note : le pool `cache.rate_limiter` n'est pas défini dans `cache.php` (il retombe sur le cache applicatif
> filesystem, écrit dans le volume `php_var` -> writable malgré `read_only`). L'hypothèse v1 « storage read-only »
> est donc fausse. **Aucune action requise** ; éventuellement migrer le pool limiter sur Redis pour le partage
> inter-réplicas (durcissement mineur, hors finding).

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

#### IDOR-DETAIL — `GET /trips/{id}/detail` sans autorisation objet — P1

`GET /trips/{id}/detail` n'applique **aucun contrôle d'ownership** : `TripDetailProvider::provide()`
(`api/src/State/TripDetailProvider.php`) charge le trip par id et le renvoie tel quel — seul un `404` est levé s'il
n'existe pas ; aucun `is_granted`, aucune comparaison au user courant. L'opération `Get` (`ApiResource/TripDetail.php`,
`uriTemplate: /trips/{id}/detail`) ne déclare **pas de `security`**. Seul le firewall global impose l'authentification.

Conséquence : **tout utilisateur authentifié lit le trip d'un autre** via son UUID (titre, `sourceUrl`, dates de
voyage, étapes calculées : géométrie, météo, POI, hébergements). Preuve empirique (deux comptes distincts) :

```text
# A crée le trip 019e8dca-... ; B = autre compte
GET /trips/019e8dca-.../detail        (B, non-proprio) -> HTTP 200 + {"title":"Entre Sensée et Escaut",...}
GET /trips/019e8dca-.../detail        (anonyme)        -> HTTP 401
GET /trips/019e8dca-.../chat-history  (B, non-proprio) -> HTTP 403   <- correctement protégé
PATCH/DELETE /trips/019e8dca-...      (B)              -> HTTP 403   (TRIP_EDIT appliqué)
GET /trips (collection, B)                             -> 0 item     (collection owner-scopée)
```

L'écriture, la collection **et le chat** sont bien protégés ; **seule la lecture `/detail` fuit**. Ce n'est donc
**pas systémique** : le pattern correct existe déjà dans le code — `GET /trips/{id}/chat-history` (#459,
`TripChatMessageResource:56`) déclare `security: "is_granted('TRIP_VIEW', request.attributes.get('id'))"` (B -> 403
vérifié). `/detail` est le **seul** endpoint de lecture trip à l'omettre. Cela **réfute** la conformité v1 « IDOR
couvert par TripVoter » uniquement pour `/detail`.
**Reco (35.4) :** copier l'expression `security` du chat-history sur l'opération `Get` `/detail`
(`ApiResource/TripDetail.php`). Atténuations : pas d'IDOR en écriture, UUIDv7 non trivialement énumérable.

#### Conformités sécurité vérifiées

+ **Rate limiting — actif (empirique)** : `magic_link_email` (3/900s), `magic_link_ip` (10/900s),
  `access_request_ip` (3/3600s) — `rate_limiter.php`, consommés dans `AuthRequestLinkProcessor` /
  `AccessRequestCreateProcessor`. La rafale ne renvoie pas de `429` (anti-énumération, cf. F4 requalifié) mais
  **supprime bien les e-mails** au-delà du quota.
+ **XSS — aucun vecteur trouvé** : **0 sink HTML brut** dans tout `pwa/src` (`grep` `dangerouslySetInnerHTML` /
  `.innerHTML` / `setHTML(` = ∅). Surface la plus à risque (popup carte, données POI/hébergements externes) :
  `MapView.tsx:570` utilise `popup.setDOMContent(container)` où `container = document.createElement('div')` est
  **rempli par un portail React** -> noms échappés (pas de `setHTML`). Chat IA / texte = enfants React (échappés).
  React échappe donc l'intégralité des surfaces user/externe.
+ **Auth 401 + autorisation objet en écriture** : endpoints protégés -> `401` non authentifié (vérifié sur
  `/trips/{id}/detail`) ; la **collection `/trips` est owner-scopée** (un autre user voit 0 trip) et
  **`PATCH`/`DELETE` d'un trip d'autrui -> `403`** (`TripVoter` `TRIP_EDIT`). **Exception : IDOR-DETAIL** en
  lecture (cf. finding ci-dessus) — `TRIP_VIEW` n'est PAS appliqué sur `/detail`.
+ **Stack-trace prod — confirmé empiriquement** : une **vraie `422`** (validation `startDate`), un `415`
  (mauvais content-type), un `404` et un `500` (lock F1) renvoient un `problem+json` RFC7807 propre (titre
  générique « An error occurred »), **sans trace, classe ni fichier** exposés ; `/_profiler` -> 404,
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

#### LH-PERF-AUTH — Lighthouse Performance pages authentifiées sous seuil — P2

`make lighthouse-authed` (cookie `refresh_token` injecté via `extraHeaders`) sur la stack recette :

```text
/trips      (dashboard) : perf 0.73  a11y 1.00  best-practices 1.00  seo 1.00
/trips/new  (éditeur)   : perf 0.52  a11y 1.00  best-practices 0.96  seo 1.00
```

**Performance < 0.80** sur les deux pages authentifiées ; l'éditeur tombe à **0.52** — cohérent avec PERF-001
(`maplibre-gl` chargé statiquement sur la route éditeur). **A11y / Best-Practices / SEO conformes** (a11y = 1.0,
contraste avec la landing publique A11Y-001/002). **Reco :** lazy-load `MapPanel` (PERF-001) + budget de perf sur
les routes éditeur.

#### Conformités performance vérifiées

+ **N+1 Doctrine — aucun, confirmé empiriquement** (cf. section R5) : `TripCollectionProvider`
  (`leftJoin('t.stages')+addSelect` + `Paginator(fetchJoinCollection: true)`) ;
  `DoctrineTripRequestRepository::storeStages` bulk DELETE + flush unique ; updates JSONB par étape atomiques.
  Comptage SQL réel : `/detail` 3 requêtes (plat avec le nb de stages), `/trips` 4 requêtes (constant).
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

`api/phpunit.dist.xml` : bloc `<coverage>` sans `<limit minimum="…">`. `pwa/vitest.config.ts` a une config
`coverage` (provider v8, depuis #615) mais **aucun `thresholds`**. La couverture n'est donc jamais un gate CI
(le job Vitest échoue sur un test rouge, pas sur un % insuffisant).
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

#### CI-UNIT — Tests unitaires front non gatés par la CI — Corrigé (PR #615)

`ci.yml` ne lançait que `npm run test:ts` ; aucun job n'exécutait `vitest`. Le job `Unit Tests (Vitest)` ajouté
(PR #615) a immédiatement révélé que la suite était **non-exécutable** : l'override `overrides.undici ^6.24.0`
(plancher npm audit) forçait undici 6 dans `jsdom@29` qui exige `undici@^7.25.0` -> `Cannot find module
'undici/lib/handler/wrap-handler.js'`, tous les tests échouaient au démarrage. De plus **6 tests étaient pourris**
(test-rot jamais détecté faute de CI : assertions trop strictes, `TooltipProvider` manquant, timers chaînés).
**PR #615** : override imbriqué `jsdom > undici ^7.25.0` (préserve le plancher ^6 ailleurs) + 6 corrections
**test-only** (aucune modif appli) -> suite verte **234/234**, désormais gatée en CI.

#### COV-FRONT — Couverture front Vitest 16,85 % statements — P2

Désormais mesurable (provider `@vitest/coverage-v8` + config, PR #615) : `npx vitest run --coverage` ->
statements **16,85 %** (1072/6359), lines 17,4 %, functions 12,7 %, branches 14,1 %. Très loin du seuil 80 %
du DoD.
**Reco :** remonter la couverture front (couplé à QUAL-002 pour le gate de seuil).

#### COV-PROV — Couverture provisioner — Corrigé (PR #615)

Le conteneur `provisioner` n'embarquait pas de driver xdebug -> `make coverage-ci` échouait sur cette jambe
(*« No code coverage driver available »*). **PR #615** ajoute xdebug au stage dev du provisioner : couverture
mesurée **84,9 %** statements (213/251, 32 tests OK).

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

+ **Suite QA/CI complète verte** (source de vérité = CI) : PHP-CS-Fixer, Rector, PHPStan **Level 9** +
  `banned_code`, ESLint strict (`no-explicit-any`, `no-console`), Prettier, TS, **Vitest (depuis #615)**,
  Markdownlint, OpenAPI lint, Hadolint, i18n, PHPUnit (api + provisioner), Playwright, BDD recette, APK.
+ PHPUnit : `failOnWarning`/`failOnRisky`/`failOnDeprecation` = true.
+ `npm audit` : **8 modérées, 0 haute/critique** -> gate CI `--audit-level=high` vert.

---

### Privacy / anonymisation (Ordre 7)

Une non-conformité mineure (RGPD-MAGIC, P3). Conformités vérifiées :

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

+ **Purge user (RGPD) — confirmée empiriquement** : `DELETE /users/me` -> `204`. Sur un compte réel
  (18 trips / 60 stages / 1 share / 7 refresh_token), cascade FK + révocation des refresh tokens +
  anonymisation irréversible de l'email confirmées, sans PII résiduelle en base ni Redis (pas de cron) :

  ```text
  # avant: trips=18 stages=60 shares=1 refresh=7 ; email=recette@example.com
  DELETE /users/me -> 204
  # après: trips=0 stages=0 shares=0 refresh=0 ; deleted_at posé
  #        email = deleted-019e8c49-...@deleted.invalid
  # Redis : 0 occurrence de l'email, 0 clé citant l'uid
  ```

+ **RGPD-MAGIC (P3) — non-conformité** : la suppression **ne purge pas** la table `magic_link` (7 rows subsistent
  pour le compte supprimé, dont **2 encore valides**). Le user ayant `deleted_at` posé, l'auth doit les rejeter
  (hygiène plutôt qu'exploitation), mais c'est une purge incomplète. **Reco :** supprimer les `magic_link` du user
  dans `AccountDeleteProcessor`.

---

## R5 — perf runtime, responsive & résilience (empirique)

### Analyse de bundle (`next build` prod)

~6,5 Mo de chunks au total ; **maplibre-gl ~1,1 Mo** = dépendance dominante. Le chunk maplibre **n'est PAS dans
`rootMainFiles`** (les 8 chunks chargés sur toutes les pages) et n'est référencé que par les routes carte
(`/trips/new`...). Il est donc **route-splitté à l'éditeur**, absent de la landing/login/pages publiques ->
**confirme l'atténuation de PERF-001** (maplibre hors du chemin critique public). Le lazy-load de `MapPanel`
(reco PERF-001) allégerait encore le 1er paint de l'éditeur (LH-PERF-AUTH `/trips/new` = 0.52).

### N+1 Doctrine — aucun (PostgreSQL `log_statement=all`, workers en pause)

```text
GET /trips/{id}/detail (3 stages) : 3 requêtes
  SELECT ... FROM "user" WHERE ... (provider JWT)
  SELECT ... FROM trip WHERE id = ?
  SELECT ... FROM stage WHERE trip_id = ?     <- une seule, tous les stages
GET /trips (collection) : 4 requêtes
  SELECT ... FROM "user" ...
  SELECT COUNT(DISTINCT t0_.id) FROM trip WHERE user_id = ?
  SELECT DISTINCT id_0, MIN(...) ...          <- fenêtre d'ids (Paginator)
  SELECT t0_.* ... (hydrate + join stages)    <- fetchJoinCollection
```

Aucune requête par-ligne : le `/detail` est **plat** avec le nombre de stages (1 SELECT pour les 3 stages). Pour la
collection (mesurée ici avec 1 trip), le **nombre de requêtes est O(1) par construction** — le triptyque
count + fenêtre d'ids + hydrate-join est la signature `fetchJoinCollection`, indépendante du nombre de trips.
Conformité N+1 confirmée (un re-test à N trips le confirmerait quantitativement).

### Multi-device / responsive — aucun overflow

Matrice **90 checks** : 5 pages publiques (`/`, `/login`, `/faq`, `/legal`, `/privacy`) × 3 viewports
(375 / 768 / 1440) × thèmes clair+sombre × **Chromium + Firefox + WebKit** -> **0 overflow horizontal, 0 erreur**
(`document.scrollWidth <= clientWidth` partout). L'éditeur authentifié (`/trips/new`, panneaux + carte) reste un
check responsive ciblé à part (cf. « Reste à exécuter »).

### Chaos / résilience workers

+ **Durabilité — confirmée** : workers stoppés -> trip créé -> **0 stage** + **9 messages en attente** dans le
  stream Redis `messages` (non perdus) -> redémarrage -> stages calculés, transport `failed` = **0**. Aucun message
  perdu malgré l'absence de consommateur.
+ **CHAOS-RESTART (P3 — à confirmer)** : après `docker kill` (`SIGKILL`, signal **137** = celui de l'OOM-killer),
  un worker est resté `Exited(137)` **> 2 min** (sur 2 essais) au lieu de redémarrer vite. **Mais** `docker inspect`
  donne `RestartCount=1` (Policy `unless-stopped`) : la politique **a bien fini par s'appliquer** — ce n'est donc
  pas un « jamais de redémarrage ». La mesure est **confondue** par mes kills répétés (backoff Docker croissant)
  et l'environnement local. L'attribution à l'interaction `deploy.replicas` reste **non prouvée**. Le risque
  théorique demeure : workers cappés à **512 Mo** (`deploy.resources.limits`), donc OOM plausible ; un redémarrage
  lent/peu fiable dégraderait le débit (messages durables, non perdus, mais traités en retard).
  **Reco :** retester proprement en isolé (un seul kill, backoff réinitialisé) **sur l'orchestrateur de prod
  (Coolify)**, et vérifier que la politique de restart est effective sous réplicas avant de statuer.

---

## Reste à exécuter (empirique — recette ultérieure / Sprint 35.3+)

Items non bloquants pour la publication de ce rapport, à exercer sur stack seedée (certains bridés par la machine
d'audit : conteneur pwa cappé, OOM `tsc`/`vitest`) :

> Faits empiriquement : **R4** — matrice 401/403 (-> IDOR-DETAIL), purge RGPD DB/Redis (-> RGPD-MAGIC), F4
> (-> faux finding), stack-trace (422/415/404/500), XSS (0 sink), Lighthouse authentifié (-> LH-PERF-AUTH) ;
> **R5** — bundle (maplibre route-split), N+1 (aucun), multi-device 90 checks (0 overflow), chaos (durabilité OK
> plus le finding CHAOS-RESTART). Restent (marginaux) :

| Sujet | Pourquoi pas encore fait | Où |
|---|---|---|
| Responsive éditeur authentifié (`/trips/new`, panneaux + carte) | overflow check authed | iso-prod recette |
| axe runtime + nav clavier pages authentifiées | stack dev seedée + Playwright | dev seedé |
| Reconnexion SSE Mercure sur coupure réseau (navigateur) | EventSource live + `tc netem`/disconnect | navigateur piloté |
| XSS chat — payload runtime (complément au static 0-sink) | trip calculé + chat Ollama | iso-prod recette |
