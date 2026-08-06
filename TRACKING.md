# Tracking

</details>

<details><summary>

## ✅ Sprint 1 — Quick Wins Alertes

</summary>
Backend pur, pattern `StageAnalyzerInterface` + `#[AutoconfigureTag]`. Reviews rapides (~100-150 lignes/PR).

| Ordre | ID                                                                    | Titre                                 | Effort | PRs                                                                                | Dépend de |
|-------|-----------------------------------------------------------------------|---------------------------------------|--------|------------------------------------------------------------------------------------|-----------|
| 1     | [#88](https://github.com/vincentchalamon/bike-trip-planner/issues/88) | Alerte calendrier : dimanches         | S      | [#110](https://github.com/vincentchalamon/bike-trip-planner/pull/110) `feature/88` | —         |
| 2     | [#63](https://github.com/vincentchalamon/bike-trip-planner/issues/63) | Détection des pentes raides           | S      | [#111](https://github.com/vincentchalamon/bike-trip-planner/pull/111) `feature/63` | —         |
| 3     | [#66](https://github.com/vincentchalamon/bike-trip-planner/issues/66) | Détecter les points de charge VAE     | S      | [#112](https://github.com/vincentchalamon/bike-trip-planner/pull/112) `feature/66` | —         |
| 4     | [#58](https://github.com/vincentchalamon/bike-trip-planner/issues/58) | Détection des points d'eau            | M      | [#116](https://github.com/vincentchalamon/bike-trip-planner/pull/116) `feature/58` | —         |
| 5     | [#54](https://github.com/vincentchalamon/bike-trip-planner/issues/54) | Correction des dénivelés sous-estimés | M      | [#117](https://github.com/vincentchalamon/bike-trip-planner/pull/117) `feature/54` | —         |

### Recette Sprint 1

- **Tests E2E :** `tests/recette/sprint-01.spec.ts`
- **Checklist manuelle :**
  - [x] Alerte dimanche visible sur une étape tombant un dimanche
  - [ ] Alerte pente raide visible sur une étape avec forte pente (en attente de trace de test)
  - [x] Alerte point de charge VAE visible (si VAE activé)
  - [x] Points d'eau détectés et affichés par étape
  - [x] Dénivelés corrigés cohérents avec la trace GPX

</details>

<details><summary>

## ✅ Sprint 2 — Alertes Frontend + UX Feedback

</summary>
| Ordre | ID                                                                    | Titre                                   | Effort | PRs                                                                                | Dépend de |
|-------|-----------------------------------------------------------------------|-----------------------------------------|--------|------------------------------------------------------------------------------------|-----------|
| 1     | [#28](https://github.com/vincentchalamon/bike-trip-planner/issues/28) | Résumer les suggestions et détections   | S      | [#160](https://github.com/vincentchalamon/bike-trip-planner/pull/160) `feature/28` | —         |
| 2     | [#41](https://github.com/vincentchalamon/bike-trip-planner/issues/41) | Badge de difficulté avec jauge visuelle | S      | [#161](https://github.com/vincentchalamon/bike-trip-planner/pull/161) `feature/41` | —         |
| 3     | [#40](https://github.com/vincentchalamon/bike-trip-planner/issues/40) | Barre de progression segmentée          | M      | [#162](https://github.com/vincentchalamon/bike-trip-planner/pull/162) `feature/40` | —         |

### Recette Sprint 2

- **Tests E2E :** `tests/recette/sprint-02.spec.ts`
- **Checklist manuelle :**
  - [ ] ~~Résumé des alertes visible dans le panneau trip~~
  - [x] Badge de difficulté avec jauge colorée par étape
  - [x] Barre de progression reflétant l'avancement du trip

</details>

<details><summary>

## ✅ Sprint 3 — Hébergements

</summary>
| Ordre | ID                                                                    | Titre                         | Effort | PRs                                                                                | Dépend de |
|-------|-----------------------------------------------------------------------|-------------------------------|--------|------------------------------------------------------------------------------------|-----------|
| 1     | [#38](https://github.com/vincentchalamon/bike-trip-planner/issues/38) | Distance hébergement-endPoint | S      | [#167](https://github.com/vincentchalamon/bike-trip-planner/pull/167) `feature/38` | —         |
| 2     | [#37](https://github.com/vincentchalamon/bike-trip-planner/issues/37) | Rayon de recherche            | M      | [#165](https://github.com/vincentchalamon/bike-trip-planner/pull/165) `feature/37` | —         |
| 3     | [#39](https://github.com/vincentchalamon/bike-trip-planner/issues/39) | Sélectionner un hébergement   | L      | [#166](https://github.com/vincentchalamon/bike-trip-planner/pull/166) `feature/39` | —         |

### Recette Sprint 3

- **Tests E2E :** `tests/recette/sprint-03.spec.ts`
- **Checklist manuelle :**
  - [x] Distance hébergement-endPoint affichée
  - [x] Modification du rayon de recherche + résultats mis à jour
  - [x] Sélection d'un hébergement → recalcul itinéraire (endPoint + startPoint étape suivante)
  - [x] Responsive : vérifier sur mobile

</details>

<details><summary>

## ✅ Sprint 4 — Configuration & Profil

</summary>
| Ordre | ID                                                                    | Titre                           | Effort | PRs                                                                                | Dépend de                                                             |
|-------|-----------------------------------------------------------------------|---------------------------------|--------|------------------------------------------------------------------------------------|-----------------------------------------------------------------------|
| 1     | [#48](https://github.com/vincentchalamon/bike-trip-planner/issues/48) | Profil cyclo + presets          | M      | [#170](https://github.com/vincentchalamon/bike-trip-planner/pull/170) `feature/48` | —                                                                     |
| 2     | [#49](https://github.com/vincentchalamon/bike-trip-planner/issues/49) | Panneau configuration (sidebar) | M      | [#172](https://github.com/vincentchalamon/bike-trip-planner/pull/172) `feature/49` | [#48](https://github.com/vincentchalamon/bike-trip-planner/issues/48) |
| 3     | [#36](https://github.com/vincentchalamon/bike-trip-planner/issues/36) | Filtre types d'hébergements     | M      | [#173](https://github.com/vincentchalamon/bike-trip-planner/pull/173) `feature/36` | [#49](https://github.com/vincentchalamon/bike-trip-planner/issues/49) |
| 4     | [#55](https://github.com/vincentchalamon/bike-trip-planner/issues/55) | Insertion jours de repos        | M      | [#171](https://github.com/vincentchalamon/bike-trip-planner/pull/171) `feature/55` | —                                                                     |

### Recette Sprint 4

- **Tests E2E :** `tests/recette/sprint-04.spec.ts`
- **Checklist manuelle :**
  - [x] Presets cyclo sélectionnables (sportif, touring, etc.)
  - [x] Panneau de configuration accessible et fonctionnel
  - [x] Filtrage par type d'hébergement dans le panneau de configuration
  - [x] Insertion d'un jour de repos → recalcul des étapes suivantes
  - [x] Responsive : sidebar sur mobile

</details>

<details><summary>

## ✅ Sprint 5 — Météo & Temps

</summary>
| Ordre | ID                                                                    | Titre                             | Effort | PRs                                                                                | Dépend de                                                             |
|-------|-----------------------------------------------------------------------|-----------------------------------|--------|------------------------------------------------------------------------------------|-----------------------------------------------------------------------|
| 1     | [#43](https://github.com/vincentchalamon/bike-trip-planner/issues/43) | Météo étendue vent + confort      | L      | [#177](https://github.com/vincentchalamon/bike-trip-planner/pull/177) `feature/43` | —                                                                     |
| 2     | [#61](https://github.com/vincentchalamon/bike-trip-planner/issues/61) | Estimation temps de parcours      | M      | [#178](https://github.com/vincentchalamon/bike-trip-planner/pull/178) `feature/61` | [#48](https://github.com/vincentchalamon/bike-trip-planner/issues/48) |
| 3     | [#62](https://github.com/vincentchalamon/bike-trip-planner/issues/62) | Horaires soleil + alerte nocturne | M      | [#179](https://github.com/vincentchalamon/bike-trip-planner/pull/179) `feature/62` | [#61](https://github.com/vincentchalamon/bike-trip-planner/issues/61) |

### Recette Sprint 5

- **Tests E2E :** `tests/recette/sprint-05.spec.ts`
- **Checklist manuelle :**
  - [x] Vent relatif (face/dos) affiché par étape
  - [x] Indice de confort cycliste visible
  - [x] Estimation du temps de parcours cohérente avec le profil cyclo
  - [x] Horaires lever/coucher de soleil affichés
  - [x] Alerte arrivée nocturne si applicable

</details>

<details><summary>

## ✅ Sprint 6 — Export (pré-auth)

</summary>
| Ordre | ID                                                                    | Titre                     | Effort | PRs                                                                                                                                                                                      | Dépend de |
|-------|-----------------------------------------------------------------------|---------------------------|--------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------|
| 1     | [#47](https://github.com/vincentchalamon/bike-trip-planner/issues/47) | Exporter au format texte  | S      | [#184](https://github.com/vincentchalamon/bike-trip-planner/pull/184) `feature/47`, [#186](https://github.com/vincentchalamon/bike-trip-planner/pull/186) `feature/export-display-fixes` | —         |
| 2     | [#64](https://github.com/vincentchalamon/bike-trip-planner/issues/64) | Téléchargement GPX global | S      | [#182](https://github.com/vincentchalamon/bike-trip-planner/pull/182) `feature/64`, [#186](https://github.com/vincentchalamon/bike-trip-planner/pull/186) `feature/export-display-fixes` | —         |
| 3     | [#59](https://github.com/vincentchalamon/bike-trip-planner/issues/59) | Budget récapitulatif      | S      | [#185](https://github.com/vincentchalamon/bike-trip-planner/pull/185) `feature/59`, [#186](https://github.com/vincentchalamon/bike-trip-planner/pull/186) `feature/export-display-fixes` | —         |

### Recette Sprint 6

- **Tests E2E :** `tests/recette/sprint-06.spec.ts`
- **Checklist manuelle :**
  - [x] Export texte complet et formaté
  - [x] Téléchargement GPX global fonctionnel
  - [x] Budget récapitulatif avec totaux cohérents

</details>

<details><summary>

## ✅ Sprint 7 — Carte Interactive

</summary>
| Ordre | ID                                                                    | Titre                                   | Effort | PRs                                                                                | Dépend de                                                             |
|-------|-----------------------------------------------------------------------|-----------------------------------------|--------|------------------------------------------------------------------------------------|-----------------------------------------------------------------------|
| 1     | [#30](https://github.com/vincentchalamon/bike-trip-planner/issues/30) | Carte interactive + profil altimétrique | XL     | [#187](https://github.com/vincentchalamon/bike-trip-planner/pull/187) `feature/30` | —                                                                     |
| 2     | [#31](https://github.com/vincentchalamon/bike-trip-planner/issues/31) | Split view carte / timeline             | M      | [#190](https://github.com/vincentchalamon/bike-trip-planner/pull/190) `feature/31` | [#30](https://github.com/vincentchalamon/bike-trip-planner/issues/30) |
| 3     | [#34](https://github.com/vincentchalamon/bike-trip-planner/issues/34) | Timeline ravitaillement                 | L      | [#189](https://github.com/vincentchalamon/bike-trip-planner/pull/189) `feature/34` | [#58](https://github.com/vincentchalamon/bike-trip-planner/issues/58) |
| 4     | [#35](https://github.com/vincentchalamon/bike-trip-planner/issues/35) | Points d'intérêt culturels              | M      | [#188](https://github.com/vincentchalamon/bike-trip-planner/pull/188) `feature/35` | —                                                                     |

### Recette Sprint 7

- **Tests E2E :** `tests/recette/sprint-07.spec.ts`
- **Checklist manuelle :**
  - [x] Carte avec tracé coloré par étape
  - [x] Profil altimétrique interactif (survol → curseur sur carte)
  - [x] Synchronisation carte ↔ timeline
  - [x] Split view fonctionnel
  - [x] Timeline ravitaillement avec POI le long du tracé
  - [x] Mode sombre : tuiles sombres
  - [x] Responsive : carte sur mobile (tactile)

</details>

<details><summary>

## ✅ Sprint 8 — UX & Onboarding

</summary>
| Ordre | ID                                                                    | Titre                     | Effort | PRs                                                                                                                                                                      | Dépend de                                                             |
|-------|-----------------------------------------------------------------------|---------------------------|--------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------|
| 1     | [#32](https://github.com/vincentchalamon/bike-trip-planner/issues/32) | Onboarding guide          | S      | [#200](https://github.com/vincentchalamon/bike-trip-planner/pull/200) `feature/32`, [#203](https://github.com/vincentchalamon/bike-trip-planner/pull/203) `fix/sprint-8` | —                                                                     |
| 2     | [#57](https://github.com/vincentchalamon/bike-trip-planner/issues/57) | Undo/Redo                 | L      | [#201](https://github.com/vincentchalamon/bike-trip-planner/pull/201) `feature/57`                                                                                       | —                                                                     |
| 3     | [#33](https://github.com/vincentchalamon/bike-trip-planner/issues/33) | Raccourcis clavier + aide | M      | [#202](https://github.com/vincentchalamon/bike-trip-planner/pull/202) `feature/33`, [#203](https://github.com/vincentchalamon/bike-trip-planner/pull/203) `fix/sprint-8` | [#57](https://github.com/vincentchalamon/bike-trip-planner/issues/57) |

### Recette Sprint 8

- **Tests E2E :** `tests/recette/sprint-08.spec.ts`
- **Checklist manuelle :**
  - [x] Onboarding guide affiché au premier lancement
  - [x] Raccourcis clavier fonctionnels (Ctrl+Z, Ctrl+Y, etc.)
  - [x] Bouton aide affichant la liste des raccourcis
  - [x] Undo/Redo sur les actions clés (suppression étape, modification distance)

</details>

<details><summary>

## ✅ Sprint 9 — Sources de Routes & Infra Backend

</summary>
| Ordre | ID                                                                    | Titre                           | Effort | PRs                                                                                | Dépend de |
|-------|-----------------------------------------------------------------------|---------------------------------|--------|------------------------------------------------------------------------------------|-----------|
| 1     | [#60](https://github.com/vincentchalamon/bike-trip-planner/issues/60) | Sources routes supplémentaires  | L      | [#214](https://github.com/vincentchalamon/bike-trip-planner/pull/214) `feature/60` | —         |
| 2     | [#53](https://github.com/vincentchalamon/bike-trip-planner/issues/53) | Création trip via URL           | S      | [#213](https://github.com/vincentchalamon/bike-trip-planner/pull/213) `feature/53` | —         |
| 3     | [#46](https://github.com/vincentchalamon/bike-trip-planner/issues/46) | Invalidation messages Messenger | M      | [#215](https://github.com/vincentchalamon/bike-trip-planner/pull/215) `feature/46` | —         |

### Recette Sprint 9

- **Tests E2E :** `tests/recette/sprint-09.spec.ts`
- **Checklist manuelle :**
  - [x] Upload GPX direct fonctionnel (drag & drop)
  - [ ] Import depuis Strava/RideWithGPS (si implémenté) (en attente de trace de test)
  - [x] Création de trip via URL avec paramètre link
  - [ ] Invalidation Messenger : pas de messages orphelins (comment tester ?)

</details>

<details><summary>

## ✅ Sprint 10 — i18n & Documentation

</summary>
| Ordre | ID                                                                    | Titre                                | Effort | PRs                                                                                          | Dépend de                                                             |
|-------|-----------------------------------------------------------------------|--------------------------------------|--------|----------------------------------------------------------------------------------------------|-----------------------------------------------------------------------|
| 1     | [#44](https://github.com/vincentchalamon/bike-trip-planner/issues/44) | Support multi-langue (fr/en)         | L      | [#218](https://github.com/vincentchalamon/bike-trip-planner/pull/218) `feature/44`           | —                                                                     |
| 2     | [#70](https://github.com/vincentchalamon/bike-trip-planner/issues/70) | i18n client-side export statique     | S      | [#224](https://github.com/vincentchalamon/bike-trip-planner/pull/224) `feature/70`           | [#44](https://github.com/vincentchalamon/bike-trip-planner/issues/44) |
| 3     | [#26](https://github.com/vincentchalamon/bike-trip-planner/issues/26) | Traduire documentation en français   | S      | [#223](https://github.com/vincentchalamon/bike-trip-planner/pull/223) `feature/26-27`        | —                                                                     |
| 4     | [#27](https://github.com/vincentchalamon/bike-trip-planner/issues/27) | Améliorer présentation documentation | S      | [#223](https://github.com/vincentchalamon/bike-trip-planner/pull/223) `feature/26-27`        | —                                                                     |
| 5     | [#29](https://github.com/vincentchalamon/bike-trip-planner/issues/29) | Changer la licence                   | S      | [#96](https://github.com/vincentchalamon/bike-trip-planner/pull/96) `fix/29-agpl-v3-license` | —                                                                     |

### Recette Sprint 10

- **Tests E2E :** `tests/recette/sprint-10.spec.ts`
- **Checklist manuelle :**
  - [x] Switcher fr/en fonctionnel
  - [x] Tous les textes traduits (pas de clés i18n visibles)
  - [x] Export statique compatible i18n
  - [x] Documentation en français complète
  - [x] Licence mise à jour

</details>

<details><summary>

## ✅ Sprint 11 — Persistance

</summary>
| Ordre | ID                                                                    | Titre                        | Effort | PRs | Dépend de |
|-------|-----------------------------------------------------------------------|------------------------------|--------|-----|-----------|
| 1     | [#56](https://github.com/vincentchalamon/bike-trip-planner/issues/56) | Persistance BDD + fixtures   | XL     | 6   | —         |

### Sous-PRs

- [x] PR1: Doctrine entities + migrations
- [x] PR2: Repositories
- [x] PR3: Migration state providers
- [x] PR4: Migration state processors
- [x] PR5: Tests fonctionnels
- [x] PR6: Factories Foundry (Zenstruck Foundry) + fixtures dev

### Recette Sprint 11

- **Tests E2E :** `tests/recette/sprint-11.spec.ts`
- **Checklist manuelle :**
  - [x] Trips persistés en PostgreSQL
  - [x] Fermer le navigateur → rouvrir → trip retrouvé
  - [x] Migrations Doctrine appliquées sans erreur
  - [x] Performances acceptables (liste de trips, chargement d'un trip)
  - [x] Fixtures chargées sans erreur (`bin/console doctrine:fixtures:load`)

</details>

<details><summary>

## ✅ Sprint 12 — Gestion des Trips

</summary>
| Ordre | ID                                                                    | Titre                     | Effort | PRs | Dépend de                                                             |
|-------|-----------------------------------------------------------------------|---------------------------|--------|-----|-----------------------------------------------------------------------|
| 1     | [#50](https://github.com/vincentchalamon/bike-trip-planner/issues/50) | Liste des trips           | L      | [#233](https://github.com/vincentchalamon/bike-trip-planner/pull/233) | [#56](https://github.com/vincentchalamon/bike-trip-planner/issues/56) |
| 2     | [#45](https://github.com/vincentchalamon/bike-trip-planner/issues/45) | Duplication de trip       | M      | [#235](https://github.com/vincentchalamon/bike-trip-planner/pull/235) | [#56](https://github.com/vincentchalamon/bike-trip-planner/issues/56) |
| 3     | [#52](https://github.com/vincentchalamon/bike-trip-planner/issues/52) | Verrouillage trips passés | M      | [#234](https://github.com/vincentchalamon/bike-trip-planner/pull/234) | [#56](https://github.com/vincentchalamon/bike-trip-planner/issues/56) |

### Recette Sprint 12

- **Tests E2E :** `tests/recette/sprint-12.spec.ts`
- **Checklist manuelle :**
  - [x] Liste des trips paginée et filtrable
  - [x] Navigation liste → détail → retour
  - [x] Duplication de trip fonctionnelle
  - [x] Verrouillage automatique des trips passés (lecture seule)
  - [x] Responsive : liste sur mobile

</details>

<details><summary>

## ✅ Sprint 13 — Auth & Sécurité

</summary>
| Ordre | ID                                                                    | Titre                         | Effort | PRs                                                                                                                                                                                            | Dépend de                                                                                                                     |
|-------|-----------------------------------------------------------------------|-------------------------------|--------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------|
| 1     | [#75](https://github.com/vincentchalamon/bike-trip-planner/issues/75) | ADR auth passwordless         | S      | [#247](https://github.com/vincentchalamon/bike-trip-planner/pull/247) `feature/75`                                                                                                             | —                                                                                                                             |
| 2     | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76) | Auth backend JWT + magic link | L      | [#248](https://github.com/vincentchalamon/bike-trip-planner/pull/248) `feature/76`, [#254](https://github.com/vincentchalamon/bike-trip-planner/pull/254) `fix/auth-content-type`               | [#75](https://github.com/vincentchalamon/bike-trip-planner/issues/75), [#56](https://github.com/vincentchalamon/bike-trip-planner/issues/56) |
| 3     | [#79](https://github.com/vincentchalamon/bike-trip-planner/issues/79) | Frontend auth                 | M      | [#251](https://github.com/vincentchalamon/bike-trip-planner/pull/251) `feature/79`                                                                                                             | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76)                                                         |
| 4     | [#77](https://github.com/vincentchalamon/bike-trip-planner/issues/77) | Sécurisation endpoints        | M      | [#249](https://github.com/vincentchalamon/bike-trip-planner/pull/249) `feature/77`                                                                                                             | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76)                                                         |
| 5     | [#78](https://github.com/vincentchalamon/bike-trip-planner/issues/78) | Sécurisation Mercure          | M      | [#250](https://github.com/vincentchalamon/bike-trip-planner/pull/250) `feature/78`                                                                                                             | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76), [#77](https://github.com/vincentchalamon/bike-trip-planner/issues/77) |

### Recette Sprint 13

- **Tests E2E :** `tests/recette/sprint-13.spec.ts`
- **Checklist manuelle :**
  - [x] Flux magic link complet : demande → email → clic → connecté
  - [x] Token expiré/utilisé → message d'erreur clair
  - [x] Endpoints sécurisés (401 sans JWT)
  - [ ] Mercure : pas de fuite de données entre utilisateurs
  - [ ] Mobile : flux auth sur Capacitor

</details>

<details><summary>

## ✅ Sprint 14 — Partage

</summary>
| Ordre | ID                                                                    | Titre                      | Effort | PRs                                                                                | Dépend de                                                                                                                     |
|-------|-----------------------------------------------------------------------|----------------------------|--------|-------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------|
| 1     | [#80](https://github.com/vincentchalamon/bike-trip-planner/issues/80) | Partage trip lecture seule | M      | [#255](https://github.com/vincentchalamon/bike-trip-planner/pull/255) `feature/80`  | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76), [#77](https://github.com/vincentchalamon/bike-trip-planner/issues/77) |
| 2     | [#42](https://github.com/vincentchalamon/bike-trip-planner/issues/42) | Bouton Partager            | L      | [#256](https://github.com/vincentchalamon/bike-trip-planner/pull/256) `feature/42`  | [#80](https://github.com/vincentchalamon/bike-trip-planner/issues/80)                                                         |

### Recette Sprint 14

- **Tests E2E :** `tests/recette/sprint-14.spec.ts`
- **Checklist manuelle :**
  - [x] Partage en lecture seule fonctionnel (lien anonyme)
  - [x] Révocation du lien par le propriétaire
  - [x] Bouton Partager : infographie + texte + lien

</details>

<details><summary>

## ✅ Sprint 15 — Mobile

</summary>
| Ordre | ID                                                                    | Titre                  | Effort | PRs                                                                                | Dépend de                                                                                                                                                                                                                                                                                                           |
|-------|-----------------------------------------------------------------------|------------------------|--------|------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1     | [#74](https://github.com/vincentchalamon/bike-trip-planner/issues/74) | ADR mobile Capacitor   | S      | [#257](https://github.com/vincentchalamon/bike-trip-planner/pull/257) `feature/74` | —                                                                                                                                                                                                                                                                                                                   |
| 2     | [#71](https://github.com/vincentchalamon/bike-trip-planner/issues/71) | URL API direct backend | S      | [#258](https://github.com/vincentchalamon/bike-trip-planner/pull/258) `feature/71` | —                                                                                                                                                                                                                                                                                                                   |
| 3     | [#69](https://github.com/vincentchalamon/bike-trip-planner/issues/69) | Scaffolding Capacitor  | M      | [#259](https://github.com/vincentchalamon/bike-trip-planner/pull/259) `feature/69` | [#52](https://github.com/vincentchalamon/bike-trip-planner/issues/52), [#53](https://github.com/vincentchalamon/bike-trip-planner/issues/53)                                                                                                                                                                         |
| 4     | [#72](https://github.com/vincentchalamon/bike-trip-planner/issues/72) | Mode hors-ligne        | L      | [#260](https://github.com/vincentchalamon/bike-trip-planner/pull/260) `feature/72` | —                                                                                                                                                                                                                                                                                                                   |
| 5     | [#73](https://github.com/vincentchalamon/bike-trip-planner/issues/73) | CI APK Android         | M      | [#261](https://github.com/vincentchalamon/bike-trip-planner/pull/261) `feature/73` | —                                                                                                                                                                                                                                                                                                                   |
| 6     | [#51](https://github.com/vincentchalamon/bike-trip-planner/issues/51) | Consultation mobile    | XL     | —                                                                                  | [#69](https://github.com/vincentchalamon/bike-trip-planner/issues/69), [#70](https://github.com/vincentchalamon/bike-trip-planner/issues/70), [#71](https://github.com/vincentchalamon/bike-trip-planner/issues/71), [#72](https://github.com/vincentchalamon/bike-trip-planner/issues/72), [#73](https://github.com/vincentchalamon/bike-trip-planner/issues/73), [#74](https://github.com/vincentchalamon/bike-trip-planner/issues/74) |

### Recette Sprint 15

- **Tests E2E :** `tests/recette/sprint-15.spec.ts`
- **Checklist manuelle :**
  - [ ] APK installable sur Android
  - [ ] Mode hors-ligne : consultation des données en cache
  - [ ] Bannière offline/online
  - [ ] Navigation tactile fluide
  - [ ] Retour en ligne : rafraîchissement automatique
  - [ ] Test sur vrai appareil Android

</details>

<details><summary>

## ✅ Sprint 16 — Recette Globale

</summary>
Phase de recette approfondie couvrant l'ensemble des sprints 1 à 15 (desktop + mobile). Scénarios Gherkin bilingues (FR/EN), tests de performance, audits sécurité/a11y/SEO, et automatisation via `playwright-bdd`. Environnement iso-prod requis.

| Ordre | ID                                                                      | Titre                                           | Effort | PRs | Dépend de                                                               |
|-------|-------------------------------------------------------------------------|------------------------------------------------|--------|-----|-------------------------------------------------------------------------|
| 1     | [#240](https://github.com/vincentchalamon/bike-trip-planner/issues/240) | Rédiger les scénarios Gherkin (FR + EN)         | XL     | 1   | —                                                                       |
| 2     | [#241](https://github.com/vincentchalamon/bike-trip-planner/issues/241) | Configurer l'environnement iso-prod             | M      | 2   | —                                                                       |
| 3     | [#242](https://github.com/vincentchalamon/bike-trip-planner/issues/242) | Recette fonctionnelle desktop (Chrome + Firefox) | L      | —   | [#240](https://github.com/vincentchalamon/bike-trip-planner/issues/240), [#241](https://github.com/vincentchalamon/bike-trip-planner/issues/241) |
| 4     | [#243](https://github.com/vincentchalamon/bike-trip-planner/issues/243) | Recette fonctionnelle mobile (web + APK)        | L      | —   | [#240](https://github.com/vincentchalamon/bike-trip-planner/issues/240), [#241](https://github.com/vincentchalamon/bike-trip-planner/issues/241) |
| 5     | [#244](https://github.com/vincentchalamon/bike-trip-planner/issues/244) | Recette performance                             | L      | —   | [#241](https://github.com/vincentchalamon/bike-trip-planner/issues/241) |
| 6     | [#245](https://github.com/vincentchalamon/bike-trip-planner/issues/245) | Recette sécurité, accessibilité et SEO          | M      | —   | [#241](https://github.com/vincentchalamon/bike-trip-planner/issues/241) |
| 7     | [#246](https://github.com/vincentchalamon/bike-trip-planner/issues/246) | Automatiser les scénarios avec playwright-bdd   | XL     | 3   | [#240](https://github.com/vincentchalamon/bike-trip-planner/issues/240) |

### Recette Sprint 16

- **Scénarios Gherkin :** `tests/recette/features/*.{fr,en}.feature`
- **Checklist manuelle :**
  - [ ] 32 fichiers `.feature` rédigés et validés (16 domaines × 2 langues)
  - [ ] Environnement iso-prod fonctionnel (`make start-prod`)
  - [ ] Recette desktop OK (Chrome + Firefox, FR/EN, clair/sombre)
  - [ ] Recette mobile OK (Chrome Android + APK Capacitor)
  - [ ] Seuils de performance respectés (Lighthouse ≥ 80, LCP < 2.5s, CLS < 0.1)
  - [ ] Audit sécurité passé (pas de stack traces, CORS, CSP, HTTPS)
  - [ ] Lighthouse Accessibility ≥ 90, axe-core 0 violation critique
  - [ ] Lighthouse SEO ≥ 90
  - [ ] `make test-recette` exécute les scénarios automatisés
  - [ ] Tous les bugs trouvés reportés en issues

</details>

<details><summary>

## ✅ Sprint 17 — Performance pipeline async

</summary>
Optimisation du pipeline d'analyse : timeouts, batch Overpass, cache warming.

| Ordre | ID                                                                      | Titre                                              | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|----------------------------------------------------|--------|-----|-----------|
| 1     | [#277](https://github.com/vincentchalamon/bike-trip-planner/issues/277) | Réduire les timeouts de scraping d'hébergements    | S      | [#292](https://github.com/vincentchalamon/bike-trip-planner/pull/292) | —         |
| 2     | [#278](https://github.com/vincentchalamon/bike-trip-planner/issues/278) | Fusionner les requêtes Overpass per-stage en batch  | M      | [#293](https://github.com/vincentchalamon/bike-trip-planner/pull/293) | —         |
| 3     | [#279](https://github.com/vincentchalamon/bike-trip-planner/issues/279) | Vérifier et optimiser le cache warming ScanAllOsmData | M   | [#294](https://github.com/vincentchalamon/bike-trip-planner/pull/294) | —         |
| 4     | [#280](https://github.com/vincentchalamon/bike-trip-planner/issues/280) | Augmenter la limite d'upload GPX à 30 MB           | S      | [#295](https://github.com/vincentchalamon/bike-trip-planner/pull/295) | —         |

</details>

<details><summary>

## ✅ Sprint 18 — Alertes actionnables + nouvelles règles

</summary>
Champ `action` sur le modèle Alert, actions contextuelles sur les analyseurs existants, nouveaux handlers. Parallélisable avec sprint 17.

| Ordre | ID                                                                      | Titre                                                      | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|------------------------------------------------------------|--------|-----|-----------|
| 1     | [#281](https://github.com/vincentchalamon/bike-trip-planner/issues/281) | Ajouter le champ `action` au modèle Alert                  | M      | [#329](https://github.com/vincentchalamon/bike-trip-planner/pull/329) | —         |
| 2     | [#282](https://github.com/vincentchalamon/bike-trip-planner/issues/282) | Ajouter des actions contextuelles aux analyseurs existants  | L      | [#333](https://github.com/vincentchalamon/bike-trip-planner/pull/333) | #281      |
| 3     | [#283](https://github.com/vincentchalamon/bike-trip-planner/issues/283) | Nouvel analyseur : gare SNCF de secours (nudge)            | S      | [#330](https://github.com/vincentchalamon/bike-trip-planner/pull/330) | —         |
| 4     | [#284](https://github.com/vincentchalamon/bike-trip-planner/issues/284) | Nouvel analyseur : pharmacie/hôpital à proximité (nudge)   | S      | [#331](https://github.com/vincentchalamon/bike-trip-planner/pull/331) | —         |
| 5     | [#285](https://github.com/vincentchalamon/bike-trip-planner/issues/285) | Nouvel analyseur : passage frontière (nudge)               | M      | [#332](https://github.com/vincentchalamon/bike-trip-planner/pull/332) | —         |
| 6     | [#313](https://github.com/vincentchalamon/bike-trip-planner/issues/313) | ~~Nouvel analyseur : départ avant l'aube (warning)~~ — **abandonné** (non implémenté, fermé _not planned_) | S      |     | —         |
| 7     | [#314](https://github.com/vincentchalamon/bike-trip-planner/issues/314) | ~~Nouvel analyseur : traversée cours d'eau sans pont (nudge)~~ — **abandonné** (non implémenté, fermé _not planned_) | M      |     | —         |
| 8     | [#315](https://github.com/vincentchalamon/bike-trip-planner/issues/315) | ADR-TBD : alertes actionnables (action DTO, 4 types)       | S      |     | —         |

</details>

<details><summary>

## ✅ Sprint 19 — Landing page + accès anticipé

</summary>
Page d'accueil marketing, système d'accès anticipé (HMAC, throttling, CLI), page FAQ. ADR-029.

| Ordre | ID                                                                      | Titre                                                         | Effort | PRs                                                                                | Dépend de |
|-------|-------------------------------------------------------------------------|---------------------------------------------------------------|--------|------------------------------------------------------------------------------------|-----------|
| 1     | [#286](https://github.com/vincentchalamon/bike-trip-planner/issues/286) | Landing page : page d'accueil marketing (8 sections)          | L      | [#338](https://github.com/vincentchalamon/bike-trip-planner/pull/338) `feature/286` | —         |
| 2     | [#287](https://github.com/vincentchalamon/bike-trip-planner/issues/287) | Système d'accès anticipé : backend (entité, HMAC, throttling) | L      | [#337](https://github.com/vincentchalamon/bike-trip-planner/pull/337) `feature/287` | —         |
| 3     | [#288](https://github.com/vincentchalamon/bike-trip-planner/issues/288) | Système d'accès anticipé : frontend (formulaire, login)       | M      | [#341](https://github.com/vincentchalamon/bike-trip-planner/pull/341) `feature/288` | #287      |
| 4     | [#289](https://github.com/vincentchalamon/bike-trip-planner/issues/289) | Page FAQ : différenciation et questions fréquentes            | S      | [#340](https://github.com/vincentchalamon/bike-trip-planner/pull/340) `feature/289` | —         |
| 5     | [#316](https://github.com/vincentchalamon/bike-trip-planner/issues/316) | ADR-029 : système d'accès anticipé (HMAC, throttling, CLI)   | S      | [#336](https://github.com/vincentchalamon/bike-trip-planner/pull/336) `feature/316` | —         |

</details>

<details><summary>

## ✅ Sprint 20 — Sources de données enrichies (DataTourisme + Wikidata)

</summary>
Intégration multi-sources : DataTourisme (hébergements, POI culturels, événements datés) en complément d'OSM, Wikidata en enrichisseur transversal (descriptions multilingues, images, horaires) via SPARQL batch, marchés forains data.gouv.fr pour les événements récurrents. Architecture extensible via interfaces + registries auto-discovered. ADR-025.

| Ordre | ID                                                                      | Titre                                                                          | Effort | PRs                                                                     | Dépend de      |
|-------|-------------------------------------------------------------------------|--------------------------------------------------------------------------------|--------|-------------------------------------------------------------------------|----------------|
| 1     | [#345](https://github.com/vincentchalamon/bike-trip-planner/issues/345) | Partie A — Enrichir requête OSM hébergements (wilderness_hut, shelter)         | S      | [#355](https://github.com/vincentchalamon/bike-trip-planner/pull/355)   | —              |
| 2     | [#346](https://github.com/vincentchalamon/bike-trip-planner/issues/346) | Partie B — Infrastructure DataTourisme (client, cache, rate limiter)           | M      | [#356](https://github.com/vincentchalamon/bike-trip-planner/pull/356)   | —              |
| 3     | [#347](https://github.com/vincentchalamon/bike-trip-planner/issues/347) | Partie C — Hébergements multi-sources (OSM + DataTourisme)                     | L      | [#357](https://github.com/vincentchalamon/bike-trip-planner/pull/357)   | #345 #346      |
| 4     | [#348](https://github.com/vincentchalamon/bike-trip-planner/issues/348) | Partie D — POI culturels multi-sources (horaires, prix, description)           | L      | [#358](https://github.com/vincentchalamon/bike-trip-planner/pull/358)   | #346           |
| 5     | [#349](https://github.com/vincentchalamon/bike-trip-planner/issues/349) | Partie E — Scan événements datés DataTourisme (festivals, expos)               | L      | [#359](https://github.com/vincentchalamon/bike-trip-planner/pull/359)   | #346           |
| 6     | [#350](https://github.com/vincentchalamon/bike-trip-planner/issues/350) | Partie G — Wikidata enricher transversal (SPARQL batch)                        | L      | [#360](https://github.com/vincentchalamon/bike-trip-planner/pull/360)   | #347 #348 #349 |
| 7     | [#351](https://github.com/vincentchalamon/bike-trip-planner/issues/351) | Partie H — Import marchés forains data.gouv.fr                                 | M      | [#361](https://github.com/vincentchalamon/bike-trip-planner/pull/361)   | #349           |
| 8     | [#352](https://github.com/vincentchalamon/bike-trip-planner/issues/352) | Partie F — Documentation & attribution globale (ADR-025)                       | S      | [#354](https://github.com/vincentchalamon/bike-trip-planner/pull/354)   | #345..#351     |

</details>

<details><summary>

## ✅ Sprint 21 — Stepper + Refonte du flux

</summary>
Composant Stepper navigation 4 actes, liste des voyages avec statuts, ADR-026 (pipeline 2 phases).

| Ordre | ID                                                                      | Titre                                                                          | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|--------------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#319](https://github.com/vincentchalamon/bike-trip-planner/issues/319) | ADR-026 : gate mechanism et pipeline 2 phases (prévisualisation → analyse)     | S      | [#362](https://github.com/vincentchalamon/bike-trip-planner/pull/362) `feature/319` | —         |
| 2     | [#317](https://github.com/vincentchalamon/bike-trip-planner/issues/317) | Composant Stepper : navigation 4 étapes (Préparation → Aperçu → Analyse → MV) | M      | [#363](https://github.com/vincentchalamon/bike-trip-planner/pull/363) `feature/317` | —         |
| 3     | [#318](https://github.com/vincentchalamon/bike-trip-planner/issues/318) | Liste des voyages avec statuts + header "Mes voyages"                          | M      | [#364](https://github.com/vincentchalamon/bike-trip-planner/pull/364) `feature/318` | —         |

</details>

<details><summary>

## ✅ Sprint 22 — Acte 1 : Card Selection + Acte 1.5 : Aperçu

</summary>
Interface d'entrée de l'itinéraire (cartes Lien/GPX), écran de prévisualisation, et endpoint `POST /trips/{id}/analyze`.

| Ordre | ID                                                                      | Titre                                                                     | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|---------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#320](https://github.com/vincentchalamon/bike-trip-planner/issues/320) | Acte 1 — Card Selection : entrée mutuellement exclusive (Lien + GPX)      | L      | [#367](https://github.com/vincentchalamon/bike-trip-planner/pull/367) `feature/320` | #317      |
| 2     | [#321](https://github.com/vincentchalamon/bike-trip-planner/issues/321) | Acte 1.5 — Écran Aperçu : prévisualisation avant analyse                 | M      | [#368](https://github.com/vincentchalamon/bike-trip-planner/pull/368) `feature/321` | #317 #320 |
| 3     | [#322](https://github.com/vincentchalamon/bike-trip-planner/issues/322) | Endpoint `POST /trips/{id}/analyze` : découplage prévisualisation/analyse | M      | [#366](https://github.com/vincentchalamon/bike-trip-planner/pull/366) `feature/322` | —         |

</details>

<details><summary>

## ✅ Sprint 23 — Acte 2 + Acte 3 : progression et résultats

</summary>
Écran de progression narrative (Acte 2), events Mercure restructurés, refonte résultats avec alertes repliables (Acte 3).

| Ordre | ID                                                                      | Titre                                                                           | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|---------------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#324](https://github.com/vincentchalamon/bike-trip-planner/issues/324) | Events Mercure dual mode : computation_step_completed + TRIP_READY + STAGE_UPDATED | L   | [#376](https://github.com/vincentchalamon/bike-trip-planner/pull/376) `feature/324` | #322      |
| 2     | [#323](https://github.com/vincentchalamon/bike-trip-planner/issues/323) | Acte 2 — ProcessingProgress : écran de progression narrative par catégorie      | L      | [#377](https://github.com/vincentchalamon/bike-trip-planner/pull/377) `feature/323` | #317 #324 |
| 3     | [#325](https://github.com/vincentchalamon/bike-trip-planner/issues/325) | Acte 3 — Refonte résultats : alertes repliables + affichage structuré           | L      | [#378](https://github.com/vincentchalamon/bike-trip-planner/pull/378) `feature/325` | #323 #324 |

</details>

<details><summary>

## ✅ Sprint 24 — UX avancé : recomputation inline + batch mode

</summary>
Shimmer/skeleton sur les étapes en recalcul, batch mode (ModificationQueue), diff post-recalcul. **Note** : implémenter avec variables CSS uniquement (pas de couleurs hardcodées) — les tokens seront remappés par le sprint 25 (Design Foundations).

| Ordre | ID                                                                      | Titre                                                                     | Effort | PRs                                                                                | Dépend de |
|-------|-------------------------------------------------------------------------|---------------------------------------------------------------------------|--------|------------------------------------------------------------------------------------|-----------|
| 1     | [#326](https://github.com/vincentchalamon/bike-trip-planner/issues/326) | Recomputation inline : shimmer/skeleton + barre de progression discrète   | L      | [#380](https://github.com/vincentchalamon/bike-trip-planner/pull/380) `feature/326` | #324 #325 |
| 2     | [#327](https://github.com/vincentchalamon/bike-trip-planner/issues/327) | Batch mode : ModificationQueue (accumulation + recalcul unique)           | L      | [#382](https://github.com/vincentchalamon/bike-trip-planner/pull/382) `feature/327` | #326      |
| 3     | [#328](https://github.com/vincentchalamon/bike-trip-planner/issues/328) | Diff post-recalcul : surbrillance des changements après recomputation     | M      | [#381](https://github.com/vincentchalamon/bike-trip-planner/pull/381) `feature/328` | #326      |

</details>

<details><summary>

## ✅ Sprint 25 — Design Foundations (issue #375 §1, §6, §10)

</summary>
Fondations du nouveau design system (palette ambre, typographies, tokens spacing/radius/shadow, pages d'erreur stylisées, pictogrammes unifiés). Consommé par tout le reste de la roadmap. Issues à créer comme sous-issues de [#375](https://github.com/vincentchalamon/bike-trip-planner/issues/375).

| Ordre | ID                                                                      | Titre                                                                                                                | Effort | PRs                                                                                | Dépend de |
|-------|-------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------|--------|------------------------------------------------------------------------------------|-----------|
| 1     | [#386](https://github.com/vincentchalamon/bike-trip-planner/issues/386) | Palette ambre `#c2671e` + tokens warm paper / ink charcoal dans `globals.css` (variantes forest/indigo/brick swap)   | M      | [#408](https://github.com/vincentchalamon/bike-trip-planner/pull/408) `feature/386` | —         |
| 2     | [#387](https://github.com/vincentchalamon/bike-trip-planner/issues/387) | Charger Fraunces + Inter Tight + JetBrains Mono via `next/font` ; remplacer `--font-geist-*`                         | S      | [#409](https://github.com/vincentchalamon/bike-trip-planner/pull/409) `feature/387` | —         |
| 3     | [#388](https://github.com/vincentchalamon/bike-trip-planner/issues/388) | Étendre échelles spacing (6/8/12/16/22/28/36/48/64) / radius (6/8/10/14/16) / shadow dans `@theme`                   | S      | [#410](https://github.com/vincentchalamon/bike-trip-planner/pull/410) `feature/388` | —         |
| 4     | [#389](https://github.com/vincentchalamon/bike-trip-planner/issues/389) | Restyle `not-found.tsx`, `error.tsx`, `global-error.tsx` (404 « Hors-piste », 500 « Caillou dans le dérailleur »)    | M      | [#411](https://github.com/vincentchalamon/bike-trip-planner/pull/411) `feature/389` | #386      |
| 5     | [#390](https://github.com/vincentchalamon/bike-trip-planner/issues/390) | Système de pictogrammes unifié (12 catégories) + légende visuelle consultable dans la FAQ                            | L      | [#412](https://github.com/vincentchalamon/bike-trip-planner/pull/412) `feature/390` | #386      |

</details>

<details><summary>

## ✅ Sprint 26 — Refonte Roadbook + Wizard 4 étapes (issue #375 §2, §3, §9)

</summary>
Cœur du redesign produit : wizard 4 étapes pour `/trips/new`, refonte du roadbook en master/detail, toggle Carte/Satellite, popover POI culturel enrichi.

| Ordre | ID                                                                      | Titre                                                                                                                              | Effort | PRs | Dépend de       |
|-------|-------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------|--------|-----|-----------------|
| 1     | [#391](https://github.com/vincentchalamon/bike-trip-planner/issues/391) | Wizard 4 étapes `/trips/new` (Préparation → Aperçu → Analyse → Mon voyage) + `WizardStepper` desktop + écran Analyse narratif      | XL     | [#414](https://github.com/vincentchalamon/bike-trip-planner/pull/414) `feature/391` | sprint 25       |
| 2     | [#392](https://github.com/vincentchalamon/bike-trip-planner/issues/392) | Étape 1 — chat Assistant IA multi-tours (text area + historique scrollable + « Valider et continuer »)                             | M      | [#417](https://github.com/vincentchalamon/bike-trip-planner/pull/417) `feature/392` | #391            |
| 3     | [#393](https://github.com/vincentchalamon/bike-trip-planner/issues/393) | Étape 2 — IA refinement single-shot (text area + boutons Effacer/Appliquer)                                                        | S      | [#418](https://github.com/vincentchalamon/bike-trip-planner/pull/418) `feature/393` | #391            |
| 4     | [#394](https://github.com/vincentchalamon/bike-trip-planner/issues/394) | Roadbook master/detail : sidebar gauche (timeline verticale) + panneau droit (détail étape) — refonte de `Timeline`                | XL     | [#415](https://github.com/vincentchalamon/bike-trip-planner/pull/415) `feature/394` | sprint 25       |
| 5     | [#395](https://github.com/vincentchalamon/bike-trip-planner/issues/395) | Réorganisation panneau droit : résumé IA → stats 4-col distance éditable → difficulty gauge → weather → alertes → events → héberg. | L      | [#419](https://github.com/vincentchalamon/bike-trip-planner/pull/419) `feature/395` | #394            |
| 6     | [#396](https://github.com/vincentchalamon/bike-trip-planner/issues/396) | Toggle Carte / Satellite (Leaflet multi-providers : OSM standard + Esri satellite ou équivalent)                                   | M      | [#420](https://github.com/vincentchalamon/bike-trip-planner/pull/420) `feature/396` | #394            |
| 7     | [#397](https://github.com/vincentchalamon/bike-trip-planner/issues/397) | Alertes regroupées par sévérité avec chevron + compteurs ; dots remplacés par boutons d'actions contextuelles                      | M      | [#421](https://github.com/vincentchalamon/bike-trip-planner/pull/421) `feature/397` | #394 #281       |
| 8     | [#398](https://github.com/vincentchalamon/bike-trip-planner/issues/398) | Popover POI culturel enrichi (pulsation 2s loop + 2 variantes Wikidata/DataTourisme vs OSM)                                        | L      | [#416](https://github.com/vincentchalamon/bike-trip-planner/pull/416) `feature/398` | #348            |

</details>

<details><summary>

## ✅ Sprint 27 — Reste du design (issue #375 §4, §5, §7, §8, §11, §12)

</summary>
Refonte des écrans restants : `/trips`, landing, auth, états UX transverses, vue partagée, infographie PNG.

| Ordre | ID                                                                      | Titre                                                                                                                          | Effort | PRs | Dépend de   |
|-------|-------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------|--------|-----|-------------|
| 1     | [#399](https://github.com/vincentchalamon/bike-trip-planner/issues/399) | Refonte `/trips` : grille 2 colonnes avec mini-map par voyage (polylines décimées) + états vides stylisés                      | M      | [#426](https://github.com/vincentchalamon/bike-trip-planner/pull/426) `feature/399` | sprint 25   |
| 2     | [#400](https://github.com/vincentchalamon/bike-trip-planner/issues/400) | Refonte landing bento-grid : hero cinématique + how-it-works + 9 cards features + sources + plateformes + témoignages + CTA   | XL     | [#427](https://github.com/vincentchalamon/bike-trip-planner/pull/427) `feature/400` | sprint 25   |
| 3     | [#401](https://github.com/vincentchalamon/bike-trip-planner/issues/401) | Restyle `/login` + `/auth/verify/[token]` + `/access-requests/verify` + 3 états magic link + validation inline                  | M      | [#428](https://github.com/vincentchalamon/bike-trip-planner/pull/428) `feature/401` | sprint 25   |
| 4     | [#402](https://github.com/vincentchalamon/bike-trip-planner/issues/402) | États UX transverses : empty states, skeletons, états d'erreur, GPX drop zone 4 états, chip détection source, modale destructive | L    | [#429](https://github.com/vincentchalamon/bike-trip-planner/pull/429) `feature/402` | sprint 25   |
| 5     | [#403](https://github.com/vincentchalamon/bike-trip-planner/issues/403) | Enrichissement données : répartition surfaces par étape + lever/coucher soleil dans `WeatherIndicator`                          | M      | [#430](https://github.com/vincentchalamon/bike-trip-planner/pull/430) `feature/403` | sprint 25   |
| 6     | [#404](https://github.com/vincentchalamon/bike-trip-planner/issues/404) | Vue partagée `/s/[code]` : bandeau lecture seule, top bar simplifiée, retrait contrôles d'édition                              | M      | [#431](https://github.com/vincentchalamon/bike-trip-planner/pull/431) `feature/404` | sprint 25   |
| 7     | [#405](https://github.com/vincentchalamon/bike-trip-planner/issues/405) | Template infographie PNG 1080×1080 (titre + mini-map + stats globales + résumé étapes)                                          | M      | [#432](https://github.com/vincentchalamon/bike-trip-planner/pull/432) `feature/405` | sprint 25   |

</details>

<details><summary>

## ✅ Sprint 28 — Intégration Ollama : fondations backend

</summary>
Service OllamaClient PHP, configuration Docker Ollama, gate mechanism dans ComputationTracker, system prompts cyclotourisme versionnés. ADR-028.

| Ordre | ID                                                                      | Titre                                                              | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|--------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#297](https://github.com/vincentchalamon/bike-trip-planner/issues/297) | ADR-028 : architecture Ollama/LLaMA (2 passes, context)            | S      | [#435](https://github.com/vincentchalamon/bike-trip-planner/pull/435) `feature/297` | —         |
| 2     | [#298](https://github.com/vincentchalamon/bike-trip-planner/issues/298) | Service OllamaClient PHP + configuration Docker Ollama             | M      | [#437](https://github.com/vincentchalamon/bike-trip-planner/pull/437) `feature/298` | —         |
| 3     | [#299](https://github.com/vincentchalamon/bike-trip-planner/issues/299) | Gate mechanism dans ComputationTracker                             | M      | [#438](https://github.com/vincentchalamon/bike-trip-planner/pull/438) `feature/299` | —         |
| 4     | [#300](https://github.com/vincentchalamon/bike-trip-planner/issues/300) | System prompts cyclotourisme versionnés (LLaMA 8B)                 | S      | [#436](https://github.com/vincentchalamon/bike-trip-planner/pull/436) `feature/300` | —         |

</details>

<details><summary>

## ✅ Sprint 29 — LLaMA 8B : analyse 2 passes

</summary>
Pipeline d'analyse IA : passe 1 par étape (parallélisable via Messenger), passe 2 vue d'ensemble, orchestration gate → LLaMA → TRIP_READY. **Décision (Sprint 29) : Ollama est une dépendance dure** — pas de fallback gracieux (cf. issue #375 arbitrage v2 « IA toujours active »). Issue #304 fermée, puis **rouverte par l'audit Sprint 35.2** : la décision est ré-inversée en **mode dégradé explicite** (cf. ADR-028 « Decision Update — Degraded Mode » + #616) ; livrée (#304 mergé).

| Ordre | ID                                                                      | Titre                                                     | Effort | PRs                                                                                | Dépend de      |
|-------|-------------------------------------------------------------------------|-----------------------------------------------------------|--------|------------------------------------------------------------------------------------|----------------|
| 1     | [#301](https://github.com/vincentchalamon/bike-trip-planner/issues/301) | LLaMA 8B passe 1 : analyse par étape via Messenger        | L      | [#441](https://github.com/vincentchalamon/bike-trip-planner/pull/441) `feature/301` | #298 #299 #300 |
| 2     | [#302](https://github.com/vincentchalamon/bike-trip-planner/issues/302) | LLaMA 8B passe 2 : vue d'ensemble du trip                 | M      | [#442](https://github.com/vincentchalamon/bike-trip-planner/pull/442) `feature/302` | #301           |
| 3     | [#303](https://github.com/vincentchalamon/bike-trip-planner/issues/303) | Pipeline gate → LLaMA → TRIP_READY event Mercure          | M      | [#443](https://github.com/vincentchalamon/bike-trip-planner/pull/443) `feature/303` | #299 #301 #302 |

</details>

<details><summary>

## ✅ Sprint 30 — Frontend IA : résumés + affichage hybride

</summary>
Affichage des résumés IA (global + par étape), layout hybride résumé/alertes repliables. **Issues #307 et #308 fermées** (arbitrage v2 #375 : IA toujours active, pas de bandeau « Actualiser », pas de fallback frontend).

| Ordre | ID                                                                      | Titre                                                                | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|----------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#305](https://github.com/vincentchalamon/bike-trip-planner/issues/305) | Résumé IA global dans "Mon voyage" (passe 2)                         | M      | [#447](https://github.com/vincentchalamon/bike-trip-planner/pull/447) `feature/305` | #302      |
| 2     | [#306](https://github.com/vincentchalamon/bike-trip-planner/issues/306) | Résumé IA par étape + layout hybride (résumé + alertes repliables)   | L      | [#448](https://github.com/vincentchalamon/bike-trip-planner/pull/448) `feature/306` | #301 #305 |

</details>

<details><summary>

## ✅ Sprint 31 — Bulle IA (LLaMA 3B) : dialogue context-aware

</summary>
Assistant conversationnel via bulle flottante, LLaMA 3B pour interpréter les intentions, intégration avec la recomputation inline. Le composant `AiBubble` consomme les tokens design du sprint 25.

| Ordre | ID                                                                      | Titre                                                                            | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|----------------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#309](https://github.com/vincentchalamon/bike-trip-planner/issues/309) | System prompt dialogue LLaMA 3B + endpoint backend chat IA                       | M      | [#453](https://github.com/vincentchalamon/bike-trip-planner/pull/453) `feature/309` | #298      |
| 2     | [#310](https://github.com/vincentchalamon/bike-trip-planner/issues/310) | Composant AiBubble : bulle flottante + panneau chat                              | L      | [#454](https://github.com/vincentchalamon/bike-trip-planner/pull/454) `feature/310` | #309 sprint 25 |
| 3     | [#311](https://github.com/vincentchalamon/bike-trip-planner/issues/311) | Intégration bulle IA ↔ recomputation inline + skipAiAnalysis + toggle batch     | M      | [#455](https://github.com/vincentchalamon/bike-trip-planner/pull/455) `feature/311` | #309 #310 |

</details>

<details><summary>

## ✅ Sprint 32 — Chat in-ride : assistant POI à proximité avec détour

</summary>
Extension du chat LLaMA 3B (sprint 31) au cas d'usage **in-ride** : pendant un voyage, l'utilisateur consulte son trip et demande à l'assistant de trouver un POI proche (friterie, abri, eau, mécano…) pour gérer un imprévu (faim, pluie, panne). Le chat existant (`POST /trips/{id}/ai-chat`) détecte la présence d'une **position GPS** dans le payload pour basculer en mode in-ride : recherche Overpass + filtrage `opening_hours` + calcul approximatif du détour (Haversine + projection orthogonale) + deeplink Google Maps. Pas de recalcul GPX (V2 future) — l'itinéraire de base reste inchangé.

| Ordre | ID  | Titre                                                                                                                | Effort | PRs | Dépend de |
|-------|-----|----------------------------------------------------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#458](https://github.com/vincentchalamon/bike-trip-planner/issues/458) | Entité `TripChatMessage` + repository + migration Doctrine (persistance long-terme par trip)                         | M      | [#467](https://github.com/vincentchalamon/bike-trip-planner/pull/467) `feature/458` | sprint 31    |
| 2     | [#459](https://github.com/vincentchalamon/bike-trip-planner/issues/459) | Endpoint `GET /trips/{id}/ai-chat-history` : pagination de l'historique persisté                                        | S      | [#471](https://github.com/vincentchalamon/bike-trip-planner/pull/471) `feature/459` | #458         |
| 3     | [#460](https://github.com/vincentchalamon/bike-trip-planner/issues/460) | `OpeningHoursParser` PHP + benchmark lib tierce + tests cas OSM courants                                             | M      | [#468](https://github.com/vincentchalamon/bike-trip-planner/pull/468) `feature/460` | —            |
| 4     | [#461](https://github.com/vincentchalamon/bike-trip-planner/issues/461) | `DetourCalculator` : projection orthogonale POI → polyline restante + distance détour (Haversine)                    | M      | [#469](https://github.com/vincentchalamon/bike-trip-planner/pull/469) `feature/461` | —            |
| 5     | [#462](https://github.com/vincentchalamon/bike-trip-planner/issues/462) | `InRideAssistant` + `PoiIntentDetector` + `DeeplinkBuilder` + extensions `OsmOverpassQueryBuilder` (eau, abri)        | L      | [#472](https://github.com/vincentchalamon/bike-trip-planner/pull/472) `feature/462` | #460, #461   |
| 6     | [#463](https://github.com/vincentchalamon/bike-trip-planner/issues/463) | Branchement `TripChatProcessor` : détection mode in-ride par position, `ACTION_FIND_POI`, prompt système dédié        | M      | [#473](https://github.com/vincentchalamon/bike-trip-planner/pull/473) `feature/463` | #462         |
| 7     | [#464](https://github.com/vincentchalamon/bike-trip-planner/issues/464) | Hooks PWA `useGeolocation` (one-shot) + `useOnlineStatus` + badge offline sur bouton flottant                         | S      | [#470](https://github.com/vincentchalamon/bike-trip-planner/pull/470) `feature/464` | —            |
| 8     | [#465](https://github.com/vincentchalamon/bike-trip-planner/issues/465) | Composants PWA `PoiCard` + `InRideDisclaimer` + `ChatHistoryLoader` + i18n FR/EN + tests Playwright in-ride/offline   | L      | [#474](https://github.com/vincentchalamon/bike-trip-planner/pull/474) `feature/465` | #459, #463, #464 |

### Recette Sprint 32

- **Tests E2E :** `pwa/tests/e2e/chat-in-ride.spec.ts`, `pwa/tests/e2e/chat-offline.spec.ts`
- **Checklist manuelle :**
  - [ ] Mobile (iPhone 14 Pro emulator) : bouton flottant visible, drawer plein écran
  - [ ] Autoriser géoloc → top 3 POI affichés avec nom, distance, horaires, badge détour
  - [ ] Bouton « Ouvrir dans Google Maps » → deeplink `?api=1&travelmode=bicycling` avec coords correctes
  - [ ] Disclaimer « votre itinéraire de base n'est pas modifié » visible sous les cards
  - [ ] Filtre horaires : POI fermé ou fermeture < 1h est exclu ; POI sans `opening_hours` affiché avec avertissement
  - [ ] Persistance : refresh page → historique rechargé depuis `/ai-chat-history`
  - [ ] Refus géoloc → message clair, le chat planning continue de fonctionner
  - [ ] DevTools Offline → badge offline + bouton désactivé
  - [ ] Non-régression : les 7 actions planning existantes (split, merge, add waypoint, change accommodation, adjust distance, change route, info) marchent sans géoloc
  - [ ] PHPStan L9 OK, ESLint OK, Playwright OK
  - [ ] `make typegen` : types frontend cohérents avec `TripChatResponse` étendu

</details>

<details><summary>

## ✅ Sprint 33 — OSM Data Refresh quotidien

</summary>
Mise à jour automatique des cartes OSM consommées par Valhalla. Le provisioner devient une commande unifiée install/update auto-détectée selon l'état (`/data/regions.json`). Un service `osm-cron` intégré à Docker Compose orchestre nuit après nuit le re-téléchargement des PBF Geofabrik et le redémarrage de Valhalla pour rebuild des tuiles. Voir ADR-030.

| Ordre | ID                                                                      | Titre                                                                                                                                  | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#477](https://github.com/vincentchalamon/bike-trip-planner/issues/477) | Commande `provision` unifiée install/update + `RegionSelectionStore` + persistance `/data/regions.json`                                | M      | [#538](https://github.com/vincentchalamon/bike-trip-planner/pull/538) | —         |
| 2     | [#478](https://github.com/vincentchalamon/bike-trip-planner/issues/478) | `OsmDataDownloader` partagé : download atomique (`.tmp` + rename) + merge osmium + tests unitaires                                     | S      | [#540](https://github.com/vincentchalamon/bike-trip-planner/pull/540) | #477      |
| 3     | [#479](https://github.com/vincentchalamon/bike-trip-planner/issues/479) | Service `osm-cron` (Dockerfile docker:cli + supercronic + script orchestration) + intégration Compose dev/prod                         | M      | [#541](https://github.com/vincentchalamon/bike-trip-planner/pull/541) | #477      |
| 4     | [#480](https://github.com/vincentchalamon/bike-trip-planner/issues/480) | ADR-030 stratégie refresh OSM (re-download vs pyosmium-up-to-date, scheduler Compose vs crontab hôte) + README provisioning            | S      | [#539](https://github.com/vincentchalamon/bike-trip-planner/pull/539) | —         |

### Recette Sprint 33

- **Tests E2E :** N/A (infra) — couverture via tests unitaires PHPUnit + smoke test cron.
- **Checklist manuelle :**
  - [ ] Bootstrap fresh : `rm -rf .docker/osm/data/*` puis `make provision` → sélection Ile-de-France → vérifier `.docker/osm/data/regions/ile-de-france-latest.osm.pbf`, `.docker/osm/data/regions.json` créés, `default.osm.pbf` mergé
  - [ ] Update non-interactif : `docker compose run --rm provisioner php bin/provision --no-interaction` → mtime PBF récent + `default.osm.pbf` re-mergé
  - [ ] Update sans config : `rm .docker/osm/data/regions.json && make provision-update` → erreur claire « First run requires interactive setup »
  - [ ] Service cron : `docker compose --profile routing up -d osm-cron` avec `OSM_CRON_SCHEDULE="* * * * *"` → `docker logs osm-cron` montre provisioner OK + Valhalla redémarré
  - [ ] Reconstruction Valhalla : après restart, healthcheck `service_starting` puis `service_healthy` (< 10 min sur Ile-de-France), test `/route` Valhalla
  - [ ] PHPStan L9 + PHPUnit + `make qa` green

</details>

<details><summary>

## ✅ Sprint 34 — Analytics d'usage & conformité RGPD + parcours compte

</summary>
Collecte de métriques d'usage **agrégées et anonymes** (sources, plateformes, profil trips, valeur features, rétention/UX) via **Plausible Analytics** (privacy-first, RGPD-compatible, sans cookie ni empreinte navigateur), avec prérequis RGPD (privacy policy, mentions légales, anonymisation user). **Décision arbitrage v3 #375** : abandon de l'implémentation native (UsageEvent partitionnée + endpoint `/events` + vue matérialisée) au profit de Plausible — simplification majeure du sprint. Voir issue [#370](https://github.com/vincentchalamon/bike-trip-planner/issues/370) (épic). **Sprint élargi** avec 3 issues compte/top bar/cookies (cf. issue #375 §13, §14, §15).

| Ordre | ID                                                                      | Titre                                                                                                            | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#550](https://github.com/vincentchalamon/bike-trip-planner/issues/550) | Page `/privacy` + mentions légales `/legal` (sommaire sticky, footer global, mention Plausible)                  | M      | [#556](https://github.com/vincentchalamon/bike-trip-planner/pull/556) | sprint 25 |
| 2     | [#549](https://github.com/vincentchalamon/bike-trip-planner/issues/549) | Anonymisation/suppression user (soft-delete trips + préférences) + export données JSON ; events Plausible non liés à l'user | M      | [#555](https://github.com/vincentchalamon/bike-trip-planner/pull/555) | —         |
| 3     | [#548](https://github.com/vincentchalamon/bike-trip-planner/issues/548) | ADR-034 : décision Plausible auto-hébergé (justification RGPD, custom events)                                    | S      | [#554](https://github.com/vincentchalamon/bike-trip-planner/pull/554) | —         |
| 4     | [#551](https://github.com/vincentchalamon/bike-trip-planner/issues/551) | Setup Plausible auto-hébergé Docker + domaine + DNS — **tâche manuelle (ops), différée post-beta** (ADR-034 / #567)  | M      |     | #548      |
| 5     | [#552](https://github.com/vincentchalamon/bike-trip-planner/issues/552) | Intégration script Plausible dans `<head>` Next.js (data-domain, chargement conditionnel à la config env `NEXT_PUBLIC_PLAUSIBLE_DOMAIN` — pas de consentement, cf. ADR-034) | S      | [#557](https://github.com/vincentchalamon/bike-trip-planner/pull/557) | #551      |
| 6     | [#553](https://github.com/vincentchalamon/bike-trip-planner/issues/553) | Custom events Plausible — sources & plateformes (`import_komoot`, `import_strava`, `import_rwgps`, `import_gpx`) | S      | [#561](https://github.com/vincentchalamon/bike-trip-planner/pull/561) | #552      |
| 7     | [#553](https://github.com/vincentchalamon/bike-trip-planner/issues/553) | Custom events Plausible — valeur features & rétention/UX (`trip_created`, `trip_shared`, `accommodation_selected`, `alert_action_clicked`, `ai_chat_opened`…) — _fusionné dans #553_ | M      | [#561](https://github.com/vincentchalamon/bike-trip-planner/pull/561) | #552      |
| 8     | [#383](https://github.com/vincentchalamon/bike-trip-planner/issues/383) | Page `/account/settings` (Mon compte / Préférences / RGPD download / Zone de danger / Déconnexion)               | L      | [#558](https://github.com/vincentchalamon/bike-trip-planner/pull/558) | #550, #549 |
| 9     | [#384](https://github.com/vincentchalamon/bike-trip-planner/issues/384) | Refonte top bar desktop (logo + tabs + undo/redo + Partager + ? aide unifiée + pills FR\|EN + thème + profil)    | L      | [#559](https://github.com/vincentchalamon/bike-trip-planner/pull/559) | #383       |
| 10    | [#385](https://github.com/vincentchalamon/bike-trip-planner/issues/385) | ~~Bannière cookies + modale granularité~~ — **abandonné** : Plausible cookieless/sans PII, aucun consentement requis (cf. ADR-034) ; gating par env uniquement | M      | [#560](https://github.com/vincentchalamon/bike-trip-planner/pull/560) (fermée) | #550, #552 |

### Recette Sprint 34

- **Checklist manuelle :**
  - [ ] Page `/privacy` accessible et complète (base légale, conservation, droits utilisateurs, mention Plausible)
  - [ ] Mentions légales `/legal` accessibles
  - [ ] Page `/account/settings` accessible via le bouton profil de la top bar
  - [ ] Aucune bannière de consentement (Plausible cookieless/sans PII — aucun consentement requis, cf. ADR-034)
  - [ ] Script Plausible chargé **uniquement si `NEXT_PUBLIC_PLAUSIBLE_DOMAIN` est défini** ; dormant sinon (beta sans analytics) — vérifier DevTools `Network`
  - [ ] Lorsque configuré : page view trackée dans le dashboard Plausible
  - [ ] Custom events visibles dans le dashboard Plausible (sources d'import, trip_created, trip_shared, etc.)
  - [ ] Aucun cookie posé par Plausible (vérifier `document.cookie`)
  - [ ] Aucune IP, User-Agent brut ou coordonnées GPS dans les events Plausible (Plausible anonymise nativement)
  - [ ] Suppression de compte → trips et préférences purgés ; events Plausible historiques restent (anonymes par construction)
  - [ ] Documentation Plausible dans `/privacy` mentionne : cloud/auto-hébergé, finalités, rétention, droits

</details>

<details><summary>

## ✅ Sprint 34.5 — Right-sizing beta Free Tier (profil <10 users)

</summary>
Ajustement de l'infrastructure pour démarrer une **beta restreinte (<10 users)** sur Oracle Cloud Free Tier (24 GB / 4 cœurs ARM) à coût 0 €, **avant** le déploiement complet du sprint 37. Right-sizing du LLM (un seul `llama3.2:3b` on-demand au lieu de 8B+3B résidents), isolement de l'inférence (transport `llm` dédié), observabilité externalisée (Sentry SaaS + UptimeRobot au lieu de GlitchTip/Uptime Kuma auto-hébergés), analytics différée (gating env déjà en place via #552/#553), filets mémoire, et OSM **France entière** en build local mensuel. Couture endpoint LLM (#564) pour préparer le 8B dédié / la migration GCP. Voir ADR-035 (#570) et le plan d'analyse de capacité.

| Ordre | ID                                                                      | Titre                                                                              | Effort | PRs | Dépend de   |
|-------|-------------------------------------------------------------------------|------------------------------------------------------------------------------------|--------|-----|-------------|
| 1     | [#563](https://github.com/vincentchalamon/bike-trip-planner/issues/563) | Profil beta — modèle 3B unique (analyse + chat) + Ollama on-demand                 | S      | [#579](https://github.com/vincentchalamon/bike-trip-planner/pull/579) ✅ mergée | —           |
| 2     | [#564](https://github.com/vincentchalamon/bike-trip-planner/issues/564) | Couture endpoint analyse/chat (`OLLAMA_ANALYSIS_URL` / `OLLAMA_CHAT_URL`)           | M      | [#580](https://github.com/vincentchalamon/bike-trip-planner/pull/580) ✅ mergée | —           |
| 3     | [#565](https://github.com/vincentchalamon/bike-trip-planner/issues/565) | Transport `llm` dédié + `worker-llm` (split async/llm)                              | M      | [#581](https://github.com/vincentchalamon/bike-trip-planner/pull/581) ✅ mergée | —           |
| 4     | [#566](https://github.com/vincentchalamon/bike-trip-planner/issues/566) | Limites mémoire conteneurs + Redis maxmemory (noeviction) + Postgres borné         | S      | [#582](https://github.com/vincentchalamon/bike-trip-planner/pull/582) ✅ mergée (base #581) | #565        |
| 5     | [#567](https://github.com/vincentchalamon/bike-trip-planner/issues/567) | Beta sans analytics — gating env + différer le serveur + doc réactivation          | S      | [#583](https://github.com/vincentchalamon/bike-trip-planner/pull/583) ✅ mergée | #552, #553  |
| 6     | [#568](https://github.com/vincentchalamon/bike-trip-planner/issues/568) | Observabilité beta SaaS — Sentry + UptimeRobot                                      | S      | [#584](https://github.com/vincentchalamon/bike-trip-planner/pull/584) ✅ mergée | —           |
| 7     | [#569](https://github.com/vincentchalamon/bike-trip-planner/issues/569) | OSM France entière — build local mensuel + runbook + désactiver osm-cron nightly   | M      | [#575](https://github.com/vincentchalamon/bike-trip-planner/pull/575) + [#585](https://github.com/vincentchalamon/bike-trip-planner/pull/585) ✅ mergée | —           |
| 8     | [#570](https://github.com/vincentchalamon/bike-trip-planner/issues/570) | ADR-035 right-sizing Free Tier + correction budget ADR-019                         | S      | [#586](https://github.com/vincentchalamon/bike-trip-planner/pull/586) ✅ mergée (ADR-039) | —           |

### Recette Sprint 34.5

- **Checklist manuelle :**
  - [ ] `docker stats` iso-prod : baseline ~6-7 GB, pic ~9 GB pendant une analyse (Ollama ~0 au repos, ~2,3 GB en analyse)
  - [ ] `/api/healthz` vert ; `worker-llm` consomme `llm`, les `worker` consomment `async`
  - [ ] 5-10 imports rapprochés → API/SSR réactifs, pas d'OOM-kill (`dmesg`), files Messenger se vident
  - [ ] `NEXT_PUBLIC_PLAUSIBLE_DOMAIN` vide → 0 requête analytics, 0 cookie
  - [ ] Erreur backend/front visible dans Sentry SaaS ; monitor UptimeRobot vert sur `/api/healthz`
  - [ ] Tuiles OSM France servies (`/route` OK) ; runbook `docs/runbooks/valhalla-routing-graph.md` rejouable
  - [ ] `make qa` green

</details>

<details><summary>

## ✅ Sprint 35 — Outillage d'audit

</summary>
> **Restructuration** : l'ancien « Sprint 35 — Recette complète & Audit » (36 ordres, 4 phases) était trop gros et hétérogène (outillage codable vs audits produisant des findings vs recette manuelle vs corrections). Il est découpé en 5 sous-sprints séquencés : **35** (outillage) et **35.1** (référentiel) en parallèle, puis **35.2** (audit non-fonctionnel) et **35.3** (audit fonctionnel + couverture) en parallèle, puis recette manuelle, puis **35.4** (corrections). Périmètre audité = features livrées sur `main` (sprints 1-33, design S25-27, IA S28-32, S34/34.5), **hors** S18 #313/#314 (abandonnés) et osm-cron nightly (#575 : refresh OSM désormais manuel). L'état des issues GitHub étant peu fiable, les findings d'audit se regroupent par **milestone `Sprint 35.4`**, pas par état d'issue.

**✅ Sprint livré** — 6 items d'outillage (4 PRs) + la dette pré-recette, toutes mergées. (Items 1+4 partageaient les fixtures Playwright ; 2/3/5/6 touchaient Makefile/CI/lockfile ; séquence (A)+(B), puis (C), puis (D).)

| Ordre | Titre | Effort | PR |
|---|---|---|---|
| 1 | `@axe-core/playwright` dans les fixtures E2E (helper `expectNoCriticalA11yViolations`) | S | [#601](https://github.com/vincentchalamon/bike-trip-planner/pull/601) ✅ |
| 4 | Monitoring console errors + requêtes 500 dans les fixtures E2E | S | [#601](https://github.com/vincentchalamon/bike-trip-planner/pull/601) ✅ |
| 3 | Script de complétude i18n FR/EN (`make i18n-check`) | S | [#603](https://github.com/vincentchalamon/bike-trip-planner/pull/603) ✅ |
| 5 | `npm audit` au workflow CI (`--audit-level=high`) | S | [#603](https://github.com/vincentchalamon/bike-trip-planner/pull/603) ✅ |
| 2 | Lighthouse CI (`make lighthouse`, pages publiques) | M | [#604](https://github.com/vincentchalamon/bike-trip-planner/pull/604) ✅ |
| 6 | Visual regression Playwright (30 baselines publiques + harnais ; trip + génération en 35.3) | M | [#605](https://github.com/vincentchalamon/bike-trip-planner/pull/605) ✅ |

**Compléments** (manquaient dans les 6 d'origine) : gate de couverture PHPUnit >= 80 % (aucun fail-under aujourd'hui) ; `composer audit` (en plus de `symfony check:security`) ; next-intl `onError` pour les clés manquantes au runtime ; 2e set VR « états » (modales/toasts/empty/error) ; câbler le monitoring 500 sur un vrai backend. CI : léger per-PR (`i18n-check`, `npm audit`, smoke axe), lourd en nightly (`lighthouse`, `visual-test`).

**Dette pré-recette** (à solder avant l'audit fonctionnel 35.3, sinon il re-signale une dette connue) — 2 items frontend tech-debt, PR indépendante :

| Issue | Titre | Effort | PR |
|---|---|---|---|
| [#450](https://github.com/vincentchalamon/bike-trip-planner/issues/450) | Wire AI payloads (`aiOverview` / `aiAnalysis`) via typegen — remplacer les mirrors manuels (`mercure/types.ts`, `validation/schemas.ts`) | S | [#602](https://github.com/vincentchalamon/bike-trip-planner/pull/602) ✅ |
| [#451](https://github.com/vincentchalamon/bike-trip-planner/issues/451) | Scope `DiffHighlight` aux alertes seules dans `StageAiSummary` (aujourd'hui enveloppe tout le résumé) | S | [#602](https://github.com/vincentchalamon/bike-trip-planner/pull/602) ✅ |

</details>

<details><summary>

## ✅ Sprint 35.1 — Référentiel de recette

</summary>
Spec commune à l'audit fonctionnel automatisé (35.3) et à la recette manuelle. Ne dépend pas de l'outillage, donc parallélisable avec 35. Livrables sous `docs/recette/`.

Livré en une PR unique (livrables fortement couplés sous `docs/recette/`).

| Ordre | Titre | Effort | PR |
|---|---|---|---|
| 1 | Inventaire des écrans (dérivé de `pwa/src/app/`) + variantes (auth/anon, états données) | S | [#607](https://github.com/vincentchalamon/bike-trip-planner/pull/607) ✅ mergée |
| 2 | Checklist par écran : éléments, comportements, états (hover/focus/disabled/loading/empty/error), responsive, a11y clavier | M | [#607](https://github.com/vincentchalamon/bike-trip-planner/pull/607) ✅ mergée |
| 3 | Manifeste d'éléments attendus par écran (présence + position approximative) dérivé de l'export Claude Design | M | [#607](https://github.com/vincentchalamon/bike-trip-planner/pull/607) ✅ mergée |
| 4 | Audit de couverture Gherkin (30 `.feature` vs features réelles) + scénarios manquants à écrire (IA S30-32, design S25-27) | M | [#607](https://github.com/vincentchalamon/bike-trip-planner/pull/607) ✅ mergée |

Source design : export Claude Design vendoré sous `docs/recette/design/` (`tokens.jsx`, `pages-*.jsx`, `toutes-les-pages.html`). **Comparaison app vs design = présence + position approximative** (auto, Playwright) ; couleur / typo / polish = **regard humain** (capture côte-à-côte).

</details>

<details><summary>

## ✅ Sprint 35.2 — Audit non-fonctionnel & qualité

</summary>
Exécute l'outillage de 35 + revue ciblée, produit des **findings en issues** (milestone `Sprint 35.4`, labels `security`/`perf`/`a11y`/`seo`/`i18n` + sévérité `P0`-`P3`). Pas de fix. Dépend de 35. Exécution : fan-out d'agents par dimension, findings vérifiés avant ouverture, rapport `docs/recette/audit-report.md`. _(ex-ordres 7-19)_

**✅ Audit livré** — rapport consolidé [#614](https://github.com/vincentchalamon/bike-trip-planner/pull/614) (màj de [#610](https://github.com/vincentchalamon/bike-trip-planner/pull/610)), à jour de l'état courant. Audit empirique complet (golden path Komoot réel, Lighthouse authentifié, couverture chiffrée, legs 401/404, purge RGPD, XSS, N+1, multi-device, chaos). Findings différés catalogués pour `Sprint 35.4` (ouverture des issues différée à validation). **Correctifs critiques mergés pendant/après l'audit** : F1 (P0, #616), IDOR-DETAIL + ENUM-404 (P1 sécurité, #616/#618, ADR-038), wiring/réseau Ollama + mode dégradé (#616/#304), F2 (#613), outillage Lighthouse/couverture/CI Vitest (#612/#615). Reste renvoyé à 35.3 : axe runtime authed, responsive éditeur authed, reconnexion SSE Mercure, payload XSS chat.

| Ordre | Titre | Effort | Statut |
|---|---|---|---|
| 1 | Sécurité : headers Caddy (constat), isolation Mercure, auth 401/403 exhaustive, rate limiting, XSS champs éditables, stack trace prod, `composer audit` | L | ✅ [#614](https://github.com/vincentchalamon/bike-trip-planner/pull/614) — SEC-001..005 (35.4) ; IDOR-DETAIL + ENUM-404 corrigés (#616/#618) |
| 2 | Performance : Lighthouse toutes pages (y.c. auth, iso-prod seedé), N+1 Doctrine, bundle/code splitting, temps calcul async | L | ✅ [#614](https://github.com/vincentchalamon/bike-trip-planner/pull/614) — PERF-001 ; Lighthouse mesuré (LH-PERF-HOME/AUTH) ; N+1 aucun |
| 3 | Accessibilité : axe 0 violation critique, navigation clavier (scriptée + manuelle) | M | ✅ [#614](https://github.com/vincentchalamon/bike-trip-planner/pull/614) — A11Y-001/002, LH-A11Y-HOME ; axe runtime authed -> 35.3 |
| 4 | SEO : meta + Open Graph sur les pages de partage | S | ✅ [#614](https://github.com/vincentchalamon/bike-trip-planner/pull/614) — SEO-001/002/003 |
| 5 | i18n : `make i18n-check` (parité) + clés visibles + formatage dates/nombres | S | ✅ [#614](https://github.com/vincentchalamon/bike-trip-planner/pull/614) — parité OK (848 clés) ; I18N-001 |
| 6 | Qualité : couverture >= 80 %, `make qa` propre, dette de tracking | S | ✅ [#614](https://github.com/vincentchalamon/bike-trip-planner/pull/614) — QUAL-001/002/003 ; QUAL-004 corrigé (#612) ; COV-API/FRONT mesurées (#615) |
| 7 | Privacy/anonymisation : `/privacy`, gating par env Plausible, 0 cookie / 0 PII, purge user en DB (pas de consentement, #385 abandonné) | M | ✅ [#614](https://github.com/vincentchalamon/bike-trip-planner/pull/614) — RGPD-MAGIC (P3) ; conformités OK |

</details>

<details><summary>

## ✅ Sprint 35.3 — Audit fonctionnel + couverture

</summary>
Transforme le référentiel (35.1) en couverture automatisée + valide les baselines VR. Dépend de 35 + 35.1. Exécution : fan-out par domaine d'écran, findings -> issues (milestone `Sprint 35.4`). _(ex-ordres 20-31)_

**✅ Livré** — 5 ordres, 4 PRs (O1 = no-op). Couverture Gherkin étendue (golden paths A/B/C + cas limites, IA, landing, parité FR/EN), baselines VR stabilisées et findings app-vs-design catalogués, liens/ancres vérifiés via outil reproductible.

| Ordre | Titre | Effort | PRs |
|---|---|---|---|
| 1 | Tests Playwright : vérifier l'existant et réparer le périmé (refonte design S25-27, IA S30-32) | M | ✅ no-op — suite verte en CI (aucun test périmé ; échecs locaux dus à un build `pwa:ci` obsolète, levés après rebuild) |
| 2 | Golden paths A/B/C + cas limites en Gherkin (checklists ci-dessous) | L | [#627](https://github.com/vincentchalamon/bike-trip-planner/pull/627) |
| 3 | Combler les domaines `.feature` absents (IA, design) | M | [#623](https://github.com/vincentchalamon/bike-trip-planner/pull/623) |
| 4 | Baselines VR (36 pages + set « états ») + comparaison app vs design (présence + position) | M | [#624](https://github.com/vincentchalamon/bike-trip-planner/pull/624) |
| 5 | Vérifier liens & ancres (docs, README, ADR, rapport recette) : aucun lien mort (404) ni ancre disparue | S | [#622](https://github.com/vincentchalamon/bike-trip-planner/pull/622) |

DoD : `make test-e2e` + `make test-recette` + `make visual-test` verts ; chaque écran du manifeste 35.1 a un verdict ; aucun lien/ancre cassé (ordre 5).

**État DoD** : `test-recette` vert (golden paths + cas limites ; 2 scénarios `@fixme` documentés : multi-onglets, upload 30MB) ; `visual-test` vert (81 passed / 21 skipped — écrans à carte skippés sur Firefox sans WebGL + mobile 375px, raison documentée) ; verdict par écran consolidé dans [`docs/recette/app-vs-design-findings.md`](docs/recette/app-vs-design-findings.md) (divergences fonctionnelles/éléments/positionnement → hotlist Sprint 35.4) ; liens/ancres OK (`make link-check`). `manifest.spec.ts` (assertions de région) retiré au profit du document de findings (faux positifs : variante authentifiée + heuristique de viewport).

---

## ✅ Recette manuelle (utilisateur)

Entre 35.3 et 35.4 : recette manuelle sur l'environnement iso-prod, guidée par le référentiel 35.1 + les baselines, produisant des findings (issues milestone `Sprint 35.4`). Checklists et seuils ci-dessous.

### Recette Sprint 35 — Golden Path A (Komoot)

- **Checklist :**
  - [ ] Connexion via magic link (email → Mailcatcher → clic → connecté)
  - [ ] Coller un lien Komoot tour → barre de progression SSE → stages générées
  - [ ] Vérifier : distances, dénivelés, carte avec tracé coloré, profil altimétrique
  - [ ] Configurer les dates (2 semaines dans le futur)
  - [ ] Modifier le profil cyclo (touring, 70 km/jour) → recalcul des stages
  - [ ] Activer le mode VAE → alertes batterie visibles
  - [ ] Insérer un jour de repos au milieu → décalage des dates
  - [ ] Sélectionner un hébergement → recalcul point d'arrivée
  - [ ] Exporter en texte → contenu cohérent
  - [ ] Télécharger le GPX global → ouvrir dans un logiciel tiers
  - [ ] Partager le trip → ouvrir le lien en navigation privée → lecture seule
  - [ ] Révoquer le partage → le lien ne fonctionne plus
  - [ ] Dupliquer le trip → modifier le duplicata → l'original est inchangé
  - [ ] Se déconnecter → se reconnecter → le trip est toujours là

### Recette Sprint 35 — Cas limites

- **Inputs invalides :**
  - [ ] GPX malformé (XML invalide) → message d'erreur clair
  - [ ] GPX vide (0 point) → message d'erreur
  - [ ] GPX > 30 MB → erreur propre (pas de 502/413 brut)
  - [ ] URL Komoot invalide → validation avant envoi
  - [ ] URL Strava privée → gestion de l'erreur
  - [ ] Dates très éloignées (2 ans) → pas de crash (météo non dispo)
- **Auth edge cases :**
  - [ ] Token magic link expiré → message clair + redemander
  - [ ] Token déjà utilisé → message clair
  - [ ] Double-clic sur le lien magic link → pas de crash
  - [ ] 2 onglets ouverts → silent refresh ne casse pas l'autre onglet
  - [ ] Inactivité 15+ min (JWT expiré) → refresh silencieux à la prochaine action
  - [ ] Cookie refresh supprimé manuellement → redirect /login
- **Réseau / async :**
  - [ ] Coupure réseau pendant un calcul → UI pas bloquée indéfiniment
  - [ ] Worker crash → retry Messenger (3×, backoff exponentiel) fonctionne
  - [ ] SSE Mercure déconnecté → reconnexion automatique
- **Limites :**
  - [ ] Trip 20+ étapes → performance carte et timeline acceptable
  - [ ] 0 hébergement trouvé → message informatif
  - [ ] Undo jusqu'au début → bouton disabled, pas de crash

### Recette Sprint 35 — Audit visuel multi-device

| Device | Navigateur | Thème | Langue | OK ? |
|---|---|---|---|---|
| Desktop 1920×1080 | Chrome | Clair | FR | [ ] |
| Desktop 1920×1080 | Firefox | Sombre | EN | [ ] |
| Desktop 1440×900 | Chrome | Sombre | FR | [ ] |
| Tablette 768×1024 | Chrome | Clair | EN | [ ] |
| Mobile 375×812 | Chrome | Clair | FR | [ ] |
| Mobile 375×812 | WebKit | Sombre | EN | [ ] |

- **Par combinaison, vérifier :**
  - [ ] Pas d'overflow horizontal
  - [ ] Carte utilisable (zoom, pan, markers cliquables)
  - [ ] Profil altimétrique lisible
  - [ ] Modales ne débordent pas de l'écran
  - [ ] Toasts visibles et ne masquent rien
  - [ ] Switch de vue (timeline/map/split) fonctionnel
  - [ ] Pas de flash blanc au chargement en dark mode

### Recette Sprint 35 — Audits automatisés

- **Seuils :**
  - [ ] `make qa` : 0 erreur
  - [ ] `make test-php` : green
  - [ ] `make test-unit` : green
  - [ ] `make test-e2e` : green
  - [ ] `make test-recette` : green
  - [ ] `composer audit` : 0 vulnérabilité haute/critique
  - [ ] `npm audit` : 0 vulnérabilité haute/critique
  - [ ] `make lighthouse` : Performance ≥ 80, Accessibility ≥ 90, SEO ≥ 90, Best Practices ≥ 90
  - [ ] `make coverage` : PHPUnit ≥ 80%
  - [ ] axe-core : 0 violation critique
  - [ ] `make i18n-check` : 0 clé manquante
  - [ ] Headers sécurité présents : CSP, HSTS, X-Content-Type-Options, X-Frame-Options
  - [ ] Aucune stack trace exposée en `APP_ENV=prod`
  - [ ] Audit privacy : page `/privacy` complète, mention Plausible (cloud / auto-hébergé), gating par env (`NEXT_PUBLIC_PLAUSIBLE_DOMAIN`), 0 cookie / 0 PII (pas de consentement, #385 abandonné)
  - [ ] Audit anonymisation : suppression user → trips et préférences purgés (vérifier via requête DB) ; events Plausible anonymes par construction (pas de lien à l'user)
  - [ ] Tous les bugs trouvés reportés en issues GitHub avec labels (`bug`, `ux`, `perf`, `security`, `a11y`)

</details>

<details><summary>

## ✅ Sprint 35.4 — Corrections

</summary>
Fixe les findings de 35.2 + 35.3 + recette manuelle, requêtés par **milestone `Sprint 35.4`** (l'état des issues n'étant pas fiable). Modèle worktree-parallèle (`/sprint`). _(ex-ordres 32-36)_

**⏩ Avancé en pré-recette (batch durcissement, 5 PRs, avant la recette manuelle)** — corrige le sous-ensemble déterministe et code-local des findings du rapport pour que la recette parte d'une app durcie :

- [#629](https://github.com/vincentchalamon/bike-trip-planner/pull/629) `fix(security)` — **finalité de suppression de compte** (bug surfacé hors-rapport : un compte supprimé pouvait se ré-authentifier) : `DeletedUserChecker` (user_checker), refus `isDeleted` dans AuthVerify/AuthRefresh, purge `magic_link` (RGPD-MAGIC) + `access_request` (PII résiduelle). 5 tests.
- [#630](https://github.com/vincentchalamon/bike-trip-planner/pull/630) `fix(security)` — **SEC-002/003/004** (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy) + **SEC-001** CSP en **Report-Only** (enforce à acter après observation recette) + **SEC-005** (x-powered-by).
- [#632](https://github.com/vincentchalamon/bike-trip-planner/pull/632) `feat(seo)` — **SEO-001/002/003** (OG/Twitter, robots, sitemap, métadonnées de partage) + **I18N-001** (onError).
- [#633](https://github.com/vincentchalamon/bike-trip-planner/pull/633) `fix(a11y)` — **A11Y-001/002** (SSR de la landing : `<main>` + `<h1>`) ; vise LH-A11Y-HOME (à re-mesurer).
- [#631](https://github.com/vincentchalamon/bike-trip-planner/pull/631) `perf` — **PERF-001** (lazy-load MapPanel, maplibre hors du 1er chunk éditeur) ; vise LH-PERF-AUTH (à re-mesurer).

**Reste 35.4** (non avancé) : COV-API/COV-FRONT/QUAL-001/002 (couverture), DT-LIVE, F5 (Overpass), CHAOS-RESTART, promotion CSP enforce, re-mesure Lighthouse (LH-PERF-HOME/LH-A11Y-HOME/LH-PERF-AUTH), + findings de la recette manuelle. Détails et nouveau finding (auth-bypass / access_request) dans [`docs/recette/audit-report.md`](docs/recette/audit-report.md).

| Ordre | Titre | Effort |
|---|---|---|
| 1 | Headers de sécurité Caddy (CSP / HSTS / X-Frame-Options / X-Content-Type-Options) | S — ⏩ #630 (CSP report-only ; enforce à finaliser) |
| 2 | P0/P1 : bugs bloquants + fonctionnels dégradés | L — auth-bypass compte supprimé ⏩ #629 |
| 3 | P2 : régressions UX/UI | M — A11Y ⏩ #633, SEO ⏩ #632 |
| 4 | P3 : performance et polish | M — PERF-001 ⏩ #631, I18N-001 ⏩ #632 |
| 5 | Re-test golden path A final (gate de clôture) | M |

DoD : toutes les issues P0-P3 fermées ou explicitement reportées ; golden path A re-testé vert.

</details>

> **Sprints reportés — déploiement & exploitation.** Les sprints ci-dessous (36-39) couvrent la mise en production et l'exploitation (Garmin OAuth, déploiement, perf/résilience, backup). Ils sont **différés** : le développement fonctionnel continue sans déploiement. Ils restent en fin de plan, à reprendre à l'ouverture de la beta.

<details><summary>

## Sprint 36 — Garmin Connect

</summary>
Push vers Garmin Connect via OAuth 2.0 PKCE (Phase 2). **L'export FIT natif (Phase 1) est déjà livré** (`FitEncoder` + `TripFitNormalizer`, format `fit` sur `Trip`/`Stage`) ; reste le flux OAuth + push de course. Voir [ADR-018](docs/adr/adr-018-garmin-export-and-device-sync-strategy.md) (statut : Proposed). Test local via ngrok pour le callback OAuth. Le visuel downloads/partage (sprint 27) et l'auth ([#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76), livrée par [#248](https://github.com/vincentchalamon/bike-trip-planner/pull/248)) sont en place ; cette implémentation ajoute la 4ᵉ section Garmin Connect en consommant les tokens design.

> **Prérequis :** s'inscrire au [Garmin Developer Program](https://developer.garmin.com/) quelques sprints en avance (~2 jours d'approbation) ; le callback OAuth de production nécessite une URL HTTPS publique (**dépend du sprint 37 — Déploiement**).

| Ordre | ID                                                                    | Titre          | Effort | PRs | Dépend de                                                                                                                                                  |
|-------|-----------------------------------------------------------------------|----------------|--------|-----|------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1     | [#65](https://github.com/vincentchalamon/bike-trip-planner/issues/65) | Push OAuth Garmin Connect (Phase 2 ; FIT Phase 1 déjà livrée) | M | ~2 | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76) ✅, sprint 27, sprint 37 (callback prod) |

### Recette Sprint 36

- **Tests E2E :** `tests/recette/sprint-36.spec.ts`
- **Checklist manuelle :**
  - [ ] Export FIT téléchargeable par étape (Phase 1 déjà livrée — non-régression)
  - [ ] Flux OAuth Garmin Connect complet (via ngrok)
  - [ ] Push course vers Garmin Connect fonctionnel
  - [ ] Gestion erreurs : token expiré, API indisponible

</details>

<details><summary>

## Sprint 37 — Déploiement

</summary>
Mise en production basée sur [ADR-019](docs/adr/adr-019-deployment-infrastructure-strategy.md) (statut : Proposed, amendé par [ADR-039](docs/adr/adr-039-beta-right-sizing-free-tier.md)). Cible : Oracle Cloud Always Free + Coolify + FreeDNS. Issues GitHub à créer au moment venu. **Plus d'infra IA à déployer** : l'IA est passée en providers cloud BYO-token ([ADR-042](docs/adr/adr-042-optional-multi-provider-ai-byo-token.md), Ollama retiré) et masquée en prod ([ADR-046](docs/adr/adr-046-temporary-ai-feature-flag.md)). Intègre l'auth résolue côté serveur ([ADR-047](docs/adr/adr-047-server-side-web-auth-resolution.md) : `API_BACKEND_URL` interne + `/auth/session`) et le **build PWA origin-relative** (même origine que l'API, pas de sous-domaine `api.` séparé).

| Ordre | Étape                                              | Effort |
|-------|----------------------------------------------------|--------|
| 1     | CI/CD pipeline production                          | M      |
| 2     | Oracle Cloud (OCI) Always Free provisioning        | M      |
| 3     | Coolify installation + configuration               | M      |
| 4     | Configuration DNS (FreeDNS)                        | S      |
| 5     | Docker configs production (FrankenPHP/Caddy + Mercure embarqués, PostgreSQL/PostGIS, Redis, Valhalla, provisioner) + healthchecks | L      |
| 6     | Monitoring & healthchecks (Sentry SaaS + Uptime Kuma/UptimeRobot ; latence Valhalla/DB/Redis) | M      |
| 7     | Migration données + smoke test production (golden path Komoot) | M      |
| 8     | [#312](https://github.com/vincentchalamon/bike-trip-planner/issues/312) Feature-deploy : preview par PR (Étapes 1-7) | L |
| 9     | [#270](https://github.com/vincentchalamon/bike-trip-planner/issues/270) Génération du keypair JWT sur le serveur — infra déjà en place (secrets `compose.yaml` + `secrets-inventory.md` via [#533](https://github.com/vincentchalamon/bike-trip-planner/pull/533)), reste à câbler la génération serveur | S |

### Recette Sprint 37

- **Checklist manuelle :**
  - [ ] Application accessible via URL publique (même origine PWA + API)
  - [ ] HTTPS fonctionnel (certificat TLS auto Caddy)
  - [ ] PostgreSQL/PostGIS + Redis + Valhalla opérationnels en production
  - [ ] Mercure SSE fonctionnel en production
  - [ ] Auth serveur ([ADR-047](docs/adr/adr-047-server-side-web-auth-resolution.md)) : `/auth/session` + gate RSC OK derrière le reverse-proxy
  - [ ] CI/CD : déploiement automatique sur push main (webhook Coolify)
  - [ ] Monitoring : healthchecks + alertes basiques (Sentry SaaS, Uptime Kuma/UptimeRobot)
  - [ ] Garmin Connect : callback OAuth sur URL production
  - [ ] Preview déployée via label `deploy:preview` sur une PR de test
  - [ ] URL `pr-<N>.biketrip.example.com` accessible en HTTPS
  - [ ] Cleanup automatique à la fermeture de la PR

</details>

<details><summary>

## Sprint 38 — Performance & Resilience Deep Dive

</summary>
Suite à Sprint 35 (recette + audits standards), Sprint 38 industrialise l'analyse de performance avancée : micro-benchs PHP, load testing scriptable, profiling à la demande, observabilité applicative ciblée, audit infrastructure, résilience aux pannes, et empreinte carbone publiée. Tests menés en iso-prod sur la VM Oracle pré-ouverture, avec validation device physique (Samsung Galaxy S20 FE).

> **Prérequis :** Sprint 37 (Déploiement) terminé, app déployée iso-prod sur Oracle + Coolify, observabilité opérationnelle ([ADR-031](docs/adr/adr-031-error-tracking-strategy.md) : Sentry SaaS en beta — GlitchTip auto-hébergé étant la cible ultérieure — + Uptime Kuma/UptimeRobot).

| Ordre | ID                                                                    | Titre                                                                                                              | Effort | PRs | Dépend de                                                                                                                                                  |
|-------|-----------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------|--------|-----|------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1     | [#508](https://github.com/vincentchalamon/bike-trip-planner/issues/508) | feat(perf): PHPBench hot paths + in-ride benchs                                                                  | M      | —   | —                                                                                                                                                          |
| 2     | [#509](https://github.com/vincentchalamon/bike-trip-planner/issues/509) | feat(perf): k6 scenarios + fixtures Komoot/GPX + Makefile                                                        | M      | —   | —                                                                                                                                                          |
| 3     | [#510](https://github.com/vincentchalamon/bike-trip-planner/issues/510) | feat(observability): Postgres pg_stat_statements + Redis SLOWLOG + Messenger queue depth                          | S      | —   | —                                                                                                                                                          |
| 4     | [#511](https://github.com/vincentchalamon/bike-trip-planner/issues/511) | feat(ci): perf.yml workflow (PHPBench + k6 + bundle-analyzer nightly)                                            | S      | —   | [#508](https://github.com/vincentchalamon/bike-trip-planner/issues/508), [#509](https://github.com/vincentchalamon/bike-trip-planner/issues/509)            |
| 5     | [#512](https://github.com/vincentchalamon/bike-trip-planner/issues/512) | feat(perf): Lighthouse CI desktop + mobile (S20 FE preset)                                                       | S      | —   | —                                                                                                                                                          |
| 6     | [#513](https://github.com/vincentchalamon/bike-trip-planner/issues/513) | docs(perf): S20 FE physical device test checklist + IndexedDB perf                                               | S      | —   | —                                                                                                                                                          |
| 7     | [#514](https://github.com/vincentchalamon/bike-trip-planner/issues/514) | feat(eco): Carbon footprint measure + public /eco page + CarbonStatsCard                                         | M      | —   | [#512](https://github.com/vincentchalamon/bike-trip-planner/issues/512)                                                                                    |
| 8     | [#515](https://github.com/vincentchalamon/bike-trip-planner/issues/515) | feat(observability): Sentry custom spans backend (Valhalla/TripDetail/Mercure ; InRide/TripChat = IA masquée) | M | — | — |
| 9     | [#516](https://github.com/vincentchalamon/bike-trip-planner/issues/516) | feat(observability): Sentry spans frontend + Mercure SSE end-to-end latency                                      | M      | —   | [#515](https://github.com/vincentchalamon/bike-trip-planner/issues/515)                                                                                    |
| 10    | [#517](https://github.com/vincentchalamon/bike-trip-planner/issues/517) | fix(perf): ChatHistoryLoader AbortController + PoiCard React.memo                                                | S      | —   | —                                                                                                                                                          |
| 11    | [#518](https://github.com/vincentchalamon/bike-trip-planner/issues/518) | feat(perf): Excimer + Speedscope ad-hoc profiling on iso-prod                                                    | M      | —   | —                                                                                                                                                          |
| 12    | [#519](https://github.com/vincentchalamon/bike-trip-planner/issues/519) | feat(ci): smoke-test extended with latency assertions + Lighthouse post-deploy                                   | S      | —   | [#512](https://github.com/vincentchalamon/bike-trip-planner/issues/512)                                                                                    |
| 13    | [#520](https://github.com/vincentchalamon/bike-trip-planner/issues/520) | docs(perf): Sentry/GlitchTip dashboards documentation + slow-trip-computation runbook | S | — | [#515](https://github.com/vincentchalamon/bike-trip-planner/issues/515), [#516](https://github.com/vincentchalamon/bike-trip-planner/issues/516) |
| 14    | [#521](https://github.com/vincentchalamon/bike-trip-planner/issues/521) | chore(infra): Caddy audit (Brotli, cache headers, HTTP/3, Server-Timing)                                         | S      | —   | —                                                                                                                                                          |
| 15    | [#522](https://github.com/vincentchalamon/bike-trip-planner/issues/522) | chore(infra): Postgres/PostGIS indexes audit (GiST corridor) + Redis bigkeys audit | M | — | [#510](https://github.com/vincentchalamon/bike-trip-planner/issues/510) |
| 16    | [#523](https://github.com/vincentchalamon/bike-trip-planner/issues/523) | feat(resilience): chaos test scripts (Valhalla/Mercure/Redis/Postgres down) + resilience-tests doc | M | — | — |
| 17    | [#524](https://github.com/vincentchalamon/bike-trip-planner/issues/524) | feat(resilience): OOM / VM recovery test + update oracle-vm-reclaimed runbook                                    | S      | —   | [#523](https://github.com/vincentchalamon/bike-trip-planner/issues/523)                                                                                    |
| 18    | [#525](https://github.com/vincentchalamon/bike-trip-planner/issues/525) | feat(perf): inter-release baselines comparison script + CI diff comment                                          | S      | —   | [#508](https://github.com/vincentchalamon/bike-trip-planner/issues/508), [#509](https://github.com/vincentchalamon/bike-trip-planner/issues/509), [#512](https://github.com/vincentchalamon/bike-trip-planner/issues/512) |

### Recette Sprint 38

- **Tests E2E :** `tests/recette/sprint-38.spec.ts` (nouveaux scénarios perf + résilience)
- **Checklist manuelle :**
  - [ ] `make phpbench` exécute les 7 benchs, baselines stockées dans `docs/perf/baselines/<release>/`
  - [ ] `make perf-load TARGET=https://biketrip.mooo.com` exécute les 5 scénarios k6 cold + hot, baselines stockées
  - [ ] Workflow CI `perf.yml` exécute Lighthouse desktop + mobile à chaque PR avec assertions
  - [ ] Page `/eco` publique affiche l'empreinte carbone mesurée, lien depuis footer
  - [ ] Sentry/GlitchTip Performance UI montre les transactions custom (Valhalla, TripDetail, Mercure, etc.)
  - [ ] `coolify env set EXCIMER_ENABLED=1` + `curl -H "X-Profile: 1" .../api/trips/<id>` produit un Speedscope JSON exploitable
  - [ ] Smoke-test post-deploy échoue si `/api/health` deps latency dépasse les seuils
  - [ ] Session S20 FE physique : checklist `docs/perf/mobile-device-test.md` complétée, baselines enregistrées
  - [ ] Caddy : Brotli actif, cache headers immutable sur statics, HTTP/3 activé
  - [ ] Postgres `pg_stat_statements` actif, slow query log opérationnel
  - [ ] Chaos tests : Valhalla/Mercure/Redis/Postgres down → app dégrade proprement (pas de 500, message user clair)
  - [ ] OOM test : time-to-recovery VM mesuré, runbook `oracle-vm-reclaimed.md` updaté
  - [ ] `scripts/perf-diff.sh <a> <b>` produit un Markdown lisible de comparaison de releases
  - [ ] Tous les SLOs cibles du plan validés ou écarts documentés en issues `[perf-debt]`

</details>

<details><summary>

## Sprint 39 — Backup & Disaster Recovery

</summary>
Sprint dédié à la résilience de la donnée en production. ADR-032 (Migrations & Rollback) appelle explicitement un "future plan PostgreSQL backup" en §51-59 : c'est ce sprint. La stack opérationnelle (Coolify, Sentry SaaS, Uptime Kuma/UptimeRobot, /api/health, runbooks) et la résilience services (Sprint 38 chaos tests + OOM recovery) sont en place ; il reste à protéger la donnée elle-même. Sans ce sprint, un DROP TABLE accidentel, une destructive migration shippée par erreur, ou une perte de la VM Oracle entraîne une perte de données irréversible.

> **Prérequis :** Sprint 37 (Déploiement) et Sprint 38 (Perf & Resilience) terminés. App déployée iso-prod sur Oracle + Coolify, Sentry SaaS + Uptime Kuma/UptimeRobot + spans + chaos tests opérationnels.
>
> **Bloque :** ouverture publique. Tant que ce sprint n'est pas livré, le projet reste en iso-prod sans données utilisateur réelles.

| Ordre | ID                                                                      | Titre                                                                                  | Effort | PRs | Dépend de                                                                                                                                                                                                                                                                                       |
|-------|-------------------------------------------------------------------------|----------------------------------------------------------------------------------------|--------|-----|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1     | [#527](https://github.com/vincentchalamon/bike-trip-planner/issues/527) | docs(adr): add adr-048 backup and disaster recovery strategy | S | — | — |
| 2     | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528) | feat(backup): add backup container with pg_dump + age + zstd (local volume)            | M      | —   | [#527](https://github.com/vincentchalamon/bike-trip-planner/issues/527)                                                                                                                                                                                                                         |
| 3     | [#529](https://github.com/vincentchalamon/bike-trip-planner/issues/529) | feat(backup): wire OCI Object Storage + Backblaze B2 destinations with object lock     | M      | —   | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528)                                                                                                                                                                                                                         |
| 4     | [#530](https://github.com/vincentchalamon/bike-trip-planner/issues/530) | feat(backup): add secrets bundle export (Coolify API + JWT PEMs)                       | S      | —   | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528)                                                                                                                                                                                                                         |
| 5     | [#531](https://github.com/vincentchalamon/bike-trip-planner/issues/531) | feat(backup): backup freshness endpoint + Uptime Kuma monitor                          | S      | —   | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528)                                                                                                                                                                                                                         |
| 6     | [#532](https://github.com/vincentchalamon/bike-trip-planner/issues/532) | docs(runbooks): disaster recovery + backup architecture                                | M      | —   | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528), [#529](https://github.com/vincentchalamon/bike-trip-planner/issues/529), [#530](https://github.com/vincentchalamon/bike-trip-planner/issues/530)                                                                       |
| 7     | [#534](https://github.com/vincentchalamon/bike-trip-planner/issues/534) | feat(backup): partial restore script with FK safety + compose.restore.yaml             | S      | —   | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528), [#532](https://github.com/vincentchalamon/bike-trip-planner/issues/532)                                                                                                                                                 |
| 8     | [#535](https://github.com/vincentchalamon/bike-trip-planner/issues/535) | ci: pre-deploy backup step in deploy workflow with [skip backup] bypass                | S      | —   | [#529](https://github.com/vincentchalamon/bike-trip-planner/issues/529)                                                                                                                                                                                                                         |
| 9     | [#536](https://github.com/vincentchalamon/bike-trip-planner/issues/536) | ci: monthly restore drill workflow dispatching incident-create on failure              | M      | —   | [#529](https://github.com/vincentchalamon/bike-trip-planner/issues/529), [#532](https://github.com/vincentchalamon/bike-trip-planner/issues/532)                                                                                                                                                 |

> **Réalignement (#533).** L'inventaire des secrets (`docs/runbooks/secrets-inventory.md`) et la politique de rotation (`docs/runbooks/secrets-rotation.md`) ont été livrés en avance par la PR [#533](https://github.com/vincentchalamon/bike-trip-planner/pull/533) (mergée). #532 ne couvre donc plus que `disaster-recovery.md` + `backup-architecture.md` et doit _lier_ la rotation existante plutôt que la redocumenter ; #527 crée l'**ADR-048** (le numéro 038 est déjà pris par « hide-forbidden-as-not-found », 033/036 par l'OSM refresh — prochain numéro libre : 048) et doit référencer ces deux runbooks.

### Recette Sprint 39

- **Tests E2E :** `tests/recette/sprint-39-backup.spec.ts` (déclenche le service backup, vérifie présence du dump localement + sur OCI + B2 via rclone).
- **Checklist manuelle :**
  - [ ] `make backup-now` produit un dump chiffré localement + uploads OCI + B2.
  - [ ] `rclone ls b2:btp-backups` montre le dump du jour.
  - [ ] OCI lifecycle retention et B2 Object Lock vérifiés (tentative de delete via UI doit échouer).
  - [ ] `curl https://biketrip.mooo.com/internal/backup/status` retourne JSON avec `age_seconds < 90000`.
  - [ ] Uptime Kuma monitor "backup-freshness" actif et green.
  - [ ] Drill manuel complet : suivre `docs/runbooks/disaster-recovery.md` § "Procédure générique" sur DB ephemeral → `/api/health` green.
  - [ ] Test restauration sélective : `restore-table.sh trip "id='<uuid>'"` réinjecte sans casser FK.
  - [ ] `gh workflow run restore-drill.yml` → succès, artefact stocké, `pg_stat_statements` réactivée post-restore.
  - [ ] Test KO forcé : corrompre volontairement le dump dans le drill → issue créée via `incident-create.yml` avec labels `backup` + `severity:high`.
  - [ ] Tag `v0.0.0-backup-test` → `deploy.yml` exécute pre-deploy backup avant Coolify dispatch.
  - [ ] Tag annoté `v0.0.1-hotfix [skip backup]` → pre-deploy backup skippé.
  - [ ] Runbook DR ouvert par un opérateur qui n'a jamais vu le projet, restauration complète end-to-end < 2 h.
  - [ ] Triple stockage clé age vérifié (Bitwarden + papier + USB chiffré).

</details>

<details><summary>

## ✅ Sprint 43 — Feedbacks Thomas (cohérence UI, flux & bugs)

</summary>
Batch de feedbacks d'un dev testeur ([#830](https://github.com/vincentchalamon/bike-trip-planner/issues/830)) traité via `/sprint 43` en worktrees parallèles. Identité couleurs / animations homepage laissées hors scope (designer). PRs à merger par l'utilisateur ; deux paires partagent un fichier (conflits possibles selon l'ordre de merge) : #832/#837 (`StageStatsRow.tsx`), #835/#840 (`use-trip-planner.ts`).

| Ordre | ID | Titre | Effort | PR | Statut | Dépend de |
|-------|----|-------|--------|----|--------|-----------|
| 1 | [#831](https://github.com/vincentchalamon/bike-trip-planner/issues/831) | Marque header/footer + LocaleSwitcher select | M | [#841](https://github.com/vincentchalamon/bike-trip-planner/pull/841) `feature/831` | — | — |
| 2 | [#832](https://github.com/vincentchalamon/bike-trip-planner/issues/832) | InfoTooltip réutilisable + difficulté | S | [#842](https://github.com/vincentchalamon/bike-trip-planner/pull/842) `feature/832` | — | — |
| 3 | [#833](https://github.com/vincentchalamon/bike-trip-planner/issues/833) | Boutons vs liens (Élargir le rayon) | S | [#843](https://github.com/vincentchalamon/bike-trip-planner/pull/843) `feature/833` | — | — |
| 4 | [#834](https://github.com/vincentchalamon/bike-trip-planner/issues/834) | Upload GPX direct depuis l'écran des 3 choix | M | [#844](https://github.com/vincentchalamon/bike-trip-planner/pull/844) `feature/834` | — | — |
| 5 | [#835](https://github.com/vincentchalamon/bike-trip-planner/issues/835) | Toast « voyage enregistré » après import | S | [#845](https://github.com/vincentchalamon/bike-trip-planner/pull/845) `feature/835` | — | — |
| 6 | [#836](https://github.com/vincentchalamon/bike-trip-planner/issues/836) | Alerte « Configure une IA » sous le header | S | [#846](https://github.com/vincentchalamon/bike-trip-planner/pull/846) `feature/836` | — | — |
| 7 | [#837](https://github.com/vincentchalamon/bike-trip-planner/issues/837) | Affordance du découpage par distance | M | [#847](https://github.com/vincentchalamon/bike-trip-planner/pull/847) `feature/837` | — | — |
| 8 | [#838](https://github.com/vincentchalamon/bike-trip-planner/issues/838) | Polish structurel sidebar paramètres | M | [#848](https://github.com/vincentchalamon/bike-trip-planner/pull/848) `feature/838` | — | — |
| 9 | [#839](https://github.com/vincentchalamon/bike-trip-planner/issues/839) | Installabilité PWA minimale (bug macOS) | M | [#849](https://github.com/vincentchalamon/bike-trip-planner/pull/849) `feature/839` | — | — |
| 10 | [#840](https://github.com/vincentchalamon/bike-trip-planner/issues/840) | Races au recompute de distance | L | [#850](https://github.com/vincentchalamon/bike-trip-planner/pull/850) `feature/840` | — | — |

### Hors scope (confié à un designer)

Identité visuelle / palette « trop IA / lourde », identité forte, animations de la homepage, restyling esthétique de la sidebar (#838 = structure uniquement).

</details>

<details><summary>

## Hors Sprints

</summary>
| ID  | Titre                            | Note                     |
|-----|----------------------------------|--------------------------|
| #5  | Add unit tests                   | Continu, à chaque sprint |
| #67 | Générer un itinéraire (LLaMA 3B) | Card "Assistant IA" Acte 1 — dépend de Valhalla + sprints 28-31 |

### Issues fermées suite à la refonte design

| ID                                                                      | Titre                                                  | Raison                                                                       |
|-------------------------------------------------------------------------|--------------------------------------------------------|------------------------------------------------------------------------------|
| [#304](https://github.com/vincentchalamon/bike-trip-planner/issues/304) | Fallback gracieux sans Ollama                          | **Rouvert puis corrigé** (audit Sprint 35.2) — mode dégradé explicite livré (#616 + #304 mergés ; ADR-028 « Degraded Mode », ADR-038) |
| [#307](https://github.com/vincentchalamon/bike-trip-planner/issues/307) | Bandeau « Actualiser l'analyse IA »                    | IA toujours active — pas de bandeau (issue #375 §16 Sprint 27)               |
| [#308](https://github.com/vincentchalamon/bike-trip-planner/issues/308) | Fallback frontend sans LLaMA                           | Ollama = dépendance dure — impossible (issue #375 arbitrage v2)              |

</details>

<details><summary>

## ✅ Sprint 44 - Alertes : fondement, unités, contrat

</summary>
Assainissement du moteur d'alertes : ce qui est faux, ce qui n'est pas fondé, ce qui n'est pas compréhensible. Indépendant de toute donnée nouvelle, donc lançable immédiatement, et c'est avec le sprint 45 ce qui porte l'essentiel de la valeur perçue.

> **Prérequis :** aucun.
>
> **Ordre interne impératif :** #862 (formateur) **après** #859 (attribution). Formater joliment un nombre mal attribué est pire que de le laisser brut : « 43,9 km » se lit comme une mesure autorisée, `43871m` se lit comme un artefact machine.

| Ordre | ID | Titre | Effort | PRs | Statut | Dépend de |
|-------|----|-------|--------|-----|--------|-----------|
| 1 | [#859](https://github.com/vincentchalamon/bike-trip-planner/issues/859) | fix(terrain): attribute ways to the ridden route, not to a 100 m neighbourhood | - | [#894](https://github.com/vincentchalamon/bike-trip-planner/pull/894) `feature/859` | ✅ Mergée | - |
| 2 | [#860](https://github.com/vincentchalamon/bike-trip-planner/issues/860) | fix(terrain): widen the unpaved surface vocabulary | - | [#895](https://github.com/vincentchalamon/bike-trip-planner/pull/895) `feature/860` | ✅ Mergée | - |
| 3 | [#861](https://github.com/vincentchalamon/bike-trip-planner/issues/861) | fix(alerts): drop the tag-presence rules and the dead surface fallback | - | [#896](https://github.com/vincentchalamon/bike-trip-planner/pull/896) `feature/861` | ✅ Mergée | - |
| 4 | [#862](https://github.com/vincentchalamon/bike-trip-planner/issues/862) | feat(alerts): locale-aware distance formatter and translated tag values | - | [#899](https://github.com/vincentchalamon/bike-trip-planner/pull/899) `feature/862` | ✅ Mergée | [#859](https://github.com/vincentchalamon/bike-trip-planner/issues/859) ✅ |
| 5 | [#863](https://github.com/vincentchalamon/bike-trip-planner/issues/863) | fix(alerts): restore navigate and dismiss actions with coordinates end-to-end | - | [#897](https://github.com/vincentchalamon/bike-trip-planner/pull/897) `feature/863` | ✅ Mergée | - |
| 6 | [#864](https://github.com/vincentchalamon/bike-trip-planner/issues/864) | fix(alerts): rest-day guard, local-time sunset and multi-year calendar | - | [#898](https://github.com/vincentchalamon/bike-trip-planner/pull/898) `feature/864` | En cours (rebasée) | - |

### Conflits de merge : résolutions appliquées (sprint 44)

Les 6 issues ont été traitées en parallèle via `/sprint 44`, donc plusieurs branches touchaient les mêmes fichiers. **5 des 6 PRs sont mergées** (#894, #895, #896, #897, #899) ; seule #898 reste ouverte. Conflits résolus comme suit.

- **#899 était empilée sur `feature/859`.** Après le squash-merge de #894, GitHub l'a retargettée sur `main` mais la branche portait encore le commit pré-squash de #859. Résolu par `git rebase --onto origin/main <commit-859>` (pas un rebase simple, qui aurait rejoué du code déjà mergé).
- **`SurfaceAlertAnalyzer.php`** — la suppression de `detectMissingSurfaceData()` par #896 a gagné dans #895 et #899, comme prévu. Effets de bord traités : le `$surfaceList` de #895 avait disparu (#896 a inliné le `implode`), et 5 cas de `SurfaceAlertAnalyzerTest` attendaient 2 alertes au lieu d'1.
- **`WaysRepository.php`** — la requête clippée de #894 a été conservée, augmentée des deux colonnes `tracktype` / `smoothness` de #895 avec l'alias de la CTE `followed` (`f.tags`, pas `w.tags`).
- **`WaysIndexReadTest.php`** — #894 et #895 avaient chacune ajouté un test au même endroit : **les deux sont conservés**, et les deux colonnes de #895 ont été portées sur l'oracle réécrit par #894.
- **`alerts.*.yaml`** — les clés supprimées par #896 (`alert.surface.fallback`, `alert.surface.missing_data`) ne sont pas réintroduites ; la ligne `alert.railway_station.action` ajoutée par #897 est préservée, et les reformulations de #895 / #899 conservées.
- **`mock-data.ts`** — #897 et #898 avaient inséré une fixture au même endroit : **les deux sont conservées**.
- **`CheckCalendarHandler` (#898 vs #899)** — #899 ajoutait la locale à `Yasumi::create('France', $year, $locale)`, que #898 remplace par `resolveProviders()` (multi-pays / multi-années). La restructuration de #898 gagne : elle porte déjà la locale jusqu'à `Yasumi::createByISO3166_2()`, donc l'intention de #899 est préservée. Côté test, les deux jeux coexistent — les 2 cas de locale de #899 ont été adaptés au contrat de #898 (clé `alerts` au lieu de `nudges`, dépôt de frontières administratives injecté), et `createHandler()` prend désormais les deux paramètres.
- **Test `batch-mode` (hors périmètre, corrigé dans #898)** — `recomputeResolve?.()` était un appel _optionnel_ : quand le handler `route` de Playwright n'avait pas encore été invoqué, la réponse n'était jamais délivrée et le panneau restait visible (échec 3 fois sur 4 sur cette branche, vert ailleurs). Bug latent du test, sans lien avec les alertes ; corrigé en attendant l'interception avant de libérer la réponse.
- **Arbitrage non prévu par les deux PRs — signaux de repli traduits.** #895 fait remonter `tracktype=grade4` / `smoothness=bad` comme pseudo-valeurs de surface dans le message, et #899 interdit toute valeur de tag brute dans un message traduit. Les deux étant désormais dans le même code, il fallait trancher : laisser passer la pseudo-valeur (fuite d'un tag brut, contraire au contrat de #899) ou la rabattre sur `surface.unknown` (perte de l'information de #895). **Choix retenu : 8 clés de traduction dédiées** (`surface.tracktype_grade3..5`, `surface.smoothness_bad..impassable`), et `translateSurface()` normalise le `=` en `_`. Le message reste informatif _et_ entièrement traduit. Les 5 tests de #895 qui assertaient la valeur brute ont été alignés.
- **Interaction #897 ↔ #899** — #897 assertait que le libellé d'action valait la _clé_ de traduction (son stub renvoyait la clé) alors que #899 branche le vrai `Translator` ; l'assertion a été alignée sur la chaîne rendue.

Note outillage : GitHub sait désormais gérer les [PRs empilées](https://docs.github.com/en/pull-requests/how-tos/stacked-pull-requests) — à utiliser pour les prochains sprints plutôt qu'une base `feature/<n>` manuelle.

### Recette Sprint 44

- **Tests E2E :** étendre `pwa/tests/mocked/alert-actions.spec.ts` (bouton d'action actif sur une alerte de terrain, aucun bouton pour un kind non câblé).
- **Checklist manuelle :**
  - [ ] Importer un tracé réel : aucune alerte ne mentionne la complétude d'OSM.
  - [ ] Les longueurs cumulées s'affichent en km au-delà de 1 000 m, avec un séparateur décimal français.
  - [ ] Aucun message ne contient de valeur de tag brute (`gravel`, `castle`), ni de nom de férié en anglais.
  - [ ] Une alerte de continuité affiche un bouton cliquable qui ouvre la position sur la carte.
  - [ ] Aucun bouton d'action désactivé n'apparaît.
  - [ ] Une étape longeant une voie verte parallèle à une nationale ne déclenche pas d'alerte de trafic.
  - [ ] Un jour de repos ne collecte plus d'alerte de terrain ni d'absence de service.
  - [ ] Pour une étape en France en juin, l'heure de coucher du soleil affichée correspond à l'heure locale.

</details>

<details><summary>

## ✅ Sprint 45 - Hébergements : débloquer l'existant

</summary>
Rendre exploitable ce qui est **déjà importé**. Le défaut dominant n'est pas l'absence de nom : **66,4 % des hébergements DataTourisme (82 523 sur 124 240) sont définitivement inatteignables** parce que `DataTourismeMapper::classify()` les rabat sur `apartment`, valeur absente de `TripRequest::ALL_ACCOMMODATION_TYPES`.

> **Prérequis :** aucun. Aucune source nouvelle, aucun ré-import de schéma.

| Ordre | ID | Titre | Effort | PRs | Statut | Dépend de |
|-------|----|-------|--------|-----|--------|-----------|
| 1 | [#865](https://github.com/vincentchalamon/bike-trip-planner/issues/865) | feat(datatourisme): map rental accommodations and drop the silent category default | - | [#906](https://github.com/vincentchalamon/bike-trip-planner/pull/906) `feature/865` | En cours | - |
| 2 | [#866](https://github.com/vincentchalamon/bike-trip-planner/issues/866) | fix(pwa): complete the accommodation label and icon maps | - | [#908](https://github.com/vincentchalamon/bike-trip-planner/pull/908) `feature/866` | En cours (empilée sur `feature/865`) | [#865](https://github.com/vincentchalamon/bike-trip-planner/issues/865) |
| 3 | [#867](https://github.com/vincentchalamon/bike-trip-planner/issues/867) | fix(pwa): guard malformed accommodation urls and translate the wikipedia link | - | [#904](https://github.com/vincentchalamon/bike-trip-planner/pull/904) `feature/867` | En cours | - |
| 4 | [#868](https://github.com/vincentchalamon/bike-trip-planner/issues/868) | fix(accommodations): deterministic ordering and bounded result sets | - | [#905](https://github.com/vincentchalamon/bike-trip-planner/pull/905) `feature/868` | En cours (2 tours de revue) | - |
| 5 | [#869](https://github.com/vincentchalamon/bike-trip-planner/issues/869) | feat(accommodations): rank candidates by completeness, use stars and capacity | - | [#909](https://github.com/vincentchalamon/bike-trip-planner/pull/909) `feature/869` | En cours (empilée sur `feature/868`, + correctif jour de repos) | [#868](https://github.com/vincentchalamon/bike-trip-planner/issues/868) |
| 6 | [#870](https://github.com/vincentchalamon/bike-trip-planner/issues/870) | fix(trips): stop dropping accommodation enrichment on persist | - | [#907](https://github.com/vincentchalamon/bike-trip-planner/pull/907) `feature/870` | En cours | - |

> **#866 a été empilée sur #865 alors que la table ne déclarait aucune dépendance.** L'issue disait « à livrer avec ou après l'issue `rental` », et les deux refont les mêmes maps de `accommodation-item.tsx` plus le contrat `accommodation-types.ts` : les traiter en parallèle aurait payé ce conflit au merge.

### Ordre de merge et conflits attendus

**Ordre imposé** (les deux piles d'abord, dans l'ordre) :

1. **#906 (#865)** puis **#908 (#866)** — #908 a pour base `feature/865`.
2. **#905 (#868)** puis **#909 (#869)** — #909 a pour base `feature/868`.
3. **#904 (#867)**, **#907 (#870)** — indépendantes, dans n'importe quel ordre.

Après le squash-merge d'un parent, GitHub retargette l'enfant sur `main` mais la branche enfant porte encore le commit pré-squash du parent : ne **pas** faire un `git rebase origin/main` direct, utiliser `git rebase --onto origin/main <dernier-commit-du-parent> feature/<enfant>`, puis vérifier que `git log origin/main..HEAD` ne liste que les commits de l'enfant.

**Fichiers partagés et arbitrages décidés avant le merge :**

| Fichier | PRs | Qui gagne |
|---------|-----|-----------|
| `pwa/src/components/accommodation-item.tsx` | #906, #908, #904 | **#908 gagne sur les maps libellés / icônes** (elle les supprime au profit d'une dérivation du contrat, donc l'entrée `rental` ajoutée par #906 devient sans objet). #904 ne touche que le bloc de rendu de l'URL, le lien Wikipédia et `commitEdits` : **conserver les deux côtés**, conflit probable sur les imports en tête de fichier. |
| `pwa/src/lib/accommodation-types.ts` | #906, #908 | Pas de conflit : #908 est empilée sur #906. |
| `pwa/messages/{fr,en}.json` | #906, #904 | Purement additif, clés disjointes (`type_rental` vs `see_on_wikipedia`) : **conserver les deux**. #908 ne touche pas ces fichiers. |
| `pwa/tests/fixtures/mock-data.ts` | #904, #907 | Additif disjoint sur les **deux mêmes** hébergements : #904 ajoute `url`, #907 ajoute `description` / `imageUrl` / `source`. **Conserver les deux.** Ne pas ajouter de troisième entrée : `accommodation-hover.spec.ts` asserte `toHaveCount(2)`. |
| `pwa/tests/mocked/accommodation.spec.ts` | #904, #907 | Les deux ajoutent un test en fin de fichier : **conserver les deux**. |
| `api/src/{Osm,Tourism}/AccommodationRepository.php` | #905, #909 | **Le SQL de #905 gagne** (fenêtre partitionnée par point le plus proche, assignation en mètres). #909 ne change que le docblock de `MAX_ROWS_PER_POINT` (marge 10x → 6x, `MAX_CANDIDATES_PER_STAGE` 3 → 5) : reporter ce commentaire. **Déjà résolu** par rebase de `feature/869` sur les deux tours de correction. |
| `api/src/Osm/WktGeometry.php` | #905 | Hors liste « Fichiers impactés » de l'issue : `array_unique` retiré de `multiPoint()`. Ses deux seuls appelants sont les deux repositories. `lineStringOrPoint()` garde son dedup. |
| `api/src/Geo/GeometryBasedDistributor.php` | #909 | Hors liste « Fichiers impactés » de l'issue : correctif du jour de repos. `distributeByGeometry` est volontairement laissé en `<` strict. |
| `api/tests/Integration/Tourism/TourismIndexReadTest.php` | #906, #905 | Additif des deux côtés (#906 ajoute la catégorie `rental`, #905 deux tests de famine) : **conserver les deux**. |
| `api/tests/Unit/AccommodationSource/DataTourismeAccommodationSourceTest.php` | #906, #909 | Cas ajoutés en fin de classe des deux côtés : **conserver les deux**. |

**Deux régressions trouvées par la CI, pas par les agents** — à garder en tête pour les prochains sprints :

- **#906 : le drift `schema.d.ts`.** Le `@description` d'un `ApiProperty` se propage dans les types générés. Un changement de description **seule** suffit à faire échouer le job « OpenAPI → TS drift » : `make typegen` est nécessaire même quand la forme du schéma ne bouge pas.
- **#906 : le scénario BDD « Filtrage des types d'hébergement » codait en dur `toHaveCount(9)`** et ignorait la liste Gherkin. Ajouter un type au contrat le cassait sans dire lequel manquait. Le step asserte désormais le compte **et** chaque libellé depuis la chaîne de la feature. Au passage : **la feature `.en` fait tourner l'application en anglais**, alors que sa chaîne portait des libellés français jamais vérifiés — corrigé, les deux features listent maintenant les libellés de leur propre catalogue.

**Trois tours de correction issus de la revue automatique, tous sur la même chaîne de famine :**

1. **#905, premier jet** — le `LIMIT` était **global** sur le MULTIPOINT combiné : une étape dense pouvait affamer une étape rurale (reproduit : 60 lignes renvoyées, **0** pour le point isolé). Le LATERAL par point suggéré par la revue a été **écarté avec mesures** (7 passages sur la table, 1119 ms contre 154 ms) et surtout parce qu'il ne corrige pas complètement la famine, `distributeByEndpoint` attribuant au point **le plus proche** et non au rayon. Forme retenue : `ROW_NUMBER() OVER (PARTITION BY <point le plus proche>)`.
2. **#905, second tour** — la métrique `<->` sur `geometry` compare des degrés WGS84, pas la Haversine en mètres utilisée en aval : une ligne de bissectrice pouvait être partitionnée sous le mauvais point et disparaître. Corrigé par un cast `geography`, avec l'écart résiduel quantifié à **1,4 ppm** (rayon terrestre 6 371 000 m contre 6 371 008,8 m), soit un facteur multiplicatif constant incapable de réordonner deux distances. Au même tour, `WktGeometry::multiPoint()` a perdu son `array_unique` — mais l'effondrement de deux sommets identiques a été démontré **behaviouralement neutre pour cette requête** (deux sommets identiques sont le même lieu, donc le départage envoie tout au premier), et le test correspondant est versé comme garde-fou, pas comme démonstration de bug.
3. **#909** — le vrai bug du jour de repos était en aval : `GeometryBasedDistributor::distributeByEndpoint` utilisait un `<` **strict**, donc pour deux étapes au même point d'arrivée la première raflait tous les candidats et la seconde recevait **zéro hébergement**. Aggravant : un scan complet vide `$stage->accommodations` avant de repersister, donc un jour de repos perdait aussi les hébergements obtenus par un scan mono-étape. Comportement retenu : **les mêmes candidats aux deux étapes** ex æquo, pas un partage — on dort deux nuits au même endroit, et `selectedAccommodation` étant par étape, le même établissement doit apparaître dans les deux listes. `distributeByGeometry` **garde** son `<` strict (POI, points d'eau, bacs, gués, événements : dupliquer un élément croisé en route est une autre question).

**Flake CI à surveiller :** `pwa/tests/mocked/landing-page.spec.ts:194` (« stale cookie (refresh fails) falls back to the landing (#649) ») a échoué deux fois pendant ce sprint, sur #904 et sur #905, avec `strict mode violation: getByTestId('landing-page') resolved to 2 elements` — deux pages de destination momentanément dans le DOM. Vert à la relance les deux fois, et sans rapport avec les diffs concernés (celui de #905 est purement PHP). À traiter séparément si la fréquence augmente.

### Recette Sprint 45

- **Tests E2E :** faits. `pwa/tests/mocked/accommodation.spec.ts` couvre le lien de site (#904) et le rechargement après scan avec badge de source et vignette (#907). Les deux hébergements de `pwa/tests/fixtures/mock-data.ts` portent désormais `url` (#904) et `description` / `imageUrl` / `source` (#907) — **ne pas en ajouter un troisième**, `accommodation-hover.spec.ts` asserte `toHaveCount(2)`.
- **Checklist manuelle :**
  - [ ] `SELECT category, count(*) FROM tourism.accommodations GROUP BY 1` ne montre plus de bucket `apartment` fourre-tout.
  - [ ] Le type gîte / meublé est **décoché par défaut** sur un nouveau voyage ; le cocher fait apparaître des gîtes.
  - [ ] Un voyage créé avant le déploiement conserve exactement le même jeu de types.
  - [ ] Un hébergement de type abri s'affiche « Abri », plus jamais « Autre ».
  - [ ] Un hébergement dont le site OSM est saisi sans schéma (`www.hotel.fr`) n'empêche pas le rendu et produit un lien fonctionnel.
  - [ ] Deux scans successifs du même voyage retournent exactement les mêmes hébergements, dans le même ordre.
  - [ ] Après rechargement de la page, la fiche conserve vignette, description, lien Wikipédia et badge de source.

</details>

<details><summary>

## ✅ Sprint 46 - Lien de vérification et données OSM

</summary>
Le lien et le téléphone cessent d'être du confort : depuis la décision d'obsolescence assumée, c'est l'utilisateur qui réserve donc qui vérifie, et ce sont **ses outils de vérification**. Ce sprint récupère aussi ce que le flux DataTourisme jette au mappage.

> **Prérequis :** #876 dépend de #861 (sprint 44) : la liste des codes d'alerte doit refléter les règles conservées. **Dépendance inter-sprint, invisible pour `/sprint` :** la colonne `Dépend de` ne référence que des issues de la même table. À respecter manuellement.

| Ordre | ID | Titre | Effort | PRs | Statut | Dépend de |
|-------|----|-------|--------|-----|--------|-----------|
| 1 | [#871](https://github.com/vincentchalamon/bike-trip-planner/issues/871) | fix(datatourisme): stop truncating the flux tags to the type list | - | [#915](https://github.com/vincentchalamon/bike-trip-planner/pull/915) `feature/871` | ✅ Mergée | - |
| 2 | [#872](https://github.com/vincentchalamon/bike-trip-planner/issues/872) | feat(datatourisme): add website, phone, wikidata and opening hours to tourism accommodations | - | [#917](https://github.com/vincentchalamon/bike-trip-planner/pull/917) `feature/872` | En cours (rebasée sur `main`, **porte aussi #873**) | [#871](https://github.com/vincentchalamon/bike-trip-planner/issues/871) ✅ |
| 3 | [#873](https://github.com/vincentchalamon/bike-trip-planner/issues/873) | feat(api): expose accommodation url, phone and osm identity | - | [#918](https://github.com/vincentchalamon/bike-trip-planner/pull/918) `feature/873` | ✅ Mergée **dans `feature/872`**, pas dans `main` | [#872](https://github.com/vincentchalamon/bike-trip-planner/issues/872) |
| 4 | [#874](https://github.com/vincentchalamon/bike-trip-planner/issues/874) | fix(poi): translated labels for unnamed pois and the dedup collision they cause | - | [#913](https://github.com/vincentchalamon/bike-trip-planner/pull/913) `feature/874` | ✅ Mergée | - |
| 5 | [#875](https://github.com/vincentchalamon/bike-trip-planner/issues/875) | feat(poi): use real opening hours instead of hardcoded schedules | - | [#914](https://github.com/vincentchalamon/bike-trip-planner/pull/914) `feature/875` | En cours (rebasée sur `feature/872`, conflits résolus) | - |
| 6 | [#876](https://github.com/vincentchalamon/bike-trip-planner/issues/876) | feat(alerts): stable alert code and hardened documentation test | - | [#916](https://github.com/vincentchalamon/bike-trip-planner/pull/916) `feature/876` | En cours (rebasée sur `feature/875`, conflit résolu) | - |

> **#876 dépendait de #861 (sprint 44), dépendance inter-sprint que la table ne sait pas exprimer.** Le sprint 44 étant mergé sur `main`, la contrainte était satisfaite en branchant sur `main` : la liste des `AlertCode` reflète les règles réellement conservées après suppression des règles de présence de tag.

### Ordre de merge — conflits résolus, chaîne linéaire

> **Mis à jour après le premier tour de merges (03/08/2026, 15h12).** #915 (#871), #913 (#874) et #919 sont dans `main` ; #918 (#873) a été mergée **dans `feature/872`** et non dans `main`. Les trois PRs restantes ont été **rebasées** et **tous les conflits sont résolus** : `git merge-tree` ne signale plus rien entre aucune paire.

**Chaîne actuelle, à merger dans cet ordre :**

```text
main ──▶ feature/872 (#917, porte #872 + #873) ──▶ feature/875 (#914) ──▶ feature/876 (#916)
```

1. **#917** — 2 commits au-dessus de `main`.
2. **#914** — base `feature/872`, 2 commits propres.
3. **#916** — base `feature/875`, 2 commits propres.

Après chaque squash-merge, la branche enfant portera encore les commits pré-squash de son parent : ne **pas** faire un `git rebase origin/main` direct, utiliser `git rebase --onto origin/main <dernier-commit-du-parent> feature/<enfant>`, puis vérifier que `git log origin/main..HEAD` ne liste que les commits de l'enfant. C'est exactement ce qui a été fait pour `feature/872`, dont le commit `6aea40b3` de #871 doublonnait le squash de #915.

**Activer `git rerere`** (`git config rerere.enabled true`) : les résolutions de cette chaîne y sont enregistrées et se rejouent seules à chaque rebase suivant. Elles l'ont fait sur les deux rebases successifs de #914 et #916.

**Résolutions appliquées** (les 12 conflits mesurés ; conservées ici pour la reproductibilité) :

| Fichier | PRs | Qui gagne |
|---------|-----|-----------|
| `api/src/Poi/PoiSourceInterface.php`, `PoiSourceRegistry.php` | #913, #914 | **Union des deux élargissements**, aucun arbitrage à rendre : #913 passe `name` à `string\|null`, #914 ajoute `openingHours` et `website` à la même forme `list<array{...}>`. Les deux modifications sont orthogonales, **conserver les deux**. |
| `api/src/CulturalPoiSource/CulturalPoiSourceInterface.php`, `CulturalPoiSourceRegistry.php`, `OsmCulturalPoiSource.php`, `DataTourismeCulturalPoiSource.php`, `api/tests/Unit/Poi/PoiSourceRegistryTest.php` | #913, #914 | Même arbitrage : union. Ce sont les 5 autres fichiers en conflit des **7 mesurés** entre ces deux PRs — le docblock de la fixture de test conflicte lui aussi, sur le même motif. |
| `api/src/Osm/PoiRepository.php`, `PoiRepositoryInterface.php`, `CulturalPoiRepository.php`, `CulturalPoiRepositoryInterface.php` | #914, #918 | **4 fichiers en conflit**, disjoints par construction : #914 ajoute `opening_hours` / `website` au `SELECT` **et au `@return` des interfaces**, #918 ajoute `osm_type` / `osm_id` au **même** `SELECT` et au **même** `@return`. Ni l'une ni l'autre ne réécrit la requête. **Conserver les deux.** |
| `README.md` (tableau du moteur d'alertes) | #914, #916 | **La restructuration de #916 gagne** (une ligne par `AlertCode`, 36 insertions / 29 suppressions). Y **reporter** la reformulation de #914 sur la ligne devenue `resupply_closed_at_passage` : « …are **known to be** closed at estimated passage time (a POI whose OpenStreetMap `opening_hours` is missing or unparsable is treated as unknown and suppresses the warning) ». Seul conflit entre ces deux PRs. |
| `api/src/MessageHandler/ScanPoisHandler.php` | #913, #914, #916 | Trois diffs disjoints, aucun conflit mesuré : #913 le libellé de POI, #914 la résolution des horaires, #916 la ligne `'code' => …`. **Conserver les trois.** |
| `api/src/MessageHandler/CheckCulturalPoisHandler.php` | #913, #914, #916 | Idem, aucun conflit mesuré. |
| `api/src/Repository/DoctrineTripRequestRepository.php` | #914, #916, #918 | Fichier partagé par **trois** PRs, mais trois méthodes différentes et **aucun conflit mesuré** : #914 dans l'aller-retour des POI (`poiToArray` / son pendant, ajout d'`openingHours` et `website`), #916 dans `alertToArray()` / `arrayToAlert()`, #918 dans `accommodationToArray()`. **Conserver l'union des trois.** |
| `pwa/src/lib/validation/schemas.ts`, `pwa/src/lib/mercure/types.ts` | #916, #918 | Ajouts additifs (`code` sur l'alerte, `phone` / `osmType` / `osmId` sur l'hébergement). **Aucun conflit mesuré** : les deux paires de PRs partagent 9 fichiers et `git merge-tree` n'en signale aucun. Rien à arbitrer. |
| `pwa/src/lib/api/schema.d.ts` | #916, #918 | **Aucun conflit mesuré** non plus. Mais **si** un conflit apparaît après un rebase, ne jamais le résoudre à la main : prendre n'importe quel côté puis régénérer (`make typegen`, ou le repli documenté dans CLAUDE.md en worktree). Régénérer après le merge du second reste prudent, un `@description` suffisant à faire dériver le fichier. |
| `api/translations/alerts.{fr,en}.yaml` | #913 | Seule #913 y touche (17 clés `poi_type.*` plus `alert.cultural_poi.suggestion_unnamed`). Pas de conflit. |

**Un point de sémantique que le merge ne signalait pas — vérifié au rebase, il tient.** #913 introduit `alert.cultural_poi.suggestion_unnamed`, une **seconde clé de traduction pour la même règle**, tandis que #916 crée l'enum `AlertCode`. Les deux clés devaient partager le code `cultural_poi_suggestion`, le code identifiant la **règle** et non la clé i18n. Après rebase, `CheckCulturalPoisHandler` a bien la forme voulue — la clé bifurque sur le nom, le code reste unique et hors de la branche — et `AlertDocumentationTest` passe. Aucun cas d'enum supplémentaire n'a été nécessaire.

**Deux conflits sémantiques que git a fusionnés proprement**, invisibles à `merge-tree` et rattrapés seulement par PHPStan puis PHPUnit lors du rebase de #914 :

1. `api/tests/Unit/MessageHandler/ScanPoisHandlerTest.php` — une signature de helper restée en `name: string` alors que #913 y passe `null`, et une fixture privée des clés `openingHours` / `website` que le handler de #914 lit désormais. PHPStan : `Array does not have offset 'openingHours'`.
2. `api/tests/Unit/Poi/OsmPoiSourceTest.php` — même omission, qui **ne faisait pas rougir la suite** : elle sortait en `Warnings: 2` (`Undefined array key "website"`) sous un `OK` vert. Corrigé en complétant les fixtures et non en assouplissant le type, le contrat de source exigeant bien ces clés.

C'est la confirmation de la leçon du sprint 45 : la carte de recoupement attrape les conflits de **fichier**, pas les conflits de **valeur**. Le garde-fou utile n'est pas `merge-tree` mais **PHPStan puis PHPUnit relancés après chaque rebase** — et lire les avertissements PHPUnit, pas seulement le statut final.

**Deux retours de revue automatique qui étaient de vrais défauts, pas du bruit :**

1. **#914 — fausse fermeture sur un franchissement de minuit partiel.** `OpeningHours` repliait la queue d'une plage `Fr 18:00-02:00` dans le **même** jour. Samedi ne recevait donc aucune règle et retombait sur le « pas de règle ⇒ fermé » : `isOpenAt(1.0, 6)` renvoyait `false`, une fermeture que rien n'établit — exactement la classe de faux positif que la PR se donnait pour but d'éliminer, et sur les heures de passage très matinales. Le repli n'est désormais appliqué que si les 7 jours portent la même règle, sinon le lecteur renvoie « inconnu ». La justification de sûreté du docblock (« ne fait qu'élargir la fenêtre ») n'était vraie que dans ce cas et a été réécrite.
2. **#916 — la garantie centrale n'était pas testée.** La PR affirmait qu'une reformulation ne change pas la clé de rejet, mais `alertKey()` était privée au module et sans test. Exportée et couverte sur quatre cas, dont la stabilité à la reformulation et la distinction de deux variantes d'une même famille.

**Faux rouge systémique à traiter séparément :** le job `claude-review` **échoue** sur #914 et #916 alors que les deux revues ont bien été publiées et sont **approuvées**. Cause : l'étape « Check for permission denials » sort en `exit 1` dès que le bot tente un outil hors allowlist — un `Edit` sur #914, une commande `Bash` d'expérimentation sur #916. Déjà observé au sprint 45 sur les PRs rebasées. Ce n'est pas un signal de qualité de code : soit élargir l'allowlist du workflow, soit ne pas faire échouer le job sur un refus de permission. Mérite son propre ticket.

**Contention de base de données entre agents parallèles :** les six agents partageaient la stack de dev, et les `ResetDatabase` concurrents de Foundry ont laissé `app_test` à demi-migrée (`postgis` installée, migrations non enregistrées → salves de `ConnectionLost` et `schema "osm" does not exist` sans rapport avec les diffs). La parade retenue, à généraliser : chaque agent pointe `DATABASE_URL` sur une base dédiée (`app871`, `app872`…, auto-suffixée `_test`), que Foundry crée et migre seule — ce qui prouve au passage que la migration de la branche s'applique.

### Recette Sprint 46

- **Tests E2E :** lien `tel:` et lien « voir sur OSM » présents sur la fiche ; rejet d'alerte persistant après reformulation d'un message.
- **Checklist manuelle :**
  - [ ] `SELECT count(*) FROM tourism.accommodations WHERE website IS NOT NULL` est non nul après ré-import.
  - [ ] `SELECT count(*) FROM tourism.cultural_pois WHERE website IS NOT NULL` est non nul (la colonne existait et n'était jamais peuplée).
  - [ ] Un hébergement DataTourisme portant un `foaf:homepage` affiche un lien de site.
  - [ ] Un hébergement OSM portant `contact:phone` affiche un lien `tel:` cliquable.
  - [ ] Le lien « voir sur OSM » pointe sur le bon objet selon `osm_type` (node, way, relation).
  - [ ] Deux POI anonymes de même catégorie à moins de 75 m sont **tous les deux** conservés.
  - [ ] Aucun POI ne s'affiche avec un slug OSM brut comme nom.
  - [ ] Un POI sans horaires connus ne déclenche pas l'alerte de créneau de ravitaillement.
  - [ ] `AlertDocumentationTest` échoue si un code est émis sans ligne de README, et réciproquement.

</details>

<details><summary>

## ✅ Sprint 47 - Mesure et spikes

</summary>
Sprint volontairement court : ce sont des mesures, et elles **arbitrent les sprints 49 et 50**. Sans chiffres, on supprime des règles et on choisit des sources à l'intuition, et c'est exactement ce qui a produit une inférence fausse pendant le diagnostic (« un rayon de 15 km sans pharmacie est un trou d'index » était une supposition, probablement démentie par la couverture OSM réelle).

> ⚠️ **Ce sprint ne passe pas par `/sprint`.** #878 et #879 sont des spikes en lecture seule : ils ne produisent aucun commit, alors que le pipeline attend une branche, un `make qa`, un commit et une PR. À traiter manuellement, ou à convertir en issues produisant un rapport committé sous `docs/`. Seule #877 est du code. #878 et #879 ont été traitées par cette seconde voie : leurs rapports sont committés sous [`docs/audit/`](docs/audit/878-hebergements-osm-sans-nom.md) et [`docs/`](docs/datatourisme-flux-audit.md).
>
> Ces deux spikes sont des **mesures à exécuter** sur un jeu de données provisionné, pas des développements. Ils supposent donc une zone déjà ouverte, en local suffit.

| Ordre | ID | Titre | Effort | PRs | Statut | Dépend de |
|-------|----|-------|--------|-----|--------|-----------|
| 1 | [#877](https://github.com/vincentchalamon/bike-trip-planner/issues/877) | feat(provisioner): per-table completeness metrics in metadata and health | - | [#926](https://github.com/vincentchalamon/bike-trip-planner/pull/926) `feature/877` | Mergée | - |
| 2 | [#878](https://github.com/vincentchalamon/bike-trip-planner/issues/878) | chore(quality): measure unnamed osm accommodations per category | - | [#924](https://github.com/vincentchalamon/bike-trip-planner/pull/924) | Mergée | - |
| 3 | [#879](https://github.com/vincentchalamon/bike-trip-planner/issues/879) | chore(datatourisme): audit flux fields for accueil-velo and minimum stay | - | [#925](https://github.com/vincentchalamon/bike-trip-planner/pull/925) | Mergée | - |
| 4 | [#927](https://github.com/vincentchalamon/bike-trip-planner/issues/927) | feat(accommodations)!: retirer shelter, motel et rental du vocabulaire d'hébergement | - | [#928](https://github.com/vincentchalamon/bike-trip-planner/pull/928) | Mergée | dérive de [#878](https://github.com/vincentchalamon/bike-trip-planner/issues/878) |

> **#927 est née de la recette de ce sprint** : la mesure de #878 (6 129 des 8 062 `shelter` sont du mobilier urbain, `motel` = 11 lignes) a fait sortir `shelter` et `motel` du vocabulaire d'hébergement, `rental` suivant sur décision produit. Les lignes `category = 'shelter'` **restent importées** pour le seul lecteur in-ride (`InRidePoiRepository.php:33`) : seul l'appariement `AccommodationRepository::NON_LODGING_CATEGORIES` les écarte de la recherche d'hébergement.

### Recette Sprint 47

- **Tests E2E :** aucun (métriques internes, non exposées à l'utilisateur).
- **Checklist manuelle :**
  - [x] `/api/health` expose les ratios de complétude par table sous `reference_data`, en dépendance non requise — `completeness` et `rejections` décodés dans le payload, `reference_data` absent de `$required` donc la sonde reste 200 index non provisionné ; `HealthControllerTest` vert (12 tests, 63 assertions) contre un vrai Postgres.
  - [x] `/api/health` expose un âge par source **sans** verdict de péremption ; `STALE_THRESHOLDS` a disparu — introduite par #705, supprimée par #926 (`git log -S`), plus aucune occurrence dans `api/`, `provisioner/`, `pwa/` ; deux tests asseyent l'absence de la clé `stale` sur un index vieux de 100 jours.
  - [x] Les hébergements exposent le détail par catégorie, condition pour arbitrer l'exclusion des entrées sans nom — `COMPLETENESS_BY_CATEGORY = ['accommodations']` dans les deux importeurs ; l'expression générée, exécutée sur l'index local réel, sort bien le détail par catégorie (`shelter` 8 062 lignes / 36,45 % nommées, `wilderness_hut` 316 / 93,67 %, `hotel` 2 706, `motel` 11).
  - [x] Le décompte des hébergements OSM sans nom, par catégorie, est publié dans #878 avec une recommandation explicite sur `shelter` et `wilderness_hut` — contrainte de complétude partout sauf `shelter`, pas d'exemption pour `wilderness_hut` (6,3 % sans nom) ([rapport](docs/audit/878-hebergements-osm-sans-nom.md)).
  - [x] La présence ou l'absence du label « Accueil Vélo » dans le flux est tranchée par une mesure dans #879 — présent : `kb:LabelRating_AccueilVelo` sur 8 010 objets dont 6 006 hébergements, sous `hasReview[].hasReviewValue` ([rapport](docs/datatourisme-flux-audit.md)).
  - [x] Les métriques n'allongent pas notablement la durée du provisionnement — mesuré dans #926 sur l'index DataTourisme (**+150 ms** sur 324 128 lignes, décomptes seuls 49-61 ms contre 201-254 ms avec la complétude) et **confirmé sur le vrai index OSM** (l'auto-critique de #926 extrapolait, le schéma `osm` local étant vide au moment de la PR) : l'expression complète des 12 tables + le détail par catégorie coûte **133 ms à froid, 53 ms à chaud** contre un import `osm2pgsql` en minutes.

### Vérification de complétude du 04/08/2026

Les trois issues sont **closes**, les quatre PRs (#924, #925, #926, #928) **mergées** sur `main`, aucune issue `sprint-47` n'est restée ouverte. Preuves réexécutées sur `main` à `46c048a8` :

- `HealthControllerTest` : `OK (12 tests, 63 assertions)`.
- Provisionner : `Tests: 101, Assertions: 555, Skipped: 1` (le skip préexiste).
- API `tests/Unit` + `tests/Integration` : `Tests: 1491, Assertions: 4130, Skipped: 1`.
- Playwright reste la charge de la CI (le PWA du worktree n'est pas dans le bundle servi en local).

Trois constats à reporter, aucun ne remet en cause la livraison :

1. **L'index local précède le code.** `osm.metadata` / `tourism.metadata` ont été provisionnées avant #877, donc `/api/health` renvoie aujourd'hui `completeness: []` : c'est le repli `SELECT *` qui joue (un index d'avant les colonnes continue de publier ses décomptes au lieu de passer pour non provisionné, comportement épinglé par un test). Les vrais ratios n'apparaîtront qu'après le prochain provisionnement. Les deux migrations `Version20260804120000` et `Version20260805120000` n'étaient pas appliquées en local, elles l'ont été pendant cette vérification.
2. **`admin_boundaries` compte 0 ligne** sur le jeu local : c'est exactement le défaut mis en tête de la recette du sprint 48 (#880), confirmé par la mesure. `osm.coverage` est donc vide et tout voyage est hors zone.
3. **Deux tickets de suivi annoncés ne sont pas ouverts** : le nettoyage de la couche abri (liste blanche `shelter_type`, 6 129 lignes de bruit, annoncé par #927) et l'index GiST sur `(geom::geography)` du Tier-1 (constat du sprint 45). Par ailleurs les recommandations 3 et 5 de l'audit #879 (« ne pas ajouter de source de label vélo », « `nationalAddressId` est vide, une jointure BAN doit partir de l'adresse postale ») visent directement #887 et #888 sans y être reportées en commentaire, contrairement à ce qui a été fait pour #884 et #865.

</details>

<details><summary>

## Sprint 48 - Découplage du routage, index administratif

</summary>
Prépare l'ouverture par zone. `default.osm.pbf` a aujourd'hui **deux consommateurs** reliés par un simple chemin de fichier : la source de l'import PostGIS et l'**unique entrée de Valhalla** (`compose.yaml:346`). Avec une zone par exécution, ouvrir la Bretagne **casserait le routage** en Hauts-de-France.

> **Prérequis :** #882 dépend des mesures du sprint 47. L'ADR est écrit **en dernier** : rédiger la décision avant d'avoir les chiffres la figerait sur une intuition.
>
> ⚠️ **#881 requiert une vérification manuelle.** Elle supprime `ensure-default-pbf`, prérequis de `make start-dev` **et** de `make start` (`Makefile:34` et `:45`). Un agent worktree lançant `make qa` ne peut pas le valider, et `make test` ne démarre pas le profil `routing`.
>
> **Code contre exécution.** Les issues livrent le mécanisme ; les commandes de la checklist ci-dessous **vérifient** ce mécanisme, elles ne constituent pas le livrable. La procédure récurrente de construction du graphe est documentée par le runbook réécrit dans #881, et son pendant pour les données de référence par #891.

| Ordre | ID | Titre | Effort | PRs | Statut | Dépend de |
|-------|----|-------|--------|-----|--------|-----------|
| 1 | [#880](https://github.com/vincentchalamon/bike-trip-planner/issues/880) | feat(provisioner): import administrative levels for offline locality labels | - | [#941](https://github.com/vincentchalamon/bike-trip-planner/pull/941) `feature/880` | En cours | - |
| 2 | [#881](https://github.com/vincentchalamon/bike-trip-planner/issues/881) | refactor(routing): decouple the valhalla dataset from the provisioner | - | [#942](https://github.com/vincentchalamon/bike-trip-planner/pull/942) `feature/881` | En cours | - |
| 3 | [#882](https://github.com/vincentchalamon/bike-trip-planner/issues/882) | docs(adr): add adr-049 zone opening and import-time completeness | - | [#943](https://github.com/vincentchalamon/bike-trip-planner/pull/943) `feature/882`, empilée sur `feature/881` | En cours | [#880](https://github.com/vincentchalamon/bike-trip-planner/issues/880), [#881](https://github.com/vincentchalamon/bike-trip-planner/issues/881) |

### Ordre de merge et conflits attendus

Ordre imposé : **#941, puis #942, puis #943**. #943 est une PR **empilée** dont la base est `feature/881` : GitHub la reciblera sur `main` au squash-merge de #942, mais la branche portera encore les commits pré-squash de #881, donc rejouer ses commits propres avec `git rebase --onto origin/main <dernier commit de 881> feature/882` plutôt qu'un rebase direct sur `main`.

| Fichier | #941 (#880) | #942 (#881) | #943 (#882) | Arbitrage |
|---|---|---|---|---|
| `docs/adr/adr-040-*.md` | puce « Coverage polygon » (union de tous les niveaux) | — | ligne de statut (renvoi vers 049) | **Hunks disjoints** : ligne 3 contre puce en milieu de fichier. Aucun conflit attendu ; si git en signale un, garder les deux. |
| `docs/adr/adr-017-*.md`, `adr-036-*.md` | — | amendement #881 (build/serve séparés) | renvoi vers ADR-049 §6 | #943 est empilée sur #942, donc déjà résolu dans l'ordre de merge. Ne pas merger #943 avant #942. |
| `README.md` | — | section « OSM provisioning » (deux jeux de données) | renvoi ADR-049 dans la même section | Idem : la pile porte la résolution. |
| `provisioner/src/PostgisImporter.php` | `TAGS_FILTER_EXPRESSIONS` + SQL de couverture | **non touché** (seul le nom de variable de l'appelant change) | — | #941 gagne. Recoupement évité par construction. |
| `provisioner/src/ProvisionCommand.php` | non touché | `DEFAULT_REFERENCE_PBF`, messages | — | #942 gagne. |
| `provisioner/tests/` | `PostgisImporterTest.php` | `ProvisionCommandTest.php`, `GeofabrikRegionRegistryTest.php` | — | Fichiers disjoints. |
| `TRACKING.md` | — | 1 ligne (lien du runbook renommé) | — | Ce même fichier est modifié par la présente PR de tracking : **conflit attendu sur `TRACKING.md`**, garder les deux modifications (elles portent sur des sprints différents). |

> ⚠️ **Ce sprint n'est pas passé par des agents worktree.** L'API a refusé les subagents en boucle (`529 Overloaded`, sept démarrages tués, dont trois avant tout commit). Le code a été terminé, vérifié et livré en direct, séquentiellement, dans les worktrees déjà créés. Aucune incidence sur le contenu ; la vérification est celle documentée dans chaque PR.

### Recette Sprint 48

- **Tests E2E :** aucun. La vérification vit dans la checklist manuelle.
- **Checklist manuelle :**
  - [ ] `SELECT count(*) FROM osm.admin_boundaries WHERE admin_level = 2` sur le jeu local mono-slug. **Si le compte est nul**, `osm.coverage` est vide, tout voyage est « hors zone » et aucun pays n'est résolu : défaut à corriger en priorité.
  - [ ] La résolution de localité d'un point fonctionne **sans appel réseau**.
  - [ ] L'impact sur la taille du PBF filtré et sur la durée d'`osm2pgsql` est mesuré et documenté.
  - [ ] `make routing-build france` construit un graphe fonctionnel, indépendamment de toute ouverture de zone.
  - [ ] `make provision <zone>` ne touche plus aux tuiles ni au PBF de routage.
  - [ ] `make start-dev` **et** `make start` démarrent depuis un état propre, sans `default.osm.pbf` ni stub.
  - [ ] Le service `valhalla` ne construit plus rien et son `start_period` est de l'ordre de quelques secondes.
  - [ ] Un calcul d'itinéraire réel aboutit après `make routing-build`.
  - [x] ADR-049 est bien le prochain numéro libre (048 réservé par #527, Sprint 39) — vérifié dans #943 : `docs/adr/` s'arrêtait à 047, aucune occurrence de `ADR-048`/`adr-048` ni `049` dans `docs/`, `README.md`, `CLAUDE.md`.

> **Ce qui reste manuel, et pourquoi.** Les six premières cases sont des exécutions, pas du code. La première (`count(*) WHERE admin_level = 2`) est déjà **mesurée** : le compte est nul sur le jeu local, et la correction est dans #941 ([mesure publiée dans l'issue](https://github.com/vincentchalamon/bike-trip-planner/issues/880#issuecomment-5192616478)) — la case ne se cochera qu'après un **nouveau provisionnement**, l'index local précédant le code. Les cases de démarrage propre (`make start-dev` / `make start` sans PBF ni stub) et de construction de graphe n'ont **pas** pu être vérifiées : un worktree obtient son propre projet compose et la stack principale occupe les ports. La procédure exacte à rejouer est écrite dans le corps de #942. Playwright reste la charge de la CI.
>
> **Conséquence à connaître avant de merger #942 :** `/api/health` compte `valhalla` parmi ses dépendances **requises** (`HealthController.php:87`) et `make start-dev` ne démarre plus le profil `routing`. Une stack de dev sans graphe répondra donc `degraded` — exact, mais c'est un changement d'état par défaut. Le stub Lille donnait un graphe minuscule en secondes ; un vrai graphe France coûte des heures (`make routing-build nord-pas-de-calais` pour un essai rapide).

</details>

<details><summary>

## Sprint 49 - Ouverture par zone, cache et gate

</summary>
Le cœur du chantier : ouverture manuelle zone par zone, cache d'enrichissement persistant, gate de complétude. Remplace le rebuild complet suivi d'un swap global de schéma, qui coûte aujourd'hui un ré-import de tout le jeu à chaque ajout de zone.

> **Prérequis :** #881 (routage découplé, sprint 48) et #878 (mesure du « sans nom », sprint 47, qui arbitre le traitement de `shelter` et `wilderness_hut`). **Dépendances inter-sprints, invisibles pour `/sprint`.**
>
> **Ce sprint livre le mécanisme, pas l'exécution.** Distinction à ne pas confondre : les issues ci-dessous sont du **code** (registre en base, argument obligatoire, schéma de staging paramétrable, anti-join, promotion transactionnelle en remplacement du `DROP SCHEMA` global, contraintes de complétude, migration Doctrine). Aujourd'hui `make provision` ne prend **aucun** argument de zone, refusionne cumulativement et fait `DROP SCHEMA osm CASCADE` : « ouvrir une zone » n'existe pas encore comme opération.
>
> L'**exécution** — ouvrir une zone en production, ou en local pour développer — est un acte opérationnel récurrent. Elle apparaît ici en checklist de recette à titre de vérification, conformément à l'usage de tous les sprints de ce fichier, et sa **procédure** est documentée par #891 (runbook production et local). Il n'existe à ce jour aucun runbook pour l'import des données de référence : `osm-france-refresh.md` porte un nom trompeur, son contenu traite des tuiles Valhalla.
>
> ⚠️ **À traiter en `/pick` séquentiels plutôt qu'en `/sprint`.** Les cinq issues touchent les mêmes fichiers (`PostgisImporter`, `DataTourismeImporter`, `ProvisionCommand`) et la chaîne de dépendances les sérialise déjà : les vagues sont #883, puis #884, puis {#885, #886}, puis #891. Le parallélisme n'apporte presque rien, la pile de PR imbriquées apporte tout le coût de rebase.

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#883](https://github.com/vincentchalamon/bike-trip-planner/issues/883) | feat(provisioner): zone registry, single-zone runs and transactional promotion | - | [#946](https://github.com/vincentchalamon/bike-trip-planner/pull/946) | - |
| 2 | [#884](https://github.com/vincentchalamon/bike-trip-planner/issues/884) | feat(provisioner): persistent enrichment cache, name resolver and completeness gate | - | [#948](https://github.com/vincentchalamon/bike-trip-planner/pull/948) | [#883](https://github.com/vincentchalamon/bike-trip-planner/issues/883) |
| 3 | [#885](https://github.com/vincentchalamon/bike-trip-planner/issues/885) | feat(provisioner): geometric matching between osm and datatourisme places | - | [#950](https://github.com/vincentchalamon/bike-trip-planner/pull/950) | [#884](https://github.com/vincentchalamon/bike-trip-planner/issues/884) |
| 4 | [#886](https://github.com/vincentchalamon/bike-trip-planner/issues/886) | feat(provisioner): zone opening report and manual override import | - | - | [#884](https://github.com/vincentchalamon/bike-trip-planner/issues/884) |
| 5 | [#891](https://github.com/vincentchalamon/bike-trip-planner/issues/891) | docs(runbooks): zone opening procedure for production and local use | - | - | [#883](https://github.com/vincentchalamon/bike-trip-planner/issues/883), [#886](https://github.com/vincentchalamon/bike-trip-planner/issues/886) |

### Recette Sprint 49

- **Tests E2E :** aucun. La vérification est un scénario de provisionnement, décrit ci-dessous.
- **Checklist manuelle :**
  - [ ] `make provision` **sans** argument de zone échoue avec un message explicite.
  - [ ] `make provision <zone>` importe cette seule zone et n'altère aucune autre.
  - [ ] Ouvrir une seconde zone **conserve** intégralement la première.
  - [ ] **Ré-ouvrir la même zone sans changement de source insère 0 ligne**, sans aucun appel réseau d'enrichissement, et le rapport annonce « 0 nouvelle entrée ». **C'est la preuve du gate.**
  - [ ] Une exécution interrompue en cours de promotion ne laisse aucun état partiel.
  - [ ] Ouvrir une zone non couverte par le graphe de routage est **refusé**, avec un message actionnable.
  - [ ] Une entrée présente avec un champ NULL est complétée par COALESCE, **sans qu'aucune valeur existante ne soit remplacée**.
  - [ ] Après incrément de `resolver_version`, une ré-ouverture réessaie les entrées `insufficient` et **seulement** elles.
  - [ ] Une contrainte `CHECK` interdit d'insérer un hébergement réservable sans nom ; un point d'eau sans nom reste acceptable.
  - [ ] Le fichier `rejected.tsv` est trié par proximité aux véloroutes, les entrées utiles en tête.
  - [ ] Un `override.tsv` importé n'est plus réanalysé à la ré-ouverture suivante.
  - [ ] `regions.json` et `RegionSelectionStore` ont disparu.
  - [ ] Un opérateur qui n'a jamais vu le projet ouvre une zone **en local** en suivant le seul runbook, sans poser de question.
  - [ ] Aucun runbook ne confond plus données de référence et graphe de routage.

</details>

<details><summary>

## Sprint 50 - Sources complémentaires et map-matching

</summary>
Sprint **conditionnel**. La source la plus rentable n'est pas nouvelle : c'est celle déjà téléchargée. Rien ne s'ajoute avant que le sprint 46 ait récupéré les champs du flux DataTourisme et que le sprint 47 ait chiffré ce qui manque encore.

> **Prérequis :** mesures du sprint 47. #887 n'est ouverte que si les métriques confirment un déficit sur `shelter`, `wilderness_hut` et les points d'eau. #888 n'est ouverte que si #880 (`admin_level`) s'avère insuffisante pour libeller les entrées. #889 ne devient du code que si l'ADR retient le map-matching Valhalla.
>
> **Écartés après examen :** _Base Sirene_ (raisons sociales majoritairement inexploitables, position dérivée de l'adresse, appariement flou : donner un mauvais nom d'hébergement est pire que n'en donner aucun) et _OpenChargeMap_ (orienté voiture comme l'IRVE nationale, alors que la recharge VAE réelle est « un café avec une prise »).

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#887](https://github.com/vincentchalamon/bike-trip-planner/issues/887) | feat(provisioner): import refuges-info shelters and water points | - | - | - |
| 2 | [#888](https://github.com/vincentchalamon/bike-trip-planner/issues/888) | feat(provisioner): import ban addresses for offline reverse geocoding | - | - | - |
| 3 | [#889](https://github.com/vincentchalamon/bike-trip-planner/issues/889) | docs(adr): add adr-050 terrain attribution to the ridden route | - | - | - |

### Recette Sprint 50

- **Tests E2E :** aucun.
- **Checklist manuelle :**
  - [ ] Les métriques du sprint 47 justifiant l'ouverture de chaque issue sont citées dans la PR correspondante.
  - [ ] Une indisponibilité de Refuges.info ou de la BAN ne fait pas échouer le provisionnement des autres sources (ADR-041 R1).
  - [ ] Les abris et points d'eau importés sont dédupliqués contre OSM par l'appariement géométrique de #885, sans second mécanisme.
  - [ ] L'attribution CC-BY-SA de Refuges.info est traitée et documentée.
  - [ ] Le périmètre BAN importé est borné et son choix justifié par une mesure de volume et de disque.
  - [ ] Aucune dépendance au runtime n'est introduite : les sources sont consommées dans le provisionner uniquement (ADR-040).
  - [ ] ADR-050 tranche explicitement, y compris si la décision est de conserver l'option corridor déjà livrée.

</details>

<details><summary>

## Sprint 51 - In-ride sans IA

</summary>
L'in-ride n'était pas une fonctionnalité : c'était une branche de `POST /trips/{id}/ai-chat` déclenchée par la présence d'un champ `position`. Elle faisait deux appels LLM — un classifieur d'intention et une narration — alors que tout le travail utile était déjà déterministe, et que ces deux appels avaient chacun un repli déterministe complet. Le sprint la remplace par une recherche guidée à 8 questions prédéfinies sur l'index Tier-1, supprime le chat de mutation et l'historique persisté, et sort l'in-ride du drapeau `NEXT_PUBLIC_ENABLE_AI`. À l'arrivée, la seule surface IA restante est **l'analyse du voyage** et **la création d'itinéraire**.

Le sprint porte aussi le ticket de suivi demandé par #927 et par la PR #928 : le nettoyage de la couche abri. Décision produit à contre-courant de la liste blanche stricte que documentait le `README`, un abribus abrite réellement de la pluie en route — il est **conservé, étiqueté « Abribus »**, et c'est le mobilier inutile (`carport`, `gazebo`, `umbrella`, `shopping_cart`) qui est écarté.

> ⚠️ **#929 et #934 doivent tourner seules.** Chacune régénère `pwa/src/lib/api/schema.d.ts` (~2 000 lignes générées) via `make typegen` : les lancer en worktrees parallèles garantit un conflit sur un fichier généré.
>
> ⚠️ **#938 ne passe pas par `/sprint`.** C'est une passe de recette manuelle sur téléphone, sans commit, alors que le pipeline attend une branche, un `make qa`, un commit et une PR. Ce qui fait la valeur de cette fonctionnalité — passage de main vers l'application de cartes, position de départ après quelques centaines de mètres, cibles tactiles avec des gants, lisibilité en plein soleil — n'est pas testable par Playwright.
>
> **Suppression avant construction.** L'ordre « ajouter puis retirer » est infaisable : les issues de construction changent la signature de `InRidePoiRepository::findNearby()`, la forme de `PoiSuggestion` et l'API publique de `OpeningHoursParser`, que `InRideAssistant` consomme encore. Et l'argument « rester déployable » ne tient pas : la surface était déjà masquée en prod et en recette (ADR-046, défaut off). #929 laisse donc **douze briques transitoirement orphelines**, listées dans son corps, qui retrouvent un appelant en #935.
>
> **#937 est hors sprint** : ADR-032 interdit une migration destructive dans la release qui cesse d'écrire. Le `DROP TABLE trip_chat_message` attend la release suivant celle de #929.
>
> **Prérequis levé :** PR #928 mergée (`46c048a8`), elle touchait `schema.d.ts`, `messages/*.json`, `tier1.lua` et `TripRequest`.

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#929](https://github.com/vincentchalamon/bike-trip-planner/issues/929) | refactor(in-ride)!: supprimer le chat IA de planification, l'in-ride IA et l'historique persisté | XL | - | PR #928 |
| 2 | [#930](https://github.com/vincentchalamon/bike-trip-planner/issues/930) | feat(in-ride): enum de catégories et lecteur PostGIS 8 buckets | L | - | #929 |
| 3 | [#931](https://github.com/vincentchalamon/bike-trip-planner/issues/931) | feat(in-ride): tri-état des horaires et libellés localisés des lieux sans nom | M | - | #929 |
| 4 | [#932](https://github.com/vincentchalamon/bike-trip-planner/issues/932) | feat(in-ride): polyligne restante côté serveur pour le calcul de détour | M | - | #929 |
| 5 | [#933](https://github.com/vincentchalamon/bike-trip-planner/issues/933) | feat(in-ride): orchestrateur de recherche de lieux sans IA | L | - | #930, #931, #932 |
| 6 | [#934](https://github.com/vincentchalamon/bike-trip-planner/issues/934) | feat(api): endpoint POST /trips/{id}/nearby-pois | L | - | #933 |
| 7 | [#935](https://github.com/vincentchalamon/bike-trip-planner/issues/935) | feat(pwa): panneau « en route » à questions prédéfinies | XL | - | #934 |
| 8 | [#936](https://github.com/vincentchalamon/bike-trip-planner/issues/936) | docs(adr): add adr-048 in-ride assistance without ai | M | - | #934 |
| 9 | [#938](https://github.com/vincentchalamon/bike-trip-planner/issues/938) | test(recette): recette sprint 51 - in-ride sans IA sur téléphone | M | - | #935, #936 |
| — | [#937](https://github.com/vincentchalamon/bike-trip-planner/issues/937) | chore(db)!: supprimer la table trip_chat_message | S | - | #929 **livrée en prod** |

Vagues `/sprint` : {#929} seule → {#930, #931, #932} → {#933} → {#934} seule → {#935, #936} → {#938} manuelle → release suivante {#937}.

### Recette Sprint 51

- **Tests E2E :** `pwa/tests/mocked/in-ride-search.spec.ts`, `pwa/tests/recette/features/in-ride.{en,fr}.feature`. La CI est le gate.
- **Checklist manuelle, sur téléphone** (détail et compte rendu dans #938) :
  - [ ] La bulle « en route » est visible **sans** `NEXT_PUBLIC_ENABLE_AI` et sans fournisseur IA configuré.
  - [ ] Aucun champ de saisie libre ; les 8 questions sont atteignables au pouce, d'une main.
  - [ ] Chaque question renvoie des résultats en zone couverte, et un message **distinct** hors zone couverte.
  - [ ] Un lieu tapé ouvre l'application de cartes du téléphone en mode vélo, **depuis la position vive** : faire la recherche, marcher 200 m, puis taper.
  - [ ] Rien n'est envoyé vers l'appareil GPS : aucun waypoint ajouté, aucun recalcul, le voyage est inchangé après la recherche.
  - [ ] Un abribus ressort étiqueté « Abribus » et non « Abri » ; aucun abri à caddies ni carport dans les résultats.
  - [ ] Aucune borne de recharge voiture proposée pour la recherche de recharge ; la recherche de courses ne renvoie ni station-service ni pharmacie.
  - [ ] Un lieu sans horaires connus porte « horaires non vérifiés » et reste visible ; un lieu certainement fermé est absent.
  - [ ] « Élargir la recherche » change réellement les résultats en centre-ville dense — garde-fou contre le no-op du plafond de candidats.
  - [ ] Plafond atteint et tout écarté : le message dit « aucun résultat exploitable parmi les N plus proches », pas « rien dans ce rayon ».
  - [ ] Le badge de détour n'apparaît que lorsqu'un détour est réellement calculé.
  - [ ] Les résultats restent lisibles en plein soleil et toute la carte réagit au tap avec des gants.
  - [ ] `/s/{shortCode}` (vue partagée anonyme) n'affiche pas la bulle.
  - [ ] Avec une clé configurée : génération depuis un brief, chat de cadrage, analyse complète, briefing par étape et synthèse de voyage fonctionnent toujours.
  - [ ] Les 12 orphelins transitoires de #929 ont tous retrouvé un appelant.
  - [ ] La table `trip_chat_message` est encore présente en base à l'issue du sprint — sa suppression est #937, release suivante.

</details>
