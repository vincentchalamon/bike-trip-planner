# Ordre 1 — Inventaire des écrans

Inventaire dérivé de `pwa/src/app/` (App Router Next.js 16). Pour chaque route : objectif, exigence d'authentification, états de données et composants principaux.

## Classes d'authentification

Source : `pwa/src/components/auth-guard.tsx`.

- **public** : rendu immédiat, aucune session requise.
- **requires-auth** : redirige vers `/login` si non authentifié ; rend `null` pendant le contrôle pour éviter le flash de contenu.
- **anon-only** : redirige vers `/` si déjà authentifié.

| Classe | Routes |
|---|---|
| public (exact) | `/`, `/faq`, `/legal`, `/privacy`, `/access-requests/verify` |
| public (préfixe) | `/auth/verify/*`, `/s/*` |
| anon-only | `/login` |
| requires-auth | `/trips`, `/trips/new`, `/trips/[id]`, `/account/settings` |

`/` est public mais **dual-state** : page d'atterrissage pour les anonymes, tableau de bord planner pour les authentifiés (silent refresh au montage).

## Écrans

### 1. Accueil — `/`

- **Fichier :** `pwa/src/app/page.tsx`
- **Objectif :** atterrissage (anon) ou tableau de bord planner (auth).
- **Auth :** public, dual-state.
- **États :**
  - `loading` : rendu `null` pendant le silent refresh (anti-flash).
  - anon : `LandingPage`.
  - auth : `TripPlanner` (dans `HydrationBoundary` + `TripPlannerErrorBoundary`).
- **Composants clés :** `LandingPage`, `TripPlanner`, `TripPlannerErrorBoundary`, `HydrationBoundary`.

### 2. Connexion — `/login`

- **Fichier :** `pwa/src/app/login/page.tsx`
- **Objectif :** authentification par lien magique.
- **Auth :** anon-only (redirige vers `/` si authentifié).
- **États :** formulaire prêt ; bannière early access. Sous-états (envoi, lien envoyé, cooldown 60s) gérés par `MagicLinkForm`.
- **Composants clés :** `MagicLinkForm`, `AttributionFooter`.

### 3. Mes voyages — `/trips`

- **Fichier :** `pwa/src/app/trips/page.tsx`
- **Objectif :** lister, filtrer, paginer et supprimer les voyages.
- **Auth :** requires-auth.
- **États :**
  - `loading` : spinner + texte (`aria-live=polite`, `aria-busy`).
  - `error` : message + bouton réessayer.
  - `empty` : `TripsEmptyState` variant `empty`.
  - `no-results` (filtres actifs) : `TripsEmptyState` variant `no-results` + reset.
  - `populated` : grille `TripCard` (1 col mobile / 2 col desktop), pagination si `totalPages > 1`, dialog de confirmation de suppression.
- **Composants clés :** `TripCard`, `TripsEmptyState`, `Dialog`.

### 4. Nouveau voyage (wizard) — `/trips/new`

- **Fichier :** `pwa/src/app/trips/new/page.tsx`
- **Objectif :** wizard 4 étapes (préparation -> aperçu -> analyse -> redirection vers le voyage).
- **Auth :** requires-auth.
- **États (param `?step=` = source de vérité, synchro bidirectionnelle avec le store) :**
  - step 1 préparation : `CardSelection` (URL / GPX / Assistant IA).
  - step 2 aperçu : carte + stats + étapes + sliders + CTA "Lancer l'analyse" + `AiRefinementCard`.
  - step 3 analyse : flux narratif SSE.
  - step 4 : redirige vers `/trips/[id]` quand `tripId` connu.
- **Composants clés :** `WizardStepper`, `TripPlanner` (`hideStepper`, `previewSlot`), `AiRefinementCard`.

### 5. Détail voyage — `/trips/[id]`

- **Fichiers :** `pwa/src/app/trips/[id]/page.tsx` (wrapper) -> `trip-page.tsx`.
- **Objectif :** consulter et éditer un voyage persisté (éditeur principal).
- **Auth :** requires-auth.
- **États :**
  - `loading` : skeletons (`TripSummarySkeleton`, `TimelineSidebarSkeleton`, `StagePanelSkeleton`, `aria-busy`).
  - `error` (non trouvé) : `TripNotFound`.
  - `populated` : `TripPlanner` hydraté ; états internes `computing`, météo en chargement, scan hébergements.
- **Composants clés :** `TripLoader` (fetch `/trips/{id}/detail`), `TripPlanner`, skeletons.

### 6. Vue partagée — `/s/[code]`

- **Fichiers :** `pwa/src/app/s/[code]/page.tsx` (wrapper) -> `shared-trip-page.tsx`.
- **Objectif :** vue publique **lecture seule** d'un voyage partagé (roadbook + carte, sans édition).
- **Auth :** public (préfixe `/s/`).
- **États :**
  - `loading` : spinner centré (min-height 60vh).
  - `error` (non trouvé / lien révoqué) : `TripNotFound` variant `share` + `SharedTopBar`.
  - `populated` : `TripSummary` (read-only), `ViewModeToggle` (timeline / map / split), `RoadbookMasterDetail` (`readOnly`), `MapPanel`.
- **Composants clés :** `SharedTripLoader`, `SharedTopBar`, `SharedViewBanner`, `TripSummary`, `ViewModeToggle`, `RoadbookMasterDetail`, `MapPanel`.

### 7. Paramètres du compte — `/account/settings`

- **Fichier :** `pwa/src/app/account/settings/page.tsx`
- **Objectif :** gestion du compte (email, langue/thème, export RGPD, suppression, déconnexion).
- **Auth :** requires-auth.
- **États :** sections empilées ; sous-états gérés par chaque section.
- **Composants clés :** `AccountSection`, `PreferencesSection`, `DataSection`, `DangerZoneSection`, `LogoutSection`.

### 8. Vérification demande d'accès — `/access-requests/verify`

- **Fichier :** `pwa/src/app/access-requests/verify/page.tsx`
- **Objectif :** vérifier une demande d'early access via lien email (params signés HMAC transmis au backend).
- **Auth :** public.
- **États :**
  - `loading` : spinner + "Verifying…" (`role=status`, `aria-live`).
  - succès : `window.location.replace()` vers le backend, qui redirige vers `/?access=confirmed`.
  - erreur (params manquants) : redirection auto vers `/`.
- **Composants clés :** aucun (spinner/texte).

### 9. Vérification lien magique — `/auth/verify/[token]`

- **Fichiers :** `pwa/src/app/auth/verify/[token]/page.tsx` (wrapper) -> `verify-page.tsx`.
- **Objectif :** consommer le token à usage unique, recevoir JWT + cookie refresh.
- **Auth :** public (préfixe `/auth/verify`).
- **États :**
  - `loading` : spinner + "Verifying…".
  - succès : `setAuth` + redirection vers `/`.
  - erreur (token invalide/expiré) : `LinkExpired` (option redemander un lien -> `/login`).
- **Composants clés :** `LinkExpired`.

### 10. FAQ — `/faq`

- **Fichier :** `pwa/src/app/faq/page.tsx`
- **Objectif :** FAQ publique, 3 catégories repliables (projet, fonctionnement, accès).
- **Auth :** public.
- **États :** contenu statique SSR.
- **Composants clés :** `FaqAccordion`.

### 11. Mentions légales — `/legal`

- **Fichier :** `pwa/src/app/legal/page.tsx`
- **Objectif :** éditeur, hébergeur, contact, propriété intellectuelle.
- **Auth :** public.
- **États :** contenu statique SSR.
- **Composants clés :** `LegalPageLayout`, `LandingFooter`.

### 12. Confidentialité — `/privacy`

- **Fichier :** `pwa/src/app/privacy/page.tsx`
- **Objectif :** politique RGPD (responsable, base légale, finalités, conservation, droits, sous-traitants, analytics, contact).
- **Auth :** public.
- **États :** contenu statique SSR.
- **Composants clés :** `LegalPageLayout`, `LandingFooter`.

## Variantes transverses

Au-delà des états par écran, ces variantes s'appliquent largement et doivent être vérifiées :

- **Thème :** clair / sombre / système (`theme-toggle.tsx`).
- **Langue :** FR / EN (`locale-switcher.tsx`).
- **Hors-ligne :** `offline-banner.tsx` (voir `mobile-offline.feature`).
- **Onboarding :** `onboarding-tour.tsx` au premier lancement.
- **Responsive :** mobile 390px vs desktop 1280px.

## États non routés (écrans système)

Pas de route App Router dédiée mais présents dans le design et le code :

- **404** : `not-found` (design `PageNotFoundDesktop`).
- **500** : error boundary (design `PageErrorDesktop`, `TripPlannerErrorBoundary`).
