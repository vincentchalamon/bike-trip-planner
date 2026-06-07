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
| 6     | [#313](https://github.com/vincentchalamon/bike-trip-planner/issues/313) | ~~Nouvel analyseur : départ avant l'aube (warning)~~ — **abandonné** (non implémenté, fermé _not planned_) | S      |     | —         |
| 7     | [#314](https://github.com/vincentchalamon/bike-trip-planner/issues/314) | ~~Nouvel analyseur : traversée cours d'eau sans pont (nudge)~~ — **abandonné** (non implémenté, fermé _not planned_) | M      |     | —         |
| 8     | [#315](https://github.com/vincentchalamon/bike-trip-planner/issues/315) | ADR-TBD : alertes actionnables (action DTO, 4 types)       | S      |     | —         |

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
| 1     | [#324](https://github.com/vincentchalamon/bike-trip-planner/issues/324) | Events Mercure dual mode : computation_step_completed + TRIP_READY + STAGE_UPDATED | L   | [#376](https://github.com/vincentchalamon/bike-trip-planner/pull/376) `feature/324` | #322      |
| 2     | [#323](https://github.com/vincentchalamon/bike-trip-planner/issues/323) | Acte 2 — ProcessingProgress : écran de progression narrative par catégorie      | L      | [#377](https://github.com/vincentchalamon/bike-trip-planner/pull/377) `feature/323` | #317 #324 |
| 3     | [#325](https://github.com/vincentchalamon/bike-trip-planner/issues/325) | Acte 3 — Refonte résultats : alertes repliables + affichage structuré           | L      | [#378](https://github.com/vincentchalamon/bike-trip-planner/pull/378) `feature/325` | #323 #324 |

---

## Sprint 24 — UX avancé : recomputation inline + batch mode

Shimmer/skeleton sur les étapes en recalcul, batch mode (ModificationQueue), diff post-recalcul. **Note** : implémenter avec variables CSS uniquement (pas de couleurs hardcodées) — les tokens seront remappés par le sprint 25 (Design Foundations).

| Ordre | ID                                                                      | Titre                                                                     | Effort | PRs                                                                                | Dépend de |
|-------|-------------------------------------------------------------------------|---------------------------------------------------------------------------|--------|------------------------------------------------------------------------------------|-----------|
| 1     | [#326](https://github.com/vincentchalamon/bike-trip-planner/issues/326) | Recomputation inline : shimmer/skeleton + barre de progression discrète   | L      | [#380](https://github.com/vincentchalamon/bike-trip-planner/pull/380) `feature/326` | #324 #325 |
| 2     | [#327](https://github.com/vincentchalamon/bike-trip-planner/issues/327) | Batch mode : ModificationQueue (accumulation + recalcul unique)           | L      | [#382](https://github.com/vincentchalamon/bike-trip-planner/pull/382) `feature/327` | #326      |
| 3     | [#328](https://github.com/vincentchalamon/bike-trip-planner/issues/328) | Diff post-recalcul : surbrillance des changements après recomputation     | M      | [#381](https://github.com/vincentchalamon/bike-trip-planner/pull/381) `feature/328` | #326      |

---

## Sprint 25 — Design Foundations (issue #375 §1, §6, §10)

Fondations du nouveau design system (palette ambre, typographies, tokens spacing/radius/shadow, pages d'erreur stylisées, pictogrammes unifiés). Consommé par tout le reste de la roadmap. Issues à créer comme sous-issues de [#375](https://github.com/vincentchalamon/bike-trip-planner/issues/375).

| Ordre | ID                                                                      | Titre                                                                                                                | Effort | PRs                                                                                | Dépend de |
|-------|-------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------|--------|------------------------------------------------------------------------------------|-----------|
| 1     | [#386](https://github.com/vincentchalamon/bike-trip-planner/issues/386) | Palette ambre `#c2671e` + tokens warm paper / ink charcoal dans `globals.css` (variantes forest/indigo/brick swap)   | M      | [#408](https://github.com/vincentchalamon/bike-trip-planner/pull/408) `feature/386` | —         |
| 2     | [#387](https://github.com/vincentchalamon/bike-trip-planner/issues/387) | Charger Fraunces + Inter Tight + JetBrains Mono via `next/font` ; remplacer `--font-geist-*`                         | S      | [#409](https://github.com/vincentchalamon/bike-trip-planner/pull/409) `feature/387` | —         |
| 3     | [#388](https://github.com/vincentchalamon/bike-trip-planner/issues/388) | Étendre échelles spacing (6/8/12/16/22/28/36/48/64) / radius (6/8/10/14/16) / shadow dans `@theme`                   | S      | [#410](https://github.com/vincentchalamon/bike-trip-planner/pull/410) `feature/388` | —         |
| 4     | [#389](https://github.com/vincentchalamon/bike-trip-planner/issues/389) | Restyle `not-found.tsx`, `error.tsx`, `global-error.tsx` (404 « Hors-piste », 500 « Caillou dans le dérailleur »)    | M      | [#411](https://github.com/vincentchalamon/bike-trip-planner/pull/411) `feature/389` | #386      |
| 5     | [#390](https://github.com/vincentchalamon/bike-trip-planner/issues/390) | Système de pictogrammes unifié (12 catégories) + légende visuelle consultable dans la FAQ                            | L      | [#412](https://github.com/vincentchalamon/bike-trip-planner/pull/412) `feature/390` | #386      |

---

## Sprint 26 — Refonte Roadbook + Wizard 4 étapes (issue #375 §2, §3, §9)

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

---

## Sprint 27 — Reste du design (issue #375 §4, §5, §7, §8, §11, §12)

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

---

## Sprint 28 — Intégration Ollama : fondations backend

Service OllamaClient PHP, configuration Docker Ollama, gate mechanism dans ComputationTracker, system prompts cyclotourisme versionnés. ADR-028.

| Ordre | ID                                                                      | Titre                                                              | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|--------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#297](https://github.com/vincentchalamon/bike-trip-planner/issues/297) | ADR-028 : architecture Ollama/LLaMA (2 passes, context)            | S      | [#435](https://github.com/vincentchalamon/bike-trip-planner/pull/435) `feature/297` | —         |
| 2     | [#298](https://github.com/vincentchalamon/bike-trip-planner/issues/298) | Service OllamaClient PHP + configuration Docker Ollama             | M      | [#437](https://github.com/vincentchalamon/bike-trip-planner/pull/437) `feature/298` | —         |
| 3     | [#299](https://github.com/vincentchalamon/bike-trip-planner/issues/299) | Gate mechanism dans ComputationTracker                             | M      | [#438](https://github.com/vincentchalamon/bike-trip-planner/pull/438) `feature/299` | —         |
| 4     | [#300](https://github.com/vincentchalamon/bike-trip-planner/issues/300) | System prompts cyclotourisme versionnés (LLaMA 8B)                 | S      | [#436](https://github.com/vincentchalamon/bike-trip-planner/pull/436) `feature/300` | —         |

---

## Sprint 29 — LLaMA 8B : analyse 2 passes

Pipeline d'analyse IA : passe 1 par étape (parallélisable via Messenger), passe 2 vue d'ensemble, orchestration gate → LLaMA → TRIP_READY. **Décision (Sprint 29) : Ollama est une dépendance dure** — pas de fallback gracieux (cf. issue #375 arbitrage v2 « IA toujours active »). Issue #304 fermée, puis **rouverte par l'audit Sprint 35.2** : la décision est ré-inversée en **mode dégradé explicite** (cf. ADR-028 « Decision Update — Degraded Mode » + #616) ; livrée (#304 mergé).

| Ordre | ID                                                                      | Titre                                                     | Effort | PRs                                                                                | Dépend de      |
|-------|-------------------------------------------------------------------------|-----------------------------------------------------------|--------|------------------------------------------------------------------------------------|----------------|
| 1     | [#301](https://github.com/vincentchalamon/bike-trip-planner/issues/301) | LLaMA 8B passe 1 : analyse par étape via Messenger        | L      | [#441](https://github.com/vincentchalamon/bike-trip-planner/pull/441) `feature/301` | #298 #299 #300 |
| 2     | [#302](https://github.com/vincentchalamon/bike-trip-planner/issues/302) | LLaMA 8B passe 2 : vue d'ensemble du trip                 | M      | [#442](https://github.com/vincentchalamon/bike-trip-planner/pull/442) `feature/302` | #301           |
| 3     | [#303](https://github.com/vincentchalamon/bike-trip-planner/issues/303) | Pipeline gate → LLaMA → TRIP_READY event Mercure          | M      | [#443](https://github.com/vincentchalamon/bike-trip-planner/pull/443) `feature/303` | #299 #301 #302 |

---

## Sprint 30 — Frontend IA : résumés + affichage hybride

Affichage des résumés IA (global + par étape), layout hybride résumé/alertes repliables. **Issues #307 et #308 fermées** (arbitrage v2 #375 : IA toujours active, pas de bandeau « Actualiser », pas de fallback frontend).

| Ordre | ID                                                                      | Titre                                                                | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|----------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#305](https://github.com/vincentchalamon/bike-trip-planner/issues/305) | Résumé IA global dans "Mon voyage" (passe 2)                         | M      | [#447](https://github.com/vincentchalamon/bike-trip-planner/pull/447) `feature/305` | #302      |
| 2     | [#306](https://github.com/vincentchalamon/bike-trip-planner/issues/306) | Résumé IA par étape + layout hybride (résumé + alertes repliables)   | L      | [#448](https://github.com/vincentchalamon/bike-trip-planner/pull/448) `feature/306` | #301 #305 |

---

## Sprint 31 — Bulle IA (LLaMA 3B) : dialogue context-aware

Assistant conversationnel via bulle flottante, LLaMA 3B pour interpréter les intentions, intégration avec la recomputation inline. Le composant `AiBubble` consomme les tokens design du sprint 25.

| Ordre | ID                                                                      | Titre                                                                            | Effort | PRs | Dépend de |
|-------|-------------------------------------------------------------------------|----------------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#309](https://github.com/vincentchalamon/bike-trip-planner/issues/309) | System prompt dialogue LLaMA 3B + endpoint backend chat IA                       | M      | [#453](https://github.com/vincentchalamon/bike-trip-planner/pull/453) `feature/309` | #298      |
| 2     | [#310](https://github.com/vincentchalamon/bike-trip-planner/issues/310) | Composant AiBubble : bulle flottante + panneau chat                              | L      | [#454](https://github.com/vincentchalamon/bike-trip-planner/pull/454) `feature/310` | #309 sprint 25 |
| 3     | [#311](https://github.com/vincentchalamon/bike-trip-planner/issues/311) | Intégration bulle IA ↔ recomputation inline + skipAiAnalysis + toggle batch     | M      | [#455](https://github.com/vincentchalamon/bike-trip-planner/pull/455) `feature/311` | #309 #310 |

---

## Sprint 32 — Chat in-ride : assistant POI à proximité avec détour

Extension du chat LLaMA 3B (sprint 31) au cas d'usage **in-ride** : pendant un voyage, l'utilisateur consulte son trip et demande à l'assistant de trouver un POI proche (friterie, abri, eau, mécano…) pour gérer un imprévu (faim, pluie, panne). Le chat existant (`POST /trips/{id}/chat`) détecte la présence d'une **position GPS** dans le payload pour basculer en mode in-ride : recherche Overpass + filtrage `opening_hours` + calcul approximatif du détour (Haversine + projection orthogonale) + deeplink Google Maps. Pas de recalcul GPX (V2 future) — l'itinéraire de base reste inchangé.

| Ordre | ID  | Titre                                                                                                                | Effort | PRs | Dépend de |
|-------|-----|----------------------------------------------------------------------------------------------------------------------|--------|-----|-----------|
| 1     | [#458](https://github.com/vincentchalamon/bike-trip-planner/issues/458) | Entité `TripChatMessage` + repository + migration Doctrine (persistance long-terme par trip)                         | M      | [#467](https://github.com/vincentchalamon/bike-trip-planner/pull/467) `feature/458` | sprint 31    |
| 2     | [#459](https://github.com/vincentchalamon/bike-trip-planner/issues/459) | Endpoint `GET /trips/{id}/chat-history` : pagination de l'historique persisté                                        | S      | [#471](https://github.com/vincentchalamon/bike-trip-planner/pull/471) `feature/459` | #458         |
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
  - [ ] Persistance : refresh page → historique rechargé depuis `/chat-history`
  - [ ] Refus géoloc → message clair, le chat planning continue de fonctionner
  - [ ] DevTools Offline → badge offline + bouton désactivé
  - [ ] Non-régression : les 7 actions planning existantes (split, merge, add waypoint, change accommodation, adjust distance, change route, info) marchent sans géoloc
  - [ ] PHPStan L9 OK, ESLint OK, Playwright OK
  - [ ] `make typegen` : types frontend cohérents avec `TripChatResponse` étendu

---

## Sprint 33 — OSM Data Refresh quotidien

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

---

## Sprint 34 — Analytics d'usage & conformité RGPD + parcours compte

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

---

## Sprint 34.5 — Right-sizing beta Free Tier (profil <10 users)

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
  - [ ] Tuiles OSM France servies (`/route` OK) ; runbook `docs/runbooks/osm-france-refresh.md` rejouable
  - [ ] `make qa` green

---

## Sprint 35 — Outillage d'audit

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

---

## Sprint 35.1 — Référentiel de recette

Spec commune à l'audit fonctionnel automatisé (35.3) et à la recette manuelle. Ne dépend pas de l'outillage, donc parallélisable avec 35. Livrables sous `docs/recette/`.

Livré en une PR unique (livrables fortement couplés sous `docs/recette/`).

| Ordre | Titre | Effort | PR |
|---|---|---|---|
| 1 | Inventaire des écrans (dérivé de `pwa/src/app/`) + variantes (auth/anon, états données) | S | [#607](https://github.com/vincentchalamon/bike-trip-planner/pull/607) ✅ mergée |
| 2 | Checklist par écran : éléments, comportements, états (hover/focus/disabled/loading/empty/error), responsive, a11y clavier | M | [#607](https://github.com/vincentchalamon/bike-trip-planner/pull/607) ✅ mergée |
| 3 | Manifeste d'éléments attendus par écran (présence + position approximative) dérivé de l'export Claude Design | M | [#607](https://github.com/vincentchalamon/bike-trip-planner/pull/607) ✅ mergée |
| 4 | Audit de couverture Gherkin (30 `.feature` vs features réelles) + scénarios manquants à écrire (IA S30-32, design S25-27) | M | [#607](https://github.com/vincentchalamon/bike-trip-planner/pull/607) ✅ mergée |

Source design : export Claude Design vendoré sous `docs/recette/design/` (`tokens.jsx`, `pages-*.jsx`, `toutes-les-pages.html`). **Comparaison app vs design = présence + position approximative** (auto, Playwright) ; couleur / typo / polish = **regard humain** (capture côte-à-côte).

---

## Sprint 35.2 — Audit non-fonctionnel & qualité

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

---

## Sprint 35.3 — Audit fonctionnel + couverture

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

## Recette manuelle (utilisateur)

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

---

## Sprint 35.4 — Corrections

Fixe les findings de 35.2 + 35.3 + recette manuelle, requêtés par **milestone `Sprint 35.4`** (l'état des issues n'étant pas fiable). Modèle worktree-parallèle (`/sprint`). _(ex-ordres 32-36)_

**⏩ Avancé en pré-recette (batch durcissement, 5 PRs, avant la recette manuelle)** — corrige le sous-ensemble déterministe et code-local des findings du rapport pour que la recette parte d'une app durcie :

- [#629](https://github.com/vincentchalamon/bike-trip-planner/pull/629) `fix(security)` — **finalité de suppression de compte** (bug surfacé hors-rapport : un compte supprimé pouvait se ré-authentifier) : `DeletedUserChecker` (user_checker), refus `isDeleted` dans AuthVerify/AuthRefresh, purge `magic_link` (RGPD-MAGIC) + `access_request` (PII résiduelle). 5 tests.
- [#630](https://github.com/vincentchalamon/bike-trip-planner/pull/630) `fix(security)` — **SEC-002/003/004** (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy) + **SEC-001** CSP en **Report-Only** (enforce à acter après observation recette) + **SEC-005** (x-powered-by).
- [#632](https://github.com/vincentchalamon/bike-trip-planner/pull/632) `feat(seo)` — **SEO-001/002/003** (OG/Twitter, robots, sitemap, métadonnées de partage) + **I18N-001** (onError).
- [#633](https://github.com/vincentchalamon/bike-trip-planner/pull/633) `fix(a11y)` — **A11Y-001/002 + LH-A11Y-HOME** (SSR de la landing : `<main>` + `<h1>`).
- [#631](https://github.com/vincentchalamon/bike-trip-planner/pull/631) `perf` — **PERF-001 / LH-PERF-AUTH** (lazy-load MapPanel, maplibre hors du 1er chunk éditeur).

**Reste 35.4** (non avancé) : COV-API/COV-FRONT/QUAL-001/002 (couverture), DT-LIVE, F5 (Overpass), CHAOS-RESTART, promotion CSP enforce, + findings de la recette manuelle. Détails et nouveau finding (auth-bypass / access_request) dans [`docs/recette/audit-report.md`](docs/recette/audit-report.md).

| Ordre | Titre | Effort |
|---|---|---|
| 1 | Headers de sécurité Caddy (CSP / HSTS / X-Frame-Options / X-Content-Type-Options) | S — ⏩ #630 (CSP report-only ; enforce à finaliser) |
| 2 | P0/P1 : bugs bloquants + fonctionnels dégradés | L — auth-bypass compte supprimé ⏩ #629 |
| 3 | P2 : régressions UX/UI | M — A11Y ⏩ #633, SEO ⏩ #632 |
| 4 | P3 : performance et polish | M — PERF-001 ⏩ #631, I18N-001 ⏩ #632 |
| 5 | Re-test golden path A final (gate de clôture) | M |

DoD : toutes les issues P0-P3 fermées ou explicitement reportées ; golden path A re-testé vert.

---

## Sprint 36 — Garmin Connect

Export FIT natif (Phase 1) et push vers Garmin Connect via OAuth 2.0 PKCE (Phase 2). Voir [ADR-018](docs/adr/adr-018-garmin-export-and-device-sync-strategy.md). Test local via ngrok pour le callback OAuth. Le visuel des menus downloads et de la modale partage a déjà été refondu en sprint 27 — cette implémentation y ajoute l'option FIT et la 4ᵉ section Garmin Connect en consommant les tokens design en place.

> **Prérequis :** s'inscrire au [Garmin Developer Program](https://developer.garmin.com/) quelques sprints en avance (~2 jours d'approbation).

| Ordre | ID                                                                    | Titre          | Effort | PRs | Dépend de                                                                                                                                                  |
|-------|-----------------------------------------------------------------------|----------------|--------|-----|------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1     | [#65](https://github.com/vincentchalamon/bike-trip-planner/issues/65) | Garmin Connect | L      | 3   | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76), sprint 27                                                                            |

### Recette Sprint 36

- **Tests E2E :** `tests/recette/sprint-36.spec.ts`
- **Checklist manuelle :**
  - [ ] Export FIT téléchargeable par étape
  - [ ] Flux OAuth Garmin Connect complet (via ngrok)
  - [ ] Push course vers Garmin Connect fonctionnel
  - [ ] Gestion erreurs : token expiré, API indisponible

---

## Sprint 37 — Déploiement

Mise en production basée sur [ADR-019](docs/adr/adr-019-deployment-infrastructure-strategy.md). Issues GitHub à créer au moment venu. **Inclut Ollama** dans la stack production (conséquence de la décision « Ollama = dépendance dure » prise au sprint 29).

| Ordre | Étape                                              | Effort |
|-------|----------------------------------------------------|--------|
| 1     | CI/CD pipeline production                          | M      |
| 2     | Oracle Cloud (OCI) Always Free provisioning        | M      |
| 3     | Coolify installation + configuration               | M      |
| 4     | Configuration DNS (FreeDNS)                        | S      |
| 5     | Docker configs production (PostgreSQL, Redis, Mercure, Caddy, **Ollama** + healthcheck) | L      |
| 6     | Monitoring & healthchecks (incl. Ollama latence/disponibilité) | M      |
| 7     | Migration données + smoke test production (incl. passe LLaMA 8B) | M      |
| 8     | [#312](https://github.com/vincentchalamon/bike-trip-planner/issues/312) Feature-deploy : preview par PR (Étapes 1-7) | L |
| 9     | [#270](https://github.com/vincentchalamon/bike-trip-planner/issues/270) Génération du keypair JWT sur le serveur (compose secrets + runbook `secrets-inventory.md`) | S |

### Recette Sprint 37

- **Checklist manuelle :**
  - [ ] Application accessible via URL publique
  - [ ] HTTPS fonctionnel (certificat TLS auto Caddy)
  - [ ] PostgreSQL + Redis opérationnels en production
  - [ ] Mercure SSE fonctionnel en production
  - [ ] **Ollama opérationnel** (modèles llama3.1:8b et llama3.2:3b chargés, healthcheck OK)
  - [ ] **Pipeline LLaMA 8B fonctionnel en prod** (passe 1 + passe 2 → TRIP_READY)
  - [ ] CI/CD : déploiement automatique sur push main
  - [ ] Monitoring : healthchecks + alertes basiques (incl. latence Ollama)
  - [ ] Garmin Connect : callback OAuth sur URL production
  - [ ] Preview déployée via label `deploy:preview` sur une PR de test
  - [ ] URL `pr-<N>.biketrip.example.com` accessible en HTTPS
  - [ ] Cleanup automatique à la fermeture de la PR

---

## Sprint 38 — Performance & Resilience Deep Dive

Suite à Sprint 35 (recette + audits standards), Sprint 38 industrialise l'analyse de performance avancée : micro-benchs PHP, load testing scriptable, profiling à la demande, observabilité applicative ciblée, audit infrastructure, résilience aux pannes, et empreinte carbone publiée. Tests menés en iso-prod sur la VM Oracle pré-ouverture, avec validation device physique (Samsung Galaxy S20 FE).

> **Prérequis :** Sprint 37 (Déploiement) terminé, app déployée iso-prod sur Oracle + Coolify, GlitchTip + Uptime Kuma opérationnels (ADR-031).

| Ordre | ID                                                                    | Titre                                                                                                              | Effort | PRs | Dépend de                                                                                                                                                  |
|-------|-----------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------|--------|-----|------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1     | [#508](https://github.com/vincentchalamon/bike-trip-planner/issues/508) | feat(perf): PHPBench hot paths + in-ride benchs                                                                  | M      | —   | —                                                                                                                                                          |
| 2     | [#509](https://github.com/vincentchalamon/bike-trip-planner/issues/509) | feat(perf): k6 scenarios + fixtures Komoot/GPX + Makefile                                                        | M      | —   | —                                                                                                                                                          |
| 3     | [#510](https://github.com/vincentchalamon/bike-trip-planner/issues/510) | feat(observability): Postgres pg_stat_statements + Redis SLOWLOG + Messenger queue depth                          | S      | —   | —                                                                                                                                                          |
| 4     | [#511](https://github.com/vincentchalamon/bike-trip-planner/issues/511) | feat(ci): perf.yml workflow (PHPBench + k6 + bundle-analyzer nightly)                                            | S      | —   | [#508](https://github.com/vincentchalamon/bike-trip-planner/issues/508), [#509](https://github.com/vincentchalamon/bike-trip-planner/issues/509)            |
| 5     | [#512](https://github.com/vincentchalamon/bike-trip-planner/issues/512) | feat(perf): Lighthouse CI desktop + mobile (S20 FE preset)                                                       | S      | —   | —                                                                                                                                                          |
| 6     | [#513](https://github.com/vincentchalamon/bike-trip-planner/issues/513) | docs(perf): S20 FE physical device test checklist + IndexedDB perf                                               | S      | —   | —                                                                                                                                                          |
| 7     | [#514](https://github.com/vincentchalamon/bike-trip-planner/issues/514) | feat(eco): Carbon footprint measure + public /eco page + CarbonStatsCard                                         | M      | —   | [#512](https://github.com/vincentchalamon/bike-trip-planner/issues/512)                                                                                    |
| 8     | [#515](https://github.com/vincentchalamon/bike-trip-planner/issues/515) | feat(observability): Sentry custom spans backend (Ollama/InRide/Overpass/Valhalla/TripDetail/TripChat/Mercure)    | M      | —   | —                                                                                                                                                          |
| 9     | [#516](https://github.com/vincentchalamon/bike-trip-planner/issues/516) | feat(observability): Sentry spans frontend + Mercure SSE end-to-end latency                                      | M      | —   | [#515](https://github.com/vincentchalamon/bike-trip-planner/issues/515)                                                                                    |
| 10    | [#517](https://github.com/vincentchalamon/bike-trip-planner/issues/517) | fix(perf): ChatHistoryLoader AbortController + PoiCard React.memo                                                | S      | —   | —                                                                                                                                                          |
| 11    | [#518](https://github.com/vincentchalamon/bike-trip-planner/issues/518) | feat(perf): Excimer + Speedscope ad-hoc profiling on iso-prod                                                    | M      | —   | —                                                                                                                                                          |
| 12    | [#519](https://github.com/vincentchalamon/bike-trip-planner/issues/519) | feat(ci): smoke-test extended with latency assertions + Lighthouse post-deploy                                   | S      | —   | [#512](https://github.com/vincentchalamon/bike-trip-planner/issues/512)                                                                                    |
| 13    | [#520](https://github.com/vincentchalamon/bike-trip-planner/issues/520) | docs(perf): GlitchTip dashboards documentation + slow-trip-computation runbook                                   | S      | —   | [#515](https://github.com/vincentchalamon/bike-trip-planner/issues/515), [#516](https://github.com/vincentchalamon/bike-trip-planner/issues/516)            |
| 14    | [#521](https://github.com/vincentchalamon/bike-trip-planner/issues/521) | chore(infra): Caddy audit (Brotli, cache headers, HTTP/3, Server-Timing)                                         | S      | —   | —                                                                                                                                                          |
| 15    | [#522](https://github.com/vincentchalamon/bike-trip-planner/issues/522) | chore(infra): Postgres indexes audit + Redis bigkeys audit                                                       | M      | —   | [#510](https://github.com/vincentchalamon/bike-trip-planner/issues/510)                                                                                    |
| 16    | [#523](https://github.com/vincentchalamon/bike-trip-planner/issues/523) | feat(resilience): chaos test scripts (Ollama/Overpass/Valhalla/Mercure/Redis/Postgres down) + resilience-tests doc | M      | —   | —                                                                                                                                                          |
| 17    | [#524](https://github.com/vincentchalamon/bike-trip-planner/issues/524) | feat(resilience): OOM / VM recovery test + update oracle-vm-reclaimed runbook                                    | S      | —   | [#523](https://github.com/vincentchalamon/bike-trip-planner/issues/523)                                                                                    |
| 18    | [#525](https://github.com/vincentchalamon/bike-trip-planner/issues/525) | feat(perf): inter-release baselines comparison script + CI diff comment                                          | S      | —   | [#508](https://github.com/vincentchalamon/bike-trip-planner/issues/508), [#509](https://github.com/vincentchalamon/bike-trip-planner/issues/509), [#512](https://github.com/vincentchalamon/bike-trip-planner/issues/512) |

### Recette Sprint 38

- **Tests E2E :** `tests/recette/sprint-38.spec.ts` (nouveaux scénarios perf + résilience)
- **Checklist manuelle :**
  - [ ] `make phpbench` exécute les 7 benchs, baselines stockées dans `docs/perf/baselines/<release>/`
  - [ ] `make perf-load TARGET=https://biketrip.mooo.com` exécute les 5 scénarios k6 cold + hot, baselines stockées
  - [ ] Workflow CI `perf.yml` exécute Lighthouse desktop + mobile à chaque PR avec assertions
  - [ ] Page `/eco` publique affiche l'empreinte carbone mesurée, lien depuis footer
  - [ ] GlitchTip Performance UI montre les transactions custom (Ollama, InRide, Mercure, etc.)
  - [ ] `coolify env set EXCIMER_ENABLED=1` + `curl -H "X-Profile: 1" .../api/trips/<id>` produit un Speedscope JSON exploitable
  - [ ] Smoke-test post-deploy échoue si `/api/health` deps latency dépasse les seuils
  - [ ] Session S20 FE physique : checklist `docs/perf/mobile-device-test.md` complétée, baselines enregistrées
  - [ ] Caddy : Brotli actif, cache headers immutable sur statics, HTTP/3 activé
  - [ ] Postgres `pg_stat_statements` actif, slow query log opérationnel
  - [ ] Chaos tests : Ollama/Overpass/Valhalla/Mercure down → app dégrade proprement (pas de 500, message user clair)
  - [ ] OOM test : time-to-recovery VM mesuré, runbook `oracle-vm-reclaimed.md` updaté
  - [ ] `scripts/perf-diff.sh <a> <b>` produit un Markdown lisible de comparaison de releases
  - [ ] Tous les SLOs cibles du plan validés ou écarts documentés en issues `[perf-debt]`

---

## Sprint 39 — Backup & Disaster Recovery

Sprint dédié à la résilience de la donnée en production. ADR-032 (Migrations & Rollback) appelle explicitement un "future plan PostgreSQL backup" en §51-59 : c'est ce sprint. La stack opérationnelle (Coolify, GlitchTip, Uptime Kuma, /api/health, 14 runbooks) et la résilience services (Sprint 38 chaos tests + OOM recovery) sont en place ; il reste à protéger la donnée elle-même. Sans ce sprint, un DROP TABLE accidentel, une destructive migration shippée par erreur, ou une perte de la VM Oracle entraîne une perte de données irréversible.

> **Prérequis :** Sprint 37 (Déploiement) et Sprint 38 (Perf & Resilience) terminés. App déployée iso-prod sur Oracle + Coolify, GlitchTip + Uptime Kuma + Sentry spans + chaos tests opérationnels.
>
> **Bloque :** ouverture publique. Tant que ce sprint n'est pas livré, le projet reste en iso-prod sans données utilisateur réelles.

| Ordre | ID                                                                      | Titre                                                                                  | Effort | PRs | Dépend de                                                                                                                                                                                                                                                                                       |
|-------|-------------------------------------------------------------------------|----------------------------------------------------------------------------------------|--------|-----|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 1     | [#527](https://github.com/vincentchalamon/bike-trip-planner/issues/527) | docs(adr): add adr-038 backup and disaster recovery strategy                           | S      | —   | —                                                                                                                                                                                                                                                                                               |
| 2     | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528) | feat(backup): add backup container with pg_dump + age + zstd (local volume)            | M      | —   | [#527](https://github.com/vincentchalamon/bike-trip-planner/issues/527)                                                                                                                                                                                                                         |
| 3     | [#529](https://github.com/vincentchalamon/bike-trip-planner/issues/529) | feat(backup): wire OCI Object Storage + Backblaze B2 destinations with object lock     | M      | —   | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528)                                                                                                                                                                                                                         |
| 4     | [#530](https://github.com/vincentchalamon/bike-trip-planner/issues/530) | feat(backup): add secrets bundle export (Coolify API + JWT PEMs)                       | S      | —   | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528)                                                                                                                                                                                                                         |
| 5     | [#531](https://github.com/vincentchalamon/bike-trip-planner/issues/531) | feat(backup): backup freshness endpoint + Uptime Kuma monitor                          | S      | —   | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528)                                                                                                                                                                                                                         |
| 6     | [#532](https://github.com/vincentchalamon/bike-trip-planner/issues/532) | docs(runbooks): disaster recovery + backup architecture                                | M      | —   | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528), [#529](https://github.com/vincentchalamon/bike-trip-planner/issues/529), [#530](https://github.com/vincentchalamon/bike-trip-planner/issues/530)                                                                       |
| 7     | [#534](https://github.com/vincentchalamon/bike-trip-planner/issues/534) | feat(backup): partial restore script with FK safety + compose.restore.yaml             | S      | —   | [#528](https://github.com/vincentchalamon/bike-trip-planner/issues/528), [#532](https://github.com/vincentchalamon/bike-trip-planner/issues/532)                                                                                                                                                 |
| 8     | [#535](https://github.com/vincentchalamon/bike-trip-planner/issues/535) | ci: pre-deploy backup step in deploy workflow with [skip backup] bypass                | S      | —   | [#529](https://github.com/vincentchalamon/bike-trip-planner/issues/529)                                                                                                                                                                                                                         |
| 9     | [#536](https://github.com/vincentchalamon/bike-trip-planner/issues/536) | ci: monthly restore drill workflow dispatching incident-create on failure              | M      | —   | [#529](https://github.com/vincentchalamon/bike-trip-planner/issues/529), [#532](https://github.com/vincentchalamon/bike-trip-planner/issues/532)                                                                                                                                                 |

> **Réalignement (#533).** L'inventaire des secrets (`docs/runbooks/secrets-inventory.md`) et la politique de rotation (`docs/runbooks/secrets-rotation.md`) ont été livrés en avance par la PR #533. #532 ne couvre donc plus que `disaster-recovery.md` + `backup-architecture.md` et doit _lier_ la rotation existante plutôt que la redocumenter ; #527 crée l'ADR-038 (033 étant déjà pris par l'OSM refresh) et doit référencer ces deux runbooks.

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

---

## Hors Sprints

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

---

## Récapitulatif

| Sprint    | Thème                                    | Tickets | PRs estimées |
|-----------|------------------------------------------|---------|--------------|
| 1         | Quick Wins Alertes                       | 5       | 5            |
| 2         | Alertes Frontend + UX                    | 3       | 3            |
| 3         | Hébergements                             | 3       | 4            |
| 4         | Configuration & Profil                   | 4       | 4            |
| 5         | Météo & Temps                            | 3       | 4            |
| 6         | Export                                   | 3       | 3            |
| 7         | Carte Interactive                        | 4       | 9            |
| 8         | UX & Onboarding                          | 3       | 4            |
| 9         | Sources Routes & Infra                   | 3       | 5            |
| 10        | i18n & Documentation                     | 5       | 7            |
| 11        | Persistance                              | 1       | 6            |
| 12        | Gestion Trips                            | 3       | 4            |
| 13        | Auth & Sécurité                          | 5       | 8            |
| 14        | Partage                                  | 2       | 3            |
| 15        | Mobile                                   | 6       | 11           |
| 16        | Recette Globale                          | 7       | ~6           |
| 17        | Performance pipeline async               | 4       | ~4           |
| 18        | Alertes actionnables + règles            | 8       | ~9           |
| 19        | Landing page + accès anticipé            | 5       | ~6           |
| 20        | Sources de données enrichies             | 8       | ~8           |
| 21        | Stepper + Refonte du flux                | 3       | ~3           |
| 22        | Acte 1 Card Selection + Aperçu           | 3       | ~3           |
| 23        | Acte 2 + Acte 3 : progression            | 3       | ~4           |
| 24        | UX avancé : recomputation                | 3       | ~4           |
| **25**    | **Design Foundations** (issue #375)      | **5**   | **~5**       |
| **26**    | **Refonte Roadbook + Wizard** (#375)     | **8**   | **~10**      |
| **27**    | **Reste du design** (#375)               | **7**   | **~7**       |
| 28        | Intégration Ollama : fondations          | 4       | ~4           |
| 29        | LLaMA 8B : analyse 2 passes              | 3       | ~4           |
| 30        | Frontend IA : résumés hybrides           | 2       | ~3           |
| 31        | Bulle IA (LLaMA 3B) dialogue             | 3       | ~4           |
| 32        | Chat in-ride : POI + détour              | 8       | ~8           |
| 33        | OSM Data Refresh quotidien               | 4       | ~4           |
| 34        | Analytics Plausible + RGPD + parcours compte | 10    | ~10          |
| 34.5      | Right-sizing beta Free Tier              | 8       | ~8           |
| 35        | Recette complète & Audit                 | 36      | ~12          |
| 36        | Garmin Connect                           | 1       | 3            |
| 37        | Déploiement (incl. Ollama prod)          | 8       | ~8           |
| 38        | Performance & Resilience Deep Dive       | 18      | ~12          |
| 39        | Backup & Disaster Recovery               | 9       | ~9           |
| **Total** |                                          | **231** | **~235**     |
