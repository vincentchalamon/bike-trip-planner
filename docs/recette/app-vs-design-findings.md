# Ordre 4 — Findings comparaison app vs design

Audit de **divergences fonctionnelles, d'éléments et de positionnement** entre
l'application réelle (stack iso-prod locale, build `pwa:ci`) et l'export Claude
Design (manifeste [`03-manifeste-elements.md`](03-manifeste-elements.md),
inventaire [`01-inventaire-ecrans.md`](01-inventaire-ecrans.md)).

But : alimenter Sprint 35.4 et **guider la recette manuelle** (côte-à-côte). On
ne traite **pas** ici la colorimétrie, la typo, les espacements ni le polish :
ce sont des vérifications "à l'oeil" en recette manuelle (voir la convention du
manifeste). On se concentre sur : **présence/absence d'éléments**, **différences
de texte/fonctionnalité**, **mauvais positionnement de région**.

## Méthode et honnêteté

- **Source app** : code des composants (`pwa/src/app`, `pwa/src/components`) +
  baselines VR rendues (`pwa/tests/visual/__screenshots__`), build iso-prod.
- **Source design** : manifeste d'éléments + inventaire (dérivés de l'export).
- **Pourquoi pas 100 % automatisé** : une première version de `manifest.spec.ts`
  (assertions de région via heuristique de viewport) a été **retirée** car non
  fiable, pour deux raisons documentées ici :
  1. **Variante d'authentification** — les écrans anon (`/`, `/login`) rendus à
     travers le fixture mocké *authentifié* affichent leur variante connectée
     (redirection / dashboard), produisant de faux "élément absent".
  2. **Heuristique de région** — classer un élément en header/sidebar/footer
     d'après sa bounding box relative au viewport produit des faux positifs
     (ex. un lien de footer réel détecté "en main"). Le positionnement réel se
     vérifie mieux à l'oeil en recette manuelle.
  Les baselines VR (captures déterministes) restent l'outil de non-régression ;
  ce document est l'**audit de divergence** curé.
- **Légende gravité** : 🔴 fonctionnel/élément manquant impactant · 🟠 écart de
  structure/positionnement · 🟢 conforme (ou app plus riche) · ⚪ non vérifiable
  ici (recette manuelle).

## Verdict par écran

### 1. Landing `/` (anon) — 🟢 conforme, app plus riche

| Aspect | Design | App | Verdict |
|---|---|---|---|
| Sections | hero, 4 étapes, bento, sources, aperçu écrans, CTA beta, footer | hero, how-it-works, features, bento, sources, availability, testimonials, footer | 🟢 toutes présentes (+ `section-features`, `section-testimonials` en plus) |
| CTA hero | "Créer mon itinéraire" + "Voir la démo" | `cta-create-itinerary` + `cta-demo` | 🟢 |
| **CTA beta bas de page** | section "Rejoignez la beta privée" : **champ email + Demander l'accès** | aucun champ email de capture en bas de landing | 🟠 **capture email beta absente de la landing** (le flux early-access existe via `/login` + `/access-requests/verify`, mais pas en CTA bas de page) — à confirmer en recette |

### 2. Login `/login` (anon-only) — 🟢 conforme

| Aspect | Design | App | Verdict |
|---|---|---|---|
| Carte centrée | logo + H2 "Bon retour" + email + "Envoyer le lien magique" + lien demander accès | `login-card` (`MagicLinkForm`) | 🟢 |
| Bannière early access | — | `early-access-banner` (présent en plus) | 🟢 app plus riche |
| États envoyé/cooldown | check + "Email envoyé" + "Renvoyer (Ns)" | gérés par `MagicLinkForm` | ⚪ sous-états à vérifier en recette |

### 3. Trips list `/trips` (auth) — 🟢 conforme

| Aspect | Design | App | Verdict |
|---|---|---|---|
| En-tête | H1 "Mes voyages" + recherche + filtre date + "Nouveau voyage" | `new-trip-button` + filtres + recherche | 🟢 |
| Grille | cartes 2 col (vignette, pill statut, titre, route+date, stats) | `trips-grid` / `TripCard` | 🟢 |
| États empty / no-results / error / loading | états UI design | `TripsEmptyState` (no-trips / no-results), error, loader | 🟢 couverture supérieure |
| TopBar | header TopBar | header de page (pas la TopBar roadbook) | ⚪ chrome d'en-tête à comparer en recette |

### 4. Wizard `/trips/new` (auth) — ⚪ à vérifier en recette

| Aspect | Design | App | Verdict |
|---|---|---|---|
| Stepper | `WizardStepper` (sidebar-L) | `wizard-stepper` présent | 🟢 |
| Step 1 onglets | "Lien URL" / "Fichier GPX" / "Assistant IA" | `CardSelection` (URL / GPX / IA) | 🟢 |
| Step 2 config | profil + 5 sliders pacing + e-bike + dates + types héberg. + "Affiner avec l'IA" + "Lancer l'analyse" | panneau config + `AiRefinementCard` | 🟢 éléments présents |
| Step 3 analyse | progression globale + 7 actes de calcul + aperçu temps réel | `processing-progress` (flux SSE) | ⚪ libellés/positions à comparer en recette |

### 5. Roadbook `/trips/[id]` (auth) — 🟢 conforme structurellement

| Aspect | Design | App | Verdict |
|---|---|---|---|
| Timeline étapes (sidebar-L) | header "Étapes" + timeline + ajouter étape/repos | `timeline-sidebar` | 🟢 |
| Carte + profil + ravitaillement (centre) | carte OSM + profil altimétrique + `SupplyTimeline` | `elevation-profile`, `supply-timeline` | 🟢 |
| Synthèse IA globale + d'étape | carte IA globale (droite) + synthèse IA d'étape | `trip-ai-overview`, `stage-ai-summary` | 🟢 |
| Bulle de chat IA (overlay) | bulle de chat IA | `ai-bubble` + `ai-chat-panel` | 🟢 (note : masquée en mode IA dégradé, #304) |
| Météo / alertes / hébergements (sidebar-R) | météo + alertes groupées + hébergements | présents | 🟢 |

### 6. Vue partagée `/s/[code]` (public) — ⚪ recette manuelle

Bannière lecture seule + top-bar simplifiée (GPX/FIT) + hero carte + cartes
étapes. Composants présents (`SharedTopBar`, `RoadbookMasterDetail readOnly`).
Positionnement et bannière à comparer côte-à-côte.

### 7. Account settings `/account/settings` (auth) — 🟠 divergence de structure

| Aspect | Design | App | Verdict |
|---|---|---|---|
| **Rail sidebar-L** | sidebar 240px : avatar + nom + email ; "Mon compte" (actif) ; **déconnexion (rouge)** | **absent** : layout **single-column** centré (`max-w-[800px]`) | 🟠 **pas de rail latéral** ; sections empilées dans `main` |
| Header | TopBar | barre logo minimale (lien "/" seul) | 🟠 chrome réduit |
| Déconnexion | bouton rouge dans le rail | `LogoutSection` empilée dans `main` | 🟠 positionnement différent |
| Footer | footer | **absent** | 🟠 |
| Cartes (Email / Préférences / RGPD / Danger Zone) | présentes | `account-section`, `preferences-section`, `data-section`, `danger-zone-section` | 🟢 contenu présent |

### 10. FAQ `/faq` (public) — 🟠 divergence de structure et de contenu

| Aspect | Design | App | Verdict |
|---|---|---|---|
| **Rail sidebar-L "Sections"** | nav 240px : Prise en main, Import & formats, Calculs & météo, Partage, Hors-ligne, **Tarifs** + carte GitHub | **absent** : layout single-column `max-w-2xl` | 🟠 **pas de rail de navigation** |
| Catégories accordéon | (6 sections design) | **3 catégories** : Projet / Fonctionnement / Accès | 🟠 **taxonomie différente** ; pas de section "Tarifs" (cohérent : pas de pricing) |
| Header | TopBar | lien retour `faq-back-link` seul | 🟠 chrome réduit |
| Footer | footer | lien "retour accueil" minimal (pas `LandingFooter`) | 🟠 |

### 11–12. Legal `/legal` & Privacy `/privacy` (public) — 🟢 conforme

| Aspect | Design | App | Verdict |
|---|---|---|---|
| Rail sidebar-L (Sommaire / menu) | TOC 220px sticky | `LegalPageLayout` : `md:grid-cols-[16rem_1fr]` + `<nav>` sticky `*-toc` | 🟢 **rail présent** (≈256px) |
| Sections ancrées | sections de contenu | `*-section-*` avec ancres | 🟢 |
| Footer | footer | `LandingFooter` | 🟢 |

> Correction d'une hypothèse antérieure : le rail latéral n'est **pas** absent
> sur legal/privacy (contrairement à account/faq). Il l'est seulement sur **faq**
> et **account**.

### 13. 404 `not-found.tsx` (public) — 🟢 conforme

"404" + H2 + CTA retour accueil / mes voyages. `not-found-page` rendu. 🟢

### 14. 500 `error.tsx` / `global-error.tsx` — ⚪ non déclenchable en l'état

| Aspect | Design | App | Verdict |
|---|---|---|---|
| Page erreur serveur | icône + badge "Erreur serveur · 500" + `request_id` + timestamp + "Réessayer" / "Signaler" | `error.tsx` existe mais **non déclenchable sans route de test** embarquée | ⚪ baseline VR substituée par la surface 404 voyage (`TripNotFound`, `trip-error`) ; polish 500 = recette manuelle |

### Modales (overlays sur roadbook chargé) — 🟢 / 🟠

| Modale | Design | App | Verdict |
|---|---|---|---|
| Partage | lien + infographie PNG + résumé texte + Garmin Connect | `modal-share` (create-link / link-text) | 🟢 baseline ; contenu Garmin/infographie à vérifier en recette |
| Config | dates + profil + sliders + hébergements + thème/langue + dupliquer/partager | `config-open-button` → dialog Paramètres | 🟢 baseline |
| Aide — Raccourcis | liste J/K, Ctrl+Z/Y, ?, Esc, T, M | `help-tab-shortcuts-panel` | 🟢 baseline |
| **FAQ rapide** | **modale FAQ autonome** (`ModalFaq`, accordéon 7 items) | **onglet FAQ de la modale Aide** (`help-tab-faq`) — pas de modale FAQ séparée | 🟠 **fonctionnalité fusionnée** : la "modale FAQ" du design = onglet de la modale Aide dans l'app |

## Hotlist Sprint 35.4 (divergences actionnables)

1. 🟠 **Account settings** : pas de rail latéral (avatar/email/déconnexion), pas
   de footer, header réduit. Décider : aligner sur le design (rail + footer) ou
   acter le single-column comme choix produit.
2. 🟠 **FAQ** : pas de rail "Sections", taxonomie 3 catégories vs 6, pas de
   `LandingFooter`. Décider : rail + footer + section "Tarifs" (si pricing) ou
   acter la version simplifiée.
3. 🟠 **Landing** : CTA de capture email "beta privée" absent en bas de page
   (flux early-access présent ailleurs). Décider si on ajoute la section.
4. 🟠 **Modale FAQ** : design = modale autonome, app = onglet de la modale Aide.
   Acter la fusion (recommandé, évite la duplication) ou séparer.
5. ⚪ **Page 500** : non déclenchable en recette sans route de test. Évaluer une
   route `/__error` gardée hors-prod pour rendre `error.tsx` testable, sinon
   garder la vérif manuelle.
6. ⚪ **Polish (couleur/typo/espacement)** sur tous les écrans 🟢/⚪ : revue
   visuelle côte-à-côte en recette manuelle, hors périmètre auto.

## Couverture VR (baselines committées)

Captures déterministes par `make visual-update`, comparées par `make
visual-test` (local, hors CI). Régions non déterministes (cartes, canvas, dates,
texte IA) masquées. Écrans dont l'état n'est pas atteignable de façon
déterministe via la chaîne de mocks contre le build iso-prod sont marqués
`fixme` dans les specs avec une raison explicite (couverts fonctionnellement par
les suites mocked/recette).
