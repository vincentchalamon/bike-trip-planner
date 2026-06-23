# Ordre 4 — Audit de couverture Gherkin

Audit des 30 fichiers `.feature` (15 sujets x FR/EN) sous `pwa/tests/recette/features/` face aux features réellement livrées, et liste des scénarios manquants à écrire (priorité IA S30-32 et design S25-27).

## Inventaire et parité FR/EN

154 scénarios côté FR. Parité globalement bonne, **3 écarts** (l'EN a un `Scenario Outline` absent du FR) :

| Sujet | FR | EN | Parité |
|---|---|---|---|
| accommodations | 11 | 11 | OK |
| alerts-analysis | 9 | 10 | **écart** : EN a "Difficulty thresholds by alert type" |
| auth-security | 10 | 10 | OK |
| configuration | 10 | 10 | OK |
| cross-cutting-ux | 12 | 12 | OK |
| dates-calendar | 8 | 8 | OK |
| edge-cases | 12 | 12 | OK |
| export | 8 | 9 | **écart** : EN a "Export by file format" |
| map-visualization | 12 | 12 | OK |
| mobile-offline | 12 | 12 | OK |
| sharing | 8 | 8 | OK |
| stage-management | 12 | 12 | OK |
| trip-creation | 11 | 12 | **écart** : EN a "Trip creation from different sources" |
| trip-management | 9 | 9 | OK |
| weather-time | 10 | 10 | OK |

**À corriger (parité) :** ajouter les 3 `Scenario Outline` manquants côté FR :

- `alerts-analysis.fr.feature` : "Seuils de difficulté par type d'alerte"
- `export.fr.feature` : "Export par format de fichier"
- `trip-creation.fr.feature` : "Création de voyage depuis différentes sources"

## Matrice de couverture vs features réelles

Les 15 sujets couvrent bien les **workflows cœur** (sprints 1-24). Les **trous portent précisément sur les sprints récents** : IA S28-32 et refonte design S25-27.

| Domaine réel | Composant(s) | Couverture | Statut |
|---|---|---|---|
| Workflows cœur (création, étapes, météo, hébergements, alertes, export, partage, dates, carte, offline) | — | 13 sujets | **COUVERT** |
| Synthèse IA voyage | `trip-ai-overview.tsx` | aucune | **MANQUANT** |
| Synthèse IA étape | `stage-ai-summary.tsx` | aucune | **MANQUANT** |
| Chat IA conversationnel | `ai-chat-panel.tsx` | aucune | **MANQUANT** |
| Raffinement IA (génération conversationnelle) | `ai-refinement-card.tsx` | aucune | **MANQUANT** |
| Surlignage des diffs IA | `diff-highlight.tsx` | aucune | **MANQUANT** |
| Page d'atterrissage | `landing-page.tsx`, `landing/` | aucune | **MANQUANT** |
| Tour d'onboarding | `onboarding-tour.tsx` | `cross-cutting-ux` (1 scénario) | **PARTIEL** |
| Toggle thème (clair/sombre/système) | `theme-toggle.tsx` | `cross-cutting-ux` (2 scénarios) | **PARTIEL** |
| Sélecteur de langue | `locale-switcher.tsx` | `cross-cutting-ux` (2 scénarios) | **PARTIEL** |
| Supply timeline | `SupplyTimeline/` | aucune | **MANQUANT** |
| Timeline/roadbook redesignée | `timeline.tsx`, `timeline-marker.tsx` | `stage-management` (partiel) | **PARTIEL** |

## Scénarios manquants à écrire

Estimation ~53 scénarios : 2 nouveaux fichiers + extensions de 2 fichiers existants. À écrire en FR **et** EN (parité).

### IA S28-32 — nouveau fichier `ai-features.{fr,en}.feature` (priorité HAUTE)

**Synthèse IA voyage (`trip-ai-overview`)** — 5 scénarios :

- Affichage de la carte de synthèse IA en haut de "Mon voyage".
- Contenu narratif visible.
- Patterns globaux visibles (dénivelé cumulé, difficultés saisonnières).
- Recommandations inter-étapes visibles.
- Carte masquée quand le LLM est absent/désactivé.

**Synthèse IA étape (`stage-ai-summary`)** — 4 scénarios :

- Carte "Analyse IA" présente sur chaque étape.
- Description IA de l'étape affichée.
- Alertes cross-stage intégrées dans la carte.
- Déploiement de l'analyse complète au clic.

**Chat IA (`ai-chat-panel`)** — 6 scénarios :

- Panneau chat visible après calcul.
- Message envoyé au backend (`POST /trips/*/ai-chat`).
- Réponse affichée dans l'historique.
- Indicateur de chargement pendant la réponse.
- Mode "En route" + géolocalisation -> POIs proches.
- Carte POI (`PoiCard`) affichée depuis une réponse.

**Raffinement IA (`ai-refinement-card`)** — 7 scénarios :

- Carte "Suggestion de modification" visible.
- Limite 500 caractères respectée.
- Compteur de caractères affiché.
- Bouton "Appliquer" disabled si vide.
- Appel API de raffinement déclenché.
- Étapes régénérées après raffinement.
- Textarea vidée après succès.

**Surlignage diff IA (`diff-highlight`)** — 3 scénarios :

- Distance modifiée surlignée (animation ~3s).
- Alerte ajoutée surlignée.
- Annonce lecteur d'écran du champ modifié.

### Design S25-27 (priorité HAUTE / MOYENNE)

**Page d'atterrissage** — nouveau fichier `landing-page.{fr,en}.feature` — 8 scénarios :

- Hero (titre, description, CTA).
- "Comment ça marche" (3-4 étapes).
- Bento avantages.
- Sources supportées (Komoot, Strava, RideWithGPS, GPX).
- Section plateformes.
- Témoignages / cas d'usage.
- Footer + liens légaux.
- Responsive 390px (sections empilées).

**Onboarding** — étendre `cross-cutting-ux` — 7 scénarios :

- Fermeture par `Esc`.
- Fermeture par clic overlay.
- Étape 1 cible le champ lien magique.
- Étape 2 cible le bouton upload GPX.
- Étapes 3-4 modales centrées (profil rider, lecture timeline).
- Tour ne réapparaît plus après fermeture (flag persistant).

**Thème** — étendre `cross-cutting-ux` — 3 scénarios :

- Cycle clair -> sombre -> système.
- Mode système suit l'OS.
- Choix persisté (localStorage) après refresh.

**Langue** — étendre `cross-cutting-ux` — 3 scénarios :

- Labels compacts (FR/EN) sur écran étroit.
- Permutation sur mobile.
- Persistance après refresh.

**Supply timeline** — étendre `stage-management` — 4 scénarios :

- Affichage de la timeline des ravitaillements.
- Marqueurs avec icônes (eau, nourriture, mécanique).
- Hover -> nom + distance.
- Défilement horizontal sur mobile.

**Timeline/roadbook redesignée** — étendre `stage-management` — 4 scénarios :

- Vue splitée carte + timeline.
- Toggle "Carte seule" / "Splitée".
- Réajustement responsive.
- Timeline plein écran sur mobile + bascule carte.

## Synthèse

| Catégorie | Fichiers | Scénarios à écrire | Priorité |
|---|---|---|---|
| Parité FR/EN | 3 existants | 3 outlines | BASSE |
| IA S28-32 | 1 nouveau (`ai-features`) | ~25 | HAUTE |
| Landing | 1 nouveau (`landing-page`) | 8 | HAUTE |
| Onboarding / thème / langue | extension `cross-cutting-ux` | 13 | MOYENNE |
| Supply / timeline | extension `stage-management` | 8 | MOYENNE |

> Note : l'écriture effective de ces scénarios + leur automatisation relève du **Sprint 35.3** (audit fonctionnel + couverture). Ce document en est le référentiel d'entrée.
