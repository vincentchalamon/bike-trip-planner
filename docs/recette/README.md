# Référentiel de recette

Spec commune à l'audit fonctionnel automatisé (Sprint 35.3) et à la recette manuelle. Décrit **ce que l'application doit faire** (écrans, éléments, comportements, états) indépendamment de la façon dont c'est testé.

Périmètre : features livrées sur `main` (sprints 1-33, design S25-27, IA S28-32, S34/34.5), **hors** S18 #313/#314 (abandonnés) et osm-cron nightly (#575).

## Livrables

| Doc | Ordre 35.1 | Contenu |
|---|---|---|
| [`01-inventaire-ecrans.md`](01-inventaire-ecrans.md) | 1 | Inventaire des écrans dérivé de `pwa/src/app/` + variantes auth/anon et états de données. |
| [`02-checklists-ecrans.md`](02-checklists-ecrans.md) | 2 | Checklist par écran : éléments, comportements, états (hover/focus/disabled/loading/empty/error), responsive, a11y clavier. |
| [`03-manifeste-elements.md`](03-manifeste-elements.md) | 3 | Manifeste d'éléments attendus par écran (présence + position approximative) dérivé de l'export Claude Design. |
| [`04-couverture-gherkin.md`](04-couverture-gherkin.md) | 4 | Audit de couverture Gherkin (`.feature` vs features réelles) + scénarios manquants à écrire. |

## Source design

L'export Claude Design (claude.ai/design) est vendoré sous [`design/`](design/) : `tokens.jsx`, `pages-*.jsx`, `modals.jsx`, `ui.jsx`, `ui2.jsx`, `toutes-les-pages.html`. Ce sont des prototypes HTML/CSS/JS, pas du code de production.

**Convention de comparaison app vs design :**

- **Présence + position approximative** des éléments => vérifiable automatiquement (Playwright, voir [`03-manifeste-elements.md`](03-manifeste-elements.md)).
- **Couleur, typo, polish** => regard humain (capture côte-à-côte pendant la recette manuelle).

## Conventions

- Routes auth : voir `pwa/src/components/auth-guard.tsx`. Trois classes : **public**, **requires-auth**, **anon-only**.
- États de données normalisés : `loading`, `empty`, `populated`, `error`, plus états métier (`computing`, `locked`, `no-dates`, `offline`).
- Breakpoints de référence : mobile **390px**, desktop **1280px** (base de l'export design).

## Traçabilité

Une ligne par écran de l'[inventaire](01-inventaire-ecrans.md), reliant les 4 livrables et le(s) sujet(s) `.feature` couvrant (Gherkin sous `pwa/tests/recette/features/`). `—` = trou de couverture identifié à l'[ordre 4](04-couverture-gherkin.md).

| Écran | Checklist | Manifeste | Sujet(s) `.feature` |
|---|---|---|---|
| `/` (landing anon) | 02 §1 | 03 Landing | — (landing à écrire) |
| `/` (planner auth) | 02 §1 | 03 Roadbook | trip-management, trip-creation |
| `/login` | 02 §2 | 03 Login | auth-security |
| `/trips` | 02 §3 | 03 Trips list | trip-management |
| `/trips/new` | 02 §4 | 03 Wizard 1/2/3 | trip-creation, configuration |
| `/trips/[id]` | 02 §5 | 03 Roadbook | stage-management, alerts-analysis, weather-time, accommodations, map-visualization, dates-calendar, export ; **IA à écrire** |
| `/s/[code]` | 02 §6 | 03 Vue partagée | sharing |
| `/account/settings` | 02 §7 | 03 Account settings | auth-security ; privacy (partiel) |
| `/access-requests/verify` | 02 §8 | 03 Access request | auth-security |
| `/auth/verify/[token]` | 02 §9 | 03 Auth verify | auth-security |
| `/faq` | 02 §10 | 03 FAQ | cross-cutting-ux (partiel) |
| `/legal` | 02 §11 | 03 Legal | — |
| `/privacy` | 02 §12 | 03 Privacy | — |
| 404 / 500 | 02 Écrans système | 03 Écrans système | edge-cases |
| Transverse (thème, langue, onboarding, offline) | 02 A11y + grilles | 03 modales / tokens | cross-cutting-ux, mobile-offline |

## Utilisation et critères de recette

Comment ce référentiel est consommé en aval :

- **Sprint 35.3 (automatisé)** : chaque écran de l'inventaire doit avoir un verdict. Le manifeste (ordre 3) alimente un test Playwright de présence + position ; les trous Gherkin (ordre 4) deviennent des `.feature` à écrire puis automatiser.
- **Recette manuelle** : la checklist (ordre 2) sert de script pas-à-pas ; la comparaison visuelle app vs design (couleur/typo/polish) se fait à l'oeil, capture côte-à-côte avec `design/`.

**Critère de passage par écran :** tous les éléments du manifeste présents et bien positionnés (auto) **ET** checklist sans point bloquant **ET** 0 violation a11y critique (`expectNoCriticalA11yViolations`). Un écart de polish visuel est un finding (issue milestone `Sprint 35.4`), pas un échec bloquant de recette.

## Niveau de confiance

- **Ordres 1-2 (inventaire, checklists)** : dérivés du code (`pwa/src/app/`, composants) — haute confiance.
- **Ordre 3 (manifeste)** : dérivé de l'export design (desktop 1280px). Libellés **verbatim FR** ; l'app réelle varie selon la locale, et les positions sont **approximatives par conception**.
- **Ordre 4 (couverture)** : compteurs de scénarios et parité FR/EN vérifiés ; la liste des manquants est une estimation de cadrage pour 35.3.
