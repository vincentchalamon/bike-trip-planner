# Tracking

---

## Sprint 1 — Quick Wins Alertes

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

---

## Sprint 2 — Alertes Frontend + UX Feedback

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

---

## Sprint 3 — Hébergements

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

---

## Sprint 4 — Configuration & Profil

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

---

## Sprint 5 — Météo & Temps

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

---

## Sprint 6 — Export (pré-auth)

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

---

## Sprint 7 — Carte Interactive

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

---

## Sprint 8 — UX & Onboarding

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

---

## Sprint 9 — Sources de Routes & Infra Backend

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

---

## Sprint 10 — i18n & Documentation

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

---

## Sprint 11 — Persistance

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

---

## Sprint 12 — Gestion des Trips

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

---

## Sprint 13 — Auth & Sécurité

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

---

## Sprint 14 — Partage

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

---

## Sprint 15 — Mobile

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

---

## Sprint 16 — Recette Globale

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

---

## Sprint 17 — Performance pipeline async

Optimisation du pipeline d'analyse : timeouts, batch Overpass, cache warming.

| Ordre | ID                                                                      | Titre                                              | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|----------------------------------------------------|--------|-----|-----------|
| 1     | [#277](https://github.com/vincentchalamon/bike-trip-planner/issues/277) | Réduire les timeouts de scraping d'hébergements    | S      | [#292](https://github.com/vincentchalamon/bike-trip-planner/pull/292) | —         |
| 2     | [#278](https://github.com/vincentchalamon/bike-trip-planner/issues/278) | Fusionner les requêtes Overpass per-stage en batch  | M      | [#293](https://github.com/vincentchalamon/bike-trip-planner/pull/293) | —         |
| 3     | [#279](https://github.com/vincentchalamon/bike-trip-planner/issues/279) | Vérifier et optimiser le cache warming ScanAllOsmData | M   | [#294](https://github.com/vincentchalamon/bike-trip-planner/pull/294) | —         |
| 4     | [#280](https://github.com/vincentchalamon/bike-trip-planner/issues/280) | Augmenter la limite d'upload GPX à 30 MB           | S      | [#295](https://github.com/vincentchalamon/bike-trip-planner/pull/295) | —         |

---

## Sprint 18 — Alertes actionnables + nouvelles règles

Champ `action` sur le modèle Alert, actions contextuelles sur les analyseurs existants, nouveaux handlers. Parallélisable avec sprint 17.

| Ordre | ID                                                                      | Titre                                                      | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|------------------------------------------------------------|--------|-----|-----------|
| 1     | [#281](https://github.com/vincentchalamon/bike-trip-planner/issues/281) | Ajouter le champ `action` au modèle Alert                  | M      | [#329](https://github.com/vincentchalamon/bike-trip-planner/pull/329) | —         |
| 2     | [#282](https://github.com/vincentchalamon/bike-trip-planner/issues/282) | Ajouter des actions contextuelles aux analyseurs existants  | L      | [#333](https://github.com/vincentchalamon/bike-trip-planner/pull/333) | #281      |
| 3     | [#283](https://github.com/vincentchalamon/bike-trip-planner/issues/283) | Nouvel analyseur : gare SNCF de secours (nudge)            | S      | [#330](https://github.com/vincentchalamon/bike-trip-planner/pull/330) | —         |
| 4     | [#284](https://github.com/vincentchalamon/bike-trip-planner/issues/284) | Nouvel analyseur : pharmacie/hôpital à proximité (nudge)   | S      | [#331](https://github.com/vincentchalamon/bike-trip-planner/pull/331) | —         |
| 5     | [#285](https://github.com/vincentchalamon/bike-trip-planner/issues/285) | Nouvel analyseur : passage frontière (nudge)               | M      | [#332](https://github.com/vincentchalamon/bike-trip-planner/pull/332) | —         |
| 6     | [#313](https://github.com/vincentchalamon/bike-trip-planner/issues/313) | Nouvel analyseur : départ avant l'aube (warning)           | S      |     | —         |
| 7     | [#314](https://github.com/vincentchalamon/bike-trip-planner/issues/314) | Nouvel analyseur : traversée cours d'eau sans pont (nudge) | M      |     | —         |
| 8     | [#315](https://github.com/vincentchalamon/bike-trip-planner/issues/315) | ADR-028 : alertes actionnables (action DTO, 4 types)       | S      |     | —         |

---

## Sprint 19 — Landing page + accès anticipé

Page d'accueil marketing, système d'accès anticipé (HMAC, throttling, CLI), page FAQ. ADR-029.

| Ordre | ID                                                                      | Titre                                                         | Effort | PRs                                                                                | Dépend de |
|-------|-------------------------------------------------------------------------|---------------------------------------------------------------|--------|------------------------------------------------------------------------------------|-----------|
| 1     | [#286](https://github.com/vincentchalamon/bike-trip-planner/issues/286) | Landing page : page d'accueil marketing (8 sections)          | L      | [#338](https://github.com/vincentchalamon/bike-trip-planner/pull/338) `feature/286` | —         |
| 2     | [#287](https://github.com/vincentchalamon/bike-trip-planner/issues/287) | Système d'accès anticipé : backend (entité, HMAC, throttling) | L      | [#337](https://github.com/vincentchalamon/bike-trip-planner/pull/337) `feature/287` | —         |
| 3     | [#288](https://github.com/vincentchalamon/bike-trip-planner/issues/288) | Système d'accès anticipé : frontend (formulaire, login)       | M      | [#341](https://github.com/vincentchalamon/bike-trip-planner/pull/341) `feature/288` | #287      |
| 4     | [#289](https://github.com/vincentchalamon/bike-trip-planner/issues/289) | Page FAQ : différenciation et questions fréquentes            | S      | [#340](https://github.com/vincentchalamon/bike-trip-planner/pull/340) `feature/289` | —         |
| 5     | [#316](https://github.com/vincentchalamon/bike-trip-planner/issues/316) | ADR-029 : système d'accès anticipé (HMAC, throttling, CLI)   | S      | [#336](https://github.com/vincentchalamon/bike-trip-planner/pull/336) `feature/316` | —         |

---

## Sprint 20 — Sources de données enrichies (DataTourisme + Wikidata)

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

---

## Sprint 21 — Stepper + Refonte du flux

Composant Stepper navigation 4 actes, liste des voyages avec statuts, ADR-026 (pipeline 2 phases).

| Ordre | ID                                                                      | Titre                                                                          | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|--------------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#319](https://github.com/vincentchalamon/bike-trip-planner/issues/319) | ADR-026 : gate mechanism et pipeline 2 phases (prévisualisation → analyse)     | S      | [#362](https://github.com/vincentchalamon/bike-trip-planner/pull/362) `feature/319` | —         |
| 2     | [#317](https://github.com/vincentchalamon/bike-trip-planner/issues/317) | Composant Stepper : navigation 4 étapes (Préparation → Aperçu → Analyse → MV) | M      | [#363](https://github.com/vincentchalamon/bike-trip-planner/pull/363) `feature/317` | —         |
| 3     | [#318](https://github.com/vincentchalamon/bike-trip-planner/issues/318) | Liste des voyages avec statuts + header "Mes voyages"                          | M      | [#364](https://github.com/vincentchalamon/bike-trip-planner/pull/364) `feature/318` | —         |

---

## Sprint 22 — Acte 1 : Card Selection + Acte 1.5 : Aperçu

Interface d'entrée de l'itinéraire (cartes Lien/GPX), écran de prévisualisation, et endpoint `POST /trips/{id}/analyze`.

| Ordre | ID                                                                      | Titre                                                                     | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|---------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#320](https://github.com/vincentchalamon/bike-trip-planner/issues/320) | Acte 1 — Card Selection : entrée mutuellement exclusive (Lien + GPX)      | L      | [#367](https://github.com/vincentchalamon/bike-trip-planner/pull/367) `feature/320` | #317      |
| 2     | [#321](https://github.com/vincentchalamon/bike-trip-planner/issues/321) | Acte 1.5 — Écran Aperçu : prévisualisation avant analyse                 | M      | [#368](https://github.com/vincentchalamon/bike-trip-planner/pull/368) `feature/321` | #317 #320 |
| 3     | [#322](https://github.com/vincentchalamon/bike-trip-planner/issues/322) | Endpoint `POST /trips/{id}/analyze` : découplage prévisualisation/analyse | M      | [#366](https://github.com/vincentchalamon/bike-trip-planner/pull/366) `feature/322` | —         |

---

## Sprint 23 — Acte 2 + Acte 3 : progression et résultats

Écran de progression narrative (Acte 2), events Mercure restructurés, refonte résultats avec alertes repliables (Acte 3).

| Ordre | ID                                                                      | Titre                                                                           | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|---------------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#324](https://github.com/vincentchalamon/bike-trip-planner/issues/324) | Events Mercure dual mode : computation_step_completed + TRIP_READY + STAGE_UPDATED | L   |     | #322      |
| 2     | [#323](https://github.com/vincentchalamon/bike-trip-planner/issues/323) | Acte 2 — ProcessingProgress : écran de progression narrative par catégorie      | L      |     | #317 #324 |
| 3     | [#325](https://github.com/vincentchalamon/bike-trip-planner/issues/325) | Acte 3 — Refonte résultats : alertes repliables + affichage structuré           | L      |     | #323 #324 |

---

## Sprint 24 — UX avancé : recomputation inline + batch mode

Shimmer/skeleton sur les étapes en recalcul, batch mode (ModificationQueue), diff post-recalcul.

| Ordre | ID                                                                      | Titre                                                                     | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|---------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#326](https://github.com/vincentchalamon/bike-trip-planner/issues/326) | Recomputation inline : shimmer/skeleton + barre de progression discrète   | L      |     | #324 #325 |
| 2     | [#327](https://github.com/vincentchalamon/bike-trip-planner/issues/327) | Batch mode : ModificationQueue (accumulation + recalcul unique)           | L      |     | #326      |
| 3     | [#328](https://github.com/vincentchalamon/bike-trip-planner/issues/328) | Diff post-recalcul : surbrillance des changements après recomputation     | M      |     | #326      |

---

## Sprint 25 — Intégration Ollama : fondations backend

Service OllamaClient PHP, configuration Docker Ollama, gate mechanism dans ComputationTracker, system prompts cyclotourisme versionnés. ADR-027.

| Ordre | ID                                                                      | Titre                                                              | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|--------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#297](https://github.com/vincentchalamon/bike-trip-planner/issues/297) | ADR-027 : architecture Ollama/LLaMA (2 passes, fallback, context)  | S      |     | —         |
| 2     | [#298](https://github.com/vincentchalamon/bike-trip-planner/issues/298) | Service OllamaClient PHP + configuration Docker Ollama             | M      |     | —         |
| 3     | [#299](https://github.com/vincentchalamon/bike-trip-planner/issues/299) | Gate mechanism dans ComputationTracker                             | M      |     | —         |
| 4     | [#300](https://github.com/vincentchalamon/bike-trip-planner/issues/300) | System prompts cyclotourisme versionnés (LLaMA 8B)                 | S      |     | —         |

---

## Sprint 26 — LLaMA 8B : analyse 2 passes

Pipeline d'analyse IA : passe 1 par étape (parallélisable via Messenger), passe 2 vue d'ensemble, orchestration gate → LLaMA → TRIP_READY, fallback gracieux.

| Ordre | ID                                                                      | Titre                                                     | Effort | PRs | Dépend de      |
|-------|-------------------------------------------------------------------------|-----------------------------------------------------------|--------|-----|----------------|
| 1     | [#301](https://github.com/vincentchalamon/bike-trip-planner/issues/301) | LLaMA 8B passe 1 : analyse par étape via Messenger        | L      |     | #298 #299 #300 |
| 2     | [#302](https://github.com/vincentchalamon/bike-trip-planner/issues/302) | LLaMA 8B passe 2 : vue d'ensemble du trip                 | M      |     | #301           |
| 3     | [#303](https://github.com/vincentchalamon/bike-trip-planner/issues/303) | Pipeline gate → LLaMA → TRIP_READY event Mercure          | M      |     | #299 #301 #302 |
| 4     | [#304](https://github.com/vincentchalamon/bike-trip-planner/issues/304) | Fallback gracieux sans Ollama                              | S      |     | #303           |

---

## Sprint 27 — Frontend IA : résumés + affichage hybride

Affichage des résumés IA (global + par étape), layout hybride résumé/alertes repliables, analyse IA différée, fallback frontend sans LLaMA.

| Ordre | ID                                                                      | Titre                                                                | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|----------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#305](https://github.com/vincentchalamon/bike-trip-planner/issues/305) | Résumé IA global dans "Mon voyage" (passe 2)                         | M      |     | #302      |
| 2     | [#306](https://github.com/vincentchalamon/bike-trip-planner/issues/306) | Résumé IA par étape + layout hybride (résumé + alertes repliables)   | L      |     | #301 #305 |
| 3     | [#307](https://github.com/vincentchalamon/bike-trip-planner/issues/307) | LLaMA 8B différé : bandeau + bouton "Actualiser l'analyse IA"       | M      |     | #306      |
| 4     | [#308](https://github.com/vincentchalamon/bike-trip-planner/issues/308) | Fallback frontend sans LLaMA (résumé masqué, alertes dépliées)      | S      |     | #305 #306 |

---

## Sprint 28 — Bulle IA (LLaMA 3B) : dialogue context-aware

Assistant conversationnel via bulle flottante, LLaMA 3B pour interpréter les intentions, intégration avec la recomputation inline.

| Ordre | ID                                                                      | Titre                                                                    | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|--------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#309](https://github.com/vincentchalamon/bike-trip-planner/issues/309) | System prompt dialogue LLaMA 3B + endpoint backend chat IA               | M      |     | #298      |
| 2     | [#310](https://github.com/vincentchalamon/bike-trip-planner/issues/310) | Composant AiBubble : bulle flottante + panneau chat                      | L      |     | #309      |
| 3     | [#311](https://github.com/vincentchalamon/bike-trip-planner/issues/311) | Intégration bulle IA ↔ recomputation inline + skipAiAnalysis             | M      |     | #309 #310 |

---

## Sprint 29 — Analytics d'usage & conformité RGPD

Collecte de métriques d'usage **agrégées et anonymes** (sources, plateformes, profil trips, santé backend, valeur features, rétention/UX), avec prérequis RGPD (privacy policy, mentions légales, anonymisation user). Implémentation **native** (Doctrine + Messenger + PostgreSQL partitionné), **aucun outil tiers**. Voir issue [#370](https://github.com/vincentchalamon/bike-trip-planner/issues/370) (épic).

| Ordre | ID                                                                      | Titre                                                                        | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|------------------------------------------------------------------------------|--------|-----|-----------|
| 1     | TBD                                                                     | Page `/privacy` + mentions légales `/legal`                                  | M      |     | —         |
| 2     | TBD                                                                     | Endpoint anonymisation/suppression user (soft-delete + purge des events)     | M      |     | —         |
| 3     | TBD                                                                     | Entité `UsageEvent` partitionnée mensuellement + migration + rate limiter    | M      |     | —         |
| 4     | TBD                                                                     | Endpoint `POST /events` + `RecordUsageEventsMessage` + handler DBAL batch    | M      |     | #3 (TBD)  |
| 5     | TBD                                                                     | Hook PWA `useUsageTracker()` + batch via `navigator.sendBeacon`              | S      |     | #4 (TBD)  |
| 6     | TBD                                                                     | Instrumentation sources & plateformes (section 1 de #370)                    | S      |     | #5 (TBD)  |
| 7     | TBD                                                                     | Instrumentation profil trips, santé backend, valeur features, rétention/UX   | L      |     | #6 (TBD)  |
| 8     | TBD                                                                     | Vue matérialisée `usage_daily_summary` + cron de refresh quotidien           | S      |     | #3 (TBD)  |

### Recette Sprint 29

- **Checklist manuelle :**
  - [ ] Page `/privacy` accessible et complète (base légale, conservation 13 mois, droits utilisateurs)
  - [ ] Mentions légales `/legal` accessibles
  - [ ] Suppression de compte → events associés purgés (vérifier via requête DB)
  - [ ] Table `usage_event` partitionnée par mois (vérifier `\d+ usage_event` en psql)
  - [ ] Script cron de purge `DROP PARTITION` à 13 mois fonctionnel
  - [ ] Endpoint `POST /events` : latence < 5 ms, réponse `202` immédiate (pas d'INSERT synchrone)
  - [ ] Batch client `sendBeacon` : 1 requête par session (vérifier DevTools)
  - [ ] Saturation Messenger → drop silencieux, aucune requête métier impactée
  - [ ] Vue matérialisée `usage_daily_summary` rafraîchie par cron quotidien
  - [ ] Aucun log d'IP, User-Agent brut ou coordonnées GPS précises dans `usage_event` (grep payload)

---

## Sprint 30 — Recette complète & Audit

Recette fonctionnelle end-to-end de l'ensemble de l'application (sprints 1 à 28) et audit complet : performance, sécurité, accessibilité, SEO, qualité de code, couverture de tests. Deux phases : **audit** (cartographier les problèmes) puis **corrections** (fixer par lots thématiques).

### Phase 1 — Outillage automatisé

| Ordre | Titre                                                          | Effort |
|-------|----------------------------------------------------------------|--------|
| 1     | Intégrer `@axe-core/playwright` dans les fixtures E2E          | S      |
| 2     | Intégrer Lighthouse CI (`make lighthouse`)                     | M      |
| 3     | Script de complétude i18n FR/EN (`make i18n-check`)            | S      |
| 4     | Monitoring console errors + requêtes 500 dans les fixtures E2E | S      |
| 5     | Ajouter `npm audit` au workflow CI                             | S      |
| 6     | Visual regression screenshots Playwright (36 baselines)        | M      |

### Phase 2 — Audit

| Ordre | Titre                                                                     | Effort |
|-------|---------------------------------------------------------------------------|--------|
| 7     | Audit sécurité : headers HTTP (CSP, HSTS, X-Frame-Options) dans Caddy    | S      |
| 8     | Audit sécurité : isolation Mercure entre utilisateurs                     | M      |
| 9     | Audit sécurité : auth exhaustive sur tous les endpoints (401/403)         | M      |
| 10    | Audit sécurité : rate limiting effectif (magic link, trip create, scrape) | S      |
| 11    | Audit sécurité : XSS dans les champs éditables (titre, locations)        | S      |
| 12    | Audit performance : Lighthouse CI sur toutes les pages                    | M      |
| 13    | Audit performance : N+1 Doctrine (TripDetail, stages, accommodations)    | M      |
| 14    | Audit performance : bundle size Next.js + code splitting                  | S      |
| 15    | Audit performance : temps calcul async complet (upload → dernier SSE)    | M      |
| 16    | Audit accessibilité : axe-core 0 violation critique                      | M      |
| 17    | Audit accessibilité : navigation clavier complète (carte, sidebar, modales) | M   |
| 18    | Audit SEO : meta tags, Open Graph sur les pages de partage               | S      |
| 19    | Audit i18n : complétude FR/EN, formatage dates/nombres, clés visibles    | S      |

### Phase 3 — Recette manuelle

| Ordre | Titre                                                          | Effort |
|-------|----------------------------------------------------------------|--------|
| 20    | Golden path A : trip depuis lien Komoot (parcours complet)     | L      |
| 21    | Golden path B : trip depuis upload GPX (drag & drop, ~25 MB)   | M      |
| 22    | Golden path C : trip via URL (`/?link=...`)                    | S      |
| 23    | Cas limites : inputs invalides (GPX malformé, > 30 MB, 0 pts) | M      |
| 24    | Cas limites : auth (token expiré, double-clic, 2 onglets, inactivité 15 min) | M |
| 25    | Cas limites : réseau (coupure pendant calcul, SSE déconnecté, worker crash) | M  |
| 26    | Cas limites : undo/redo séquences complexes (repos + hébergement + distance) | S |
| 27    | Cas limites : trip 20+ étapes, 0 hébergement, dénivelé > 3000m | S     |
| 28    | Audit visuel : desktop Chrome clair FR + Firefox sombre EN     | M      |
| 29    | Audit visuel : tablette 768×1024 + mobile 375×812 (clair/sombre) | M    |
| 30    | Audit visuel : mode sombre complet (carte, elevation, modales, toasts) | M |
| 31    | Audit visuel : états vides + états d'erreur sur toutes les pages | S     |

### Phase 4 — Corrections

| Ordre | Titre                                                      | Effort |
|-------|------------------------------------------------------------|--------|
| 32    | Fix : headers de sécurité manquants dans Caddy             | S      |
| 33    | Fix : bugs bloquants (P0) et fonctionnels dégradés (P1)    | L      |
| 34    | Fix : régressions UX/UI (P2)                               | M      |
| 35    | Fix : performance et polish (P3)                           | M      |
| 36    | Re-test : golden path A final après corrections            | M      |

### Recette Sprint 30 — Golden Path A (Komoot)

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

### Recette Sprint 30 — Cas limites

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

### Recette Sprint 30 — Audit visuel multi-device

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

### Recette Sprint 30 — Audits automatisés

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
  - [ ] Audit privacy : page `/privacy` complète, durée de conservation 13 mois respectée, purge `DROP PARTITION` vérifiée
  - [ ] Audit anonymisation : suppression user → purge des events associés, vérification via requête DB
  - [ ] Tous les bugs trouvés reportés en issues GitHub avec labels (`bug`, `ux`, `perf`, `security`, `a11y`)

---

## Sprint 31 — Garmin Connect

Export FIT natif (Phase 1) et push vers Garmin Connect via OAuth 2.0 PKCE (Phase 2). Voir [ADR-018](docs/adr/adr-018-garmin-export-and-device-sync-strategy.md). Test local via ngrok pour le callback OAuth.

> **Prérequis :** s'inscrire au [Garmin Developer Program](https://developer.garmin.com/) quelques sprints en avance (~2 jours d'approbation).

| Ordre | ID                                                                    | Titre          | Effort | PRs | Dépend de                                                             |
|-------|-----------------------------------------------------------------------|----------------|--------|-----|-----------------------------------------------------------------------|
| 1     | [#65](https://github.com/vincentchalamon/bike-trip-planner/issues/65) | Garmin Connect | L      | 3   | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76) |

### Recette Sprint 31

  - **Tests E2E :** `tests/recette/sprint-31.spec.ts`
- **Checklist manuelle :**
  - [ ] Export FIT téléchargeable par étape
  - [ ] Flux OAuth Garmin Connect complet (via ngrok)
  - [ ] Push course vers Garmin Connect fonctionnel
  - [ ] Gestion erreurs : token expiré, API indisponible

---

## Sprint 32 — Déploiement

Mise en production basée sur [ADR-019](docs/adr/adr-019-deployment-infrastructure-strategy.md). Issues GitHub à créer au moment venu.

| Ordre | Étape                                              | Effort |
|-------|----------------------------------------------------|--------|
| 1     | CI/CD pipeline production                          | M      |
| 2     | Oracle Cloud (OCI) Always Free provisioning        | M      |
| 3     | Coolify installation + configuration               | M      |
| 4     | Configuration DNS (FreeDNS)                        | S      |
| 5     | Docker configs production (PostgreSQL, Redis, Mercure, Caddy) | L      |
| 6     | Monitoring & healthchecks                          | M      |
| 7     | Migration données + smoke test production          | M      |
| 8     | [#312](https://github.com/vincentchalamon/bike-trip-planner/issues/312) Feature-deploy : preview par PR (Étapes 1-7) | L |

### Recette Sprint 32

- **Checklist manuelle :**
  - [ ] Application accessible via URL publique
  - [ ] HTTPS fonctionnel (certificat TLS auto Caddy)
  - [ ] PostgreSQL + Redis opérationnels en production
  - [ ] Mercure SSE fonctionnel en production
  - [ ] CI/CD : déploiement automatique sur push main
  - [ ] Monitoring : healthchecks + alertes basiques
  - [ ] Garmin Connect : callback OAuth sur URL production
  - [ ] Preview déployée via label `deploy:preview` sur une PR de test
  - [ ] URL `pr-<N>.biketrip.example.com` accessible en HTTPS
  - [ ] Cleanup automatique à la fermeture de la PR

---

## Hors Sprints

| ID  | Titre                            | Note                     |
|-----|----------------------------------|--------------------------|
| #5  | Add unit tests                   | Continu, à chaque sprint |
| #67 | Générer un itinéraire (LLaMA 3B) | Card "Assistant IA" Acte 1 — dépend de Valhalla + sprints 25-28 |

---

## Récapitulatif

| Sprint    | Thème                          | Tickets | PRs estimées |
|-----------|--------------------------------|---------|--------------|
| 1         | Quick Wins Alertes             | 5       | 5            |
| 2         | Alertes Frontend + UX          | 3       | 3            |
| 3         | Hébergements                   | 3       | 4            |
| 4         | Configuration & Profil         | 4       | 4            |
| 5         | Météo & Temps                  | 3       | 4            |
| 6         | Export                         | 3       | 3            |
| 7         | Carte Interactive              | 4       | 9            |
| 8         | UX & Onboarding                | 3       | 4            |
| 9         | Sources Routes & Infra         | 3       | 5            |
| 10        | i18n & Documentation           | 5       | 7            |
| 11        | Persistance                    | 1       | 6            |
| 12        | Gestion Trips                  | 3       | 4            |
| 13        | Auth & Sécurité                | 5       | 8            |
| 14        | Partage                        | 2       | 3            |
| 15        | Mobile                         | 6       | 11           |
| 16        | Recette Globale                | 7       | ~6           |
| 17        | Performance pipeline async     | 4       | ~4           |
| 18        | Alertes actionnables + règles  | 8       | ~9           |
| 19        | Landing page + accès anticipé  | 5       | ~6           |
| 20        | Sources de données enrichies   | 8       | ~8           |
| 21        | Stepper + Refonte du flux      | 3       | ~3           |
| 22        | Acte 1 Card Selection + Aperçu | 3       | ~3           |
| 23        | Acte 2 + Acte 3 : progression  | 3       | ~4           |
| 24        | UX avancé : recomputation      | 3       | ~4           |
| 25        | Intégration Ollama : fondations | 4      | ~4           |
| 26        | LLaMA 8B : analyse 2 passes    | 4       | ~5           |
| 27        | Frontend IA : résumés hybrides | 4       | ~5           |
| 28        | Bulle IA (LLaMA 3B) dialogue   | 3       | ~4           |
| 29        | Analytics d'usage & RGPD       | 8       | ~8           |
| 30        | Recette complète & Audit       | 36      | ~12          |
| 31        | Garmin Connect                 | 1       | 3            |
| 32        | Déploiement                    | 8       | ~8           |
| **Total** |                                | **165** | **~176**     |
