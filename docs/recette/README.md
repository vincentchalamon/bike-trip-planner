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
