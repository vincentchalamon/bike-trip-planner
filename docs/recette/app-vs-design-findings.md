# Audit app vs design — v2 (pré-recette)

Audit code-level des divergences entre l'application réelle
(`pwa/src/app`, `pwa/src/components`, `pwa/messages/*.json`,
`pwa/src/app/globals.css`) et l'export Claude Design
(`docs/recette/design/*.jsx`, manifeste `03-manifeste-elements.md`,
inventaire `01-inventaire-ecrans.md`).

Cette v2 **corrige et enrichit** la v1 à partir d'une relecture du code.
Périmètre élargi par rapport à la v1 : la v2 traite aussi le microcopy, les
tokens de couleur/typo, la parité i18n, les états et le responsive, en plus de
la présence/absence d'éléments. Produite par audit multi-agent (10 dimensions,
111 observations) puis synthèse curée.

## Méthode et honnêteté

- **Détection code-level uniquement** : lecture des fichiers source, aucun rendu
  navigateur. Les jugements esthétiques, éditoriaux et d'équivalence visuelle
  sont marqués ⚪ / décision humaine et renvoyés au checkpoint utilisateur.
- **Source de vérité design** : `docs/recette/design/*.jsx`.
- **Légende gravité** : 🔴 fonctionnel/élément manquant impactant · 🟠 écart de
  structure/positionnement/contenu · 🟢 conforme (ou app plus riche) · ⚪ non
  vérifiable code-level (recette manuelle / décision).

## Corrections de la v1 (findings périmés)

| Finding v1 | Statut | Correction |
|---|---|---|
| Landing : « CTA capture email beta absente » (🟠, hotlist #3) | ❌ FAUX | `landing-page.tsx` l.31 monte `EarlyAccessSection` (section-early-access) → `EarlyAccessForm` + confirmation `?access=confirmed`. App plus riche. L'écart résiduel est éditorial (« Inscriptions bientôt disponibles » vs « Demander l'accès », argument « gratuit pendant la beta »). |
| Landing : « 🟢 conforme, app plus riche » | ⚠️ INCOMPLET | Header/top-bar **absent** de la landing (🔴) : `landing-page.tsx` démarre par `LandingHero`, le `TopBar` n'est monté que dans `trip-planner.tsx`. Langue/thème/connexion seulement au footer. |
| Landing sections « toutes présentes (+ en plus) » | ⚠️ À PRÉCISER | `LandingPlatforms` et `LandingTestimonials` sont **absentes du design** et intercalées dans la séquence ; témoignages potentiellement placeholder. |
| 500 « non déclenchable / polish manuel » | ⚠️ REQUALIFIÉ | Écart code-level : `error.tsx` sans badge 500, sans request_id/timestamp (digest loggé mais non rendu), sans « Signaler ». |
| 404 « conforme, 404 + 2 CTA » | ⚠️ INEXACT | `not-found.tsx` : illustration SVG + 1 seul bouton. Numéral « 404 » et CTA « Mes voyages » absents. |
| Modales « GPX/FIT » / FIT supposé dispo | ⚠️ INEXACT | `trip-downloads.tsx` = GPX seul (global) ; FIT seulement au niveau étape (`stage-downloads.tsx`). Commentaire de `top-bar.tsx` trompeur. |
| Tokens hors périmètre | ⚠️ ÉLARGI | Dérives systématiques : surface carte fusionnée au fond (#faf7f0 partout), palette sémantique red/blue/green sans token. |

## Verdict par écran

### 1. Landing `/` — 🔴 écart majeur (header) + écarts éditoriaux

| Aspect | Design | App | Verdict |
|---|---|---|---|
| Header / top-bar | header sur le hero (logo + FR/EN + thème + « Se connecter » + « Demander l'accès ») | aucun header ; `LandingHero` direct | 🔴 absent — nav/connexion/langue/thème repoussés au footer |
| Capture email beta | « Rejoignez la beta privée » + champ email | `EarlyAccessSection` + `EarlyAccessForm` (+ états success/throttled/confirmed) | 🟢 présent (corrige la v1) ; copie à arbitrer |
| Hero titre / sous-titre / badge | « planifié dans les moindres détails » / sources nommées / « accès sur demande » | « copilote pour chaque kilomètre » / bénéfice / « Places limitées » | 🟠 réécriture éditoriale |
| CTA hero | « Créer mon itinéraire » / « Voir la démo » | « Créer un voyage » / « Voir comment ça marche » | 🟠 incohérence interne (earlyAccess dit « Créer un itinéraire ») |
| How it works | titre narratif + Import/Aperçu&config/Analyse/Personnalisation | « Comment ça marche » + Importez/Analysez/Planifiez/Partez | 🟠 mapping d'étapes 1:1 imparfait |
| Features bento | « rouler serein » + « 20+ alertes » + « 9 hébergements » | « 9 modules » ; pas de carte « 20+ alertes » | 🟠 chiffres/cartes divergents |
| Testimonials / Platforms | absents du design | sections ajoutées et intercalées | 🟠 ajouts à valider (avis réels ?) |
| Footer | minimal + liens | tagline open-source + login + liens | 🟢 app plus riche |
| Bannière cookies | présente (design) | absente (Plausible sans cookie, justifié) | ⚪ divergence assumée |
| Ordre des sections communes | Hero→HowItWorks→Bento→Sources→Screens→EarlyAccess→Footer | identique | 🟢 ordre préservé |

### 2. Login `/login` — 🟢 conforme, ton à arbitrer

États form/sent/cooldown/sent-ready/expired tous présents
(`magic-link-form.tsx`, `email-sent.tsx`, `link-expired.tsx`), validation email
inline présente. Écarts de ton : design chaleureux (« Bon retour. », « lien
magique », affirmation d'envoi + « 15 minutes ») vs app sobre/anti-énumération
(« Si X est enregistrée… »). 🟠 éditorial — recommandation : garder
l'anti-énumération, ajouter le délai « 15 minutes ».

### 3. Trips `/trips` — 🟠 chrome + carte voyage

| Aspect | Design | App | Verdict |
|---|---|---|---|
| TopBar de page + footer | `TopBarDesktop` + `DesktopFooter` | `<main>` nu, H1 + croix | 🟠 absents |
| Filtre date | 1 bouton « Filtrer par date » | 2 `Input type=date` inline + clear | 🟠 structure (équivalent/plus riche) |
| Carte voyage | route ville→ville, stat « jours », menu ⋯ | date seule, « étapes », poubelle directe | 🟠 3 écarts (dont suppression directe = risque UX) |
| États empty/no-results/error/loading | définis | 5 états mutuellement exclusifs + pagination | 🟢 couverture supérieure |
| Statuts (pills) | active/done/draft/archived | draft/analyzing/analyzed | ⚪ dépend du data-model |

### 4. Wizard `/trips/new` — 🟢 fonctionnel, chrome absent

`WizardStepper` + `CardSelection` (URL/GPX/IA) + `processing-progress` (7 actes,
sous-états pending/in_progress/done/failed) présents. Pas de `TopBar` ni footer
(🟠). États d'erreur d'import (GPX invalide/lourd/corrompu, URL non supportée,
detection states) non vérifiés code-level dans ce passage → ⚪ recette.

### 5. Roadbook `/trips/[id]` — 🟢 structurellement conforme, FIT manquant

Structure timeline / carte / profil / supply / IA globale+étape / météo /
alertes / hébergements présente. Affordances d'étape « Élargir la zone » +
« Ajouter manuellement » présentes (🟢). **🔴 Export FIT global absent**
(`trip-downloads.tsx` = GPX seul ; FIT au niveau étape uniquement).

### 6. Vue partagée `/s/[code]` — 🟠 structure + responsive

`SharedTopBar` + `SharedViewBanner` + `RoadbookMasterDetail readOnly` +
`ViewModeToggle`. Le design propose une grille de cartes étapes + hero carte ;
l'app réutilise le master/detail propriétaire read-only (🟠, fonctionnellement
plus riche). **🟠 responsive** : la carte reçoit `hidden lg:block` en split
(masquée < lg) alors que l'éditeur l'empile en pleine largeur — incohérence à
aligner. FIT du top-bar partagé reporté (#404).

### 7. Account `/account/settings` — 🟠 divergence de structure

Header réduit au logo (pas de nav/langue/thème/profil), pas de rail latéral
240px (avatar/email/déconnexion rouge), pas de footer. Single-column
`max-w-[800px]`. Contenu des cartes (Email/Préférences/RGPD/Danger Zone)
présent ; microcopy conforme (« Modifier via magic link », « Télécharger mes
données (JSON) », confirmation « SUPPRIMER »).

### 8. FAQ `/faq` — 🟠 structure + taxonomie

Pas de `TopBar`, pas de rail « Sections », pas de `LandingFooter`. Accordéon 3
catégories (Projet/Fonctionnement/Accès, 9 items) vs 6 sections design (dont
« Tarifs », sans objet ici). Questions entièrement réécrites. ⚪ éditorial.

### 9. Legal `/legal` & Privacy `/privacy` — 🟢 structure / 🔴 contenu factuel

Rail TOC `md:grid-cols-[16rem_1fr]` sticky présent (🟢, corrige une hypothèse v1
antérieure). **🔴 divergences factuelles** : domaine contact
`biketripplanner.app` (design) vs `bike-trip-planner.app` (app, avec tirets) ;
conservation « 13 mois » vs « tant que le compte est actif » ; DPO dédié vs
contact générique ; hébergeur OVH nommé vs « cloud UE ». Point juridique à
trancher.

### 10. 404 `not-found.tsx` — 🟠 éléments

Illustration SVG cycliste + H1 « Hors-piste » + 1 bouton « Retour à l'accueil ».
Numéral « 404 » serif et 2e CTA « Mes voyages » du design absents.

### 11. 500 `error.tsx` — 🟠 éléments d'état

Icône accent (pas rouge) + retry + retour accueil. Manquent : badge « Erreur
serveur · 500 », bloc mono request_id + timestamp (digest loggé non rendu),
bouton « Signaler le problème ». request_id seulement dans `global-error.tsx`
(boundary distincte).

### 12. Modales (overlays roadbook)

| Modale | Design | App | Verdict |
|---|---|---|---|
| Partage | lien + PNG + texte + **Garmin Connect** | lien + PNG (rect + carré 1080²) + texte ; **pas de Garmin** | 🔴 Garmin Connect absent ; app plus riche sur l'infographie |
| Config | dates + pacing + héberg. + **thème/langue** + **preset profil** | dates + pacing + héberg. + actions ; drawer latéral | 🟠 thème/langue déplacés au TopBar ; preset segmenté absent ; drawer vs modale centrée |
| Aide — Raccourcis | J/K/Ctrl+Z/Y/?/Esc + **T** + **M** | J/K/Ctrl+Z/Y/?/Esc | 🟠 T (thème) et M (carte) absents (hook + libellés) |
| FAQ | modale autonome | onglet de la modale Aide (`help-tab-faq`) | 🟢 fusion actée (évite duplication) |

## Tokens (globals.css vs tokens.jsx)

| Élément | Design | App | Verdict |
|---|---|---|---|
| Familles police | Fraunces / Inter Tight / JetBrains Mono | identiques (next/font → --font-*) | 🟢 |
| Accent amber | #c2671e | #c2671e | 🟢 |
| Surface carte | #ffffff distinct de bg #faf7f0 | --card = --background = --surface = #faf7f0 | 🟠 contraste carte/fond supprimé |
| Encre principale | #2a2418 | #1a1814 | 🟠 plus sombre/froide |
| Palette red/blue/green (+soft) | hex pinnés (~90 réfs) | aucun token, classes Tailwind brutes | 🔴 valeurs design non respectées |
| Neutres line/inkSoft/inkMute/surfaceAlt | hex tièdes très référencés | oklch shadcn génériques | 🟠 grille de neutres non exposée |
| Ombres | teintées brun chaud | noir neutre, nomenclature différente | 🟠 |
| Variantes accent + dark | amber/forest/indigo/brick + dark | toutes implémentées | 🟢 app conforme/plus riche |
| forest/rose, radius/spacing/fontSize | accents décoratifs / littéraux composant | tokens différents / non comparables | ⚪ recette |

## i18n FR/EN — 🟢 conforme

Parité de clés EN/FR **parfaite** (0 clé manquante dans les deux sens),
namespaces et tableaux (legal/privacy paragraphs) identiques, placeholders ICU
cohérents. Seuls « écarts » : valeurs EN==FR légitimes (marques, cognats,
unités) et un écart stylistique mineur sur `status_analyzing`
(« Analyzing… » vs « En cours d'analyse »). Aucun risque de crash next-intl.

## Responsive — 🟢 globalement solide, 2 écarts

262 prefixes Tailwind répartis ; dialogs mobile-safe ; viewport Next.js par
défaut. Écarts : (1) nav primaire `hidden sm:flex` sans hamburger < 640px ;
(2) carte vue partagée `hidden lg:block` en split (incohérent avec l'éditeur).
Le design ne fournit aucun artboard mobile (équivalence visuelle ⚪).

## Iconographie — ⚪ majoritairement décision humaine

App = lucide 75+ ; design = SVG maison. UI conforme ; carto/météo plus riches.
Écarts structurels (logo lucide Bike vs logomark dédié ; wordmarks sources vs
glyphes ; illustrations 404/500 ; absence og:image) à juger en recette.

## Hotlist détaillée

| ID | Écran | Gravité | Écart | Effort | Décision humaine |
|---|---|---|---|---|---|
| H1 | landing `/` | 🔴 | Header/top-bar absent (connexion + langue + thème en haut) | M | non |
| H2 | trips, account, faq, wizard | 🟠 | TopBar globale absente hors roadbook | L | oui |
| H3 | roadbook & shared | 🔴 | Export FIT global manquant (GPX seul) | M | oui |
| H4 | modale Partage | 🔴 | Section Garmin Connect absente | L | oui |
| H5 | legal & privacy | 🔴 | Divergences factuelles RGPD/légales (domaine, hébergeur, conservation, DPO) | S | oui |
| H6 | 500 `error.tsx` | 🟠 | Sans badge 500, request_id/timestamp ni « Signaler » | S | oui |
| H7 | 404 `not-found.tsx` | 🟠 | Sans numéral « 404 » ni 2e CTA « Mes voyages » | S | oui |
| H8 | trips `/trips` (carte) | 🟠 | Pas de route ville→ville, stat « étapes » vs « jours », poubelle directe vs menu ⋯ | M | oui |
| H9 | modale Aide — Raccourcis | 🟠 | Raccourcis T (thème) et M (carte) absents | S | non |
| H10 | shared `/s/[code]` | 🟠 | Carte masquée (`hidden lg:block`) en split, incohérent avec l'éditeur | S | non |
| H11 | global (top-bar) | 🟠 | Nav primaire masquée < 640px sans hamburger | M | oui |
| H12 | global (tokens couleur) | 🟠 | Palette sémantique red/blue/green sans token + surface carte fusionnée au fond | M | non |
| H13 | landing (testimonials) | 🟢 | Section témoignages absente du design — vérifier la véracité des avis | S | oui |
| H14 | landing (hero CTA) | 🟢 | Incohérence interne CTA « voyage » (hero) vs « itinéraire » (earlyAccess) | S | oui |

## Décisions prises (checkpoint pré-recette)

Arbitrées avec l'éditeur avant build. Le batch pré-recette construit le sous-
ensemble ci-dessous ; le reste est différé au Sprint 35.4 ou acté comme choix
produit.

| Sujet | Décision | Disposition |
|---|---|---|
| TopBar globale (H2) | Généraliser la TopBar à `/trips`, `/account/settings`, `/faq`, wizard | **Build** |
| Header landing (H1) | Monter un header (logo + FR/EN + thème + connexion + demander l'accès) | **Build** |
| Account (rail + footer) | Ajouter le rail latéral 240px + footer | **Build** |
| FAQ (rail + footer) | Ajouter le rail « Sections » + `LandingFooter` ; **garder la taxo 3-cat** (pas de « Tarifs », pas de pricing) | **Build** |
| Export FIT (H3) | FIT global maintenant (handler déjà au niveau étape) ; corriger le commentaire trompeur de `top-bar.tsx` | **Build** |
| Garmin Connect (H4) | Différé post-recette (Sprint 31) | Différé |
| Légal/privacy (H5) | **Copie générique self-host** : pas de domaine ni hébergeur figé (domaine de déploiement encore inconnu ; hébergeur potentiellement Oracle Cloud Free Tier, amené à changer ; projet open-source auto-hébergeable). Conservation = anonymisation immédiate + cache 24h. Contact générique, pas de DPO dédié | **Build** |
| Landing microcopy (H14, hero/badge/étapes) | Aligner au plus près du design (badge beta, punchline IA, sources nommées) ; harmoniser le CTA interne | **Build** |
| Témoignages landing (H13) | Garder mais encadrer « exemple / testeur bêta » (avis non vérifiés → risque éditorial sinon) | **Build** |
| Page 500 (H6) | Enrichir : badge « 500 » + request_id (digest) + timestamp + icône rouge ; pas de « Signaler » (aucun canal de report) | **Build** |
| Page 404 (H7) | Ajouter le numéral « 404 » + 2e CTA « Mes voyages » | **Build** |
| Raccourcis T/M (H9) | Ajouter les bindings + libellés | **Build** |
| Carte split partagée (H10) | Aligner sur l'éditeur (retirer `hidden lg:block`) | **Build** |
| Carte voyage (H8) | Sécuriser la suppression (menu ⋯ / confirmation) ; libellé jours/étapes et route ville→ville selon le data-model | **Build (partiel)** |
| Nav mobile (H11) | Hamburger mobile | Différé 35.4 (pas d'artboard mobile design) |
| Tokens couleur (H12) | Exposer tokens sémantiques + distinguer `--card`/`--background` | Différé 35.4 (refactor transverse) |
| Bannière cookies | Ne pas ajouter (Plausible sans cookie, justifié dans privacy) | Acté (divergence assumée) |
| Modale FAQ fusionnée | Onglet de la modale Aide | Acté (évite duplication) |
| Ton login | Garder l'anti-énumération ; ajouter le délai « 15 minutes » | **Build** |
