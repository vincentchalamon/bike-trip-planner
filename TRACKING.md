# Tracking

> **Note :** Dépendance circulaire #76 <-> #56 cassée volontairement : l'auth peut être implémentée avec sa propre table users sans attendre la persistance complète.

---

## Sprint 1 — Quick Wins Alertes

Backend pur, pattern `StageAnalyzerInterface` + `#[AutoconfigureTag]`. Reviews rapides (~100-150 lignes/PR).

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#88](https://github.com/vincentchalamon/bike-trip-planner/issues/88) | Alerte calendrier : dimanches | S | [#110](https://github.com/vincentchalamon/bike-trip-planner/pull/110) `feature/88` | — |
| 2 | [#63](https://github.com/vincentchalamon/bike-trip-planner/issues/63) | Détection des pentes raides | S | [#111](https://github.com/vincentchalamon/bike-trip-planner/pull/111) `feature/63` | — |
| 3 | [#66](https://github.com/vincentchalamon/bike-trip-planner/issues/66) | Détecter les points de charge VAE | S | [#112](https://github.com/vincentchalamon/bike-trip-planner/pull/112) `feature/66` | — |
| 4 | [#58](https://github.com/vincentchalamon/bike-trip-planner/issues/58) | Détection des points d'eau | M | [#116](https://github.com/vincentchalamon/bike-trip-planner/pull/116) `feature/58` | — |
| 5 | [#54](https://github.com/vincentchalamon/bike-trip-planner/issues/54) | Correction des dénivelés sous-estimés | M | [#117](https://github.com/vincentchalamon/bike-trip-planner/pull/117) `feature/54` | — |

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

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#28](https://github.com/vincentchalamon/bike-trip-planner/issues/28) | Résumer les suggestions et détections | S | [#160](https://github.com/vincentchalamon/bike-trip-planner/pull/160) `feature/28` | — |
| 2 | [#41](https://github.com/vincentchalamon/bike-trip-planner/issues/41) | Badge de difficulté avec jauge visuelle | S | [#161](https://github.com/vincentchalamon/bike-trip-planner/pull/161) `feature/41` | — |
| 3 | [#40](https://github.com/vincentchalamon/bike-trip-planner/issues/40) | Barre de progression segmentée | M | [#162](https://github.com/vincentchalamon/bike-trip-planner/pull/162) `feature/40` | — |

### Recette Sprint 2

- **Tests E2E :** `tests/recette/sprint-02.spec.ts`
- **Checklist manuelle :**
  - [ ] ~~Résumé des alertes visible dans le panneau trip~~
  - [x] Badge de difficulté avec jauge colorée par étape
  - [x] Barre de progression reflétant l'avancement du trip

---

## Sprint 3 — Hébergements

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#38](https://github.com/vincentchalamon/bike-trip-planner/issues/38) | Distance hébergement-endPoint | S | [#167](https://github.com/vincentchalamon/bike-trip-planner/pull/167) `feature/38` | — |
| 2 | [#37](https://github.com/vincentchalamon/bike-trip-planner/issues/37) | Rayon de recherche | M | [#165](https://github.com/vincentchalamon/bike-trip-planner/pull/165) `feature/37` | — |
| 3 | [#39](https://github.com/vincentchalamon/bike-trip-planner/issues/39) | Sélectionner un hébergement | L | [#166](https://github.com/vincentchalamon/bike-trip-planner/pull/166) `feature/39` | — |

### Recette Sprint 3

- **Tests E2E :** `tests/recette/sprint-03.spec.ts`
- **Checklist manuelle :**
  - [x] Distance hébergement-endPoint affichée
  - [x] Modification du rayon de recherche + résultats mis à jour
  - [x] Sélection d'un hébergement → recalcul itinéraire (endPoint + startPoint étape suivante)
  - [x] Responsive : vérifier sur mobile

---

## Sprint 4 — Configuration & Profil

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#48](https://github.com/vincentchalamon/bike-trip-planner/issues/48) | Profil cyclo + presets | M | [#170](https://github.com/vincentchalamon/bike-trip-planner/pull/170) `feature/48` | — |
| 2 | [#49](https://github.com/vincentchalamon/bike-trip-planner/issues/49) | Panneau configuration (sidebar) | M | [#172](https://github.com/vincentchalamon/bike-trip-planner/pull/172) `feature/49` | [#48](https://github.com/vincentchalamon/bike-trip-planner/issues/48) |
| 3 | [#36](https://github.com/vincentchalamon/bike-trip-planner/issues/36) | Filtre types d'hébergements | M | [#173](https://github.com/vincentchalamon/bike-trip-planner/pull/173) `feature/36` | [#49](https://github.com/vincentchalamon/bike-trip-planner/issues/49) |
| 4 | [#55](https://github.com/vincentchalamon/bike-trip-planner/issues/55) | Insertion jours de repos | M | [#171](https://github.com/vincentchalamon/bike-trip-planner/pull/171) `feature/55` | — |

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

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#43](https://github.com/vincentchalamon/bike-trip-planner/issues/43) | Météo étendue vent + confort | L | [#177](https://github.com/vincentchalamon/bike-trip-planner/pull/177) `feature/43` | — |
| 2 | [#61](https://github.com/vincentchalamon/bike-trip-planner/issues/61) | Estimation temps de parcours | M | [#178](https://github.com/vincentchalamon/bike-trip-planner/pull/178) `feature/61` | [#48](https://github.com/vincentchalamon/bike-trip-planner/issues/48) |
| 3 | [#62](https://github.com/vincentchalamon/bike-trip-planner/issues/62) | Horaires soleil + alerte nocturne | M | [#179](https://github.com/vincentchalamon/bike-trip-planner/pull/179) `feature/62` | [#61](https://github.com/vincentchalamon/bike-trip-planner/issues/61) |

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

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#47](https://github.com/vincentchalamon/bike-trip-planner/issues/47) | Exporter au format texte | S | [#184](https://github.com/vincentchalamon/bike-trip-planner/pull/184) `feature/47`, [#186](https://github.com/vincentchalamon/bike-trip-planner/pull/186) `feature/export-display-fixes` | — |
| 2 | [#64](https://github.com/vincentchalamon/bike-trip-planner/issues/64) | Téléchargement GPX global | S | [#182](https://github.com/vincentchalamon/bike-trip-planner/pull/182) `feature/64`, [#186](https://github.com/vincentchalamon/bike-trip-planner/pull/186) `feature/export-display-fixes` | — |
| 3 | [#59](https://github.com/vincentchalamon/bike-trip-planner/issues/59) | Budget récapitulatif | S | [#185](https://github.com/vincentchalamon/bike-trip-planner/pull/185) `feature/59`, [#186](https://github.com/vincentchalamon/bike-trip-planner/pull/186) `feature/export-display-fixes` | — |

### Recette Sprint 6

- **Tests E2E :** `tests/recette/sprint-06.spec.ts`
- **Checklist manuelle :**
  - [x] Export texte complet et formaté
  - [x] Téléchargement GPX global fonctionnel
  - [x] Budget récapitulatif avec totaux cohérents

---

## Sprint 7 — Carte Interactive

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#30](https://github.com/vincentchalamon/bike-trip-planner/issues/30) | Carte interactive + profil altimétrique | XL | [#187](https://github.com/vincentchalamon/bike-trip-planner/pull/187) `feature/30` | — |
| 2 | [#31](https://github.com/vincentchalamon/bike-trip-planner/issues/31) | Split view carte / timeline | M | [#190](https://github.com/vincentchalamon/bike-trip-planner/pull/190) `feature/31` | [#30](https://github.com/vincentchalamon/bike-trip-planner/issues/30) |
| 3 | [#34](https://github.com/vincentchalamon/bike-trip-planner/issues/34) | Timeline ravitaillement | L | [#189](https://github.com/vincentchalamon/bike-trip-planner/pull/189) `feature/34` | [#58](https://github.com/vincentchalamon/bike-trip-planner/issues/58) |
| 4 | [#35](https://github.com/vincentchalamon/bike-trip-planner/issues/35) | Points d'intérêt culturels | M | [#188](https://github.com/vincentchalamon/bike-trip-planner/pull/188) `feature/35` | — |

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

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#32](https://github.com/vincentchalamon/bike-trip-planner/issues/32) | Onboarding guide | S | [#200](https://github.com/vincentchalamon/bike-trip-planner/pull/200) `feature/32`, [#203](https://github.com/vincentchalamon/bike-trip-planner/pull/203) `fix/sprint-8` | — |
| 2 | [#57](https://github.com/vincentchalamon/bike-trip-planner/issues/57) | Undo/Redo | L | [#201](https://github.com/vincentchalamon/bike-trip-planner/pull/201) `feature/57` | — |
| 3 | [#33](https://github.com/vincentchalamon/bike-trip-planner/issues/33) | Raccourcis clavier + aide | M | [#202](https://github.com/vincentchalamon/bike-trip-planner/pull/202) `feature/33`, [#203](https://github.com/vincentchalamon/bike-trip-planner/pull/203) `fix/sprint-8` | [#57](https://github.com/vincentchalamon/bike-trip-planner/issues/57) |

### Recette Sprint 8

- **Tests E2E :** `tests/recette/sprint-08.spec.ts`
- **Checklist manuelle :**
  - [x] Onboarding guide affiché au premier lancement
  - [x] Raccourcis clavier fonctionnels (Ctrl+Z, Ctrl+Y, etc.)
  - [x] Bouton aide affichant la liste des raccourcis
  - [x] Undo/Redo sur les actions clés (suppression étape, modification distance)

---

## Sprint 9 — Sources de Routes & Infra Backend

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#60](https://github.com/vincentchalamon/bike-trip-planner/issues/60) | Sources routes supplémentaires | L | [#214](https://github.com/vincentchalamon/bike-trip-planner/pull/214) `feature/60` | — |
| 2 | [#53](https://github.com/vincentchalamon/bike-trip-planner/issues/53) | Création trip via URL | S | [#213](https://github.com/vincentchalamon/bike-trip-planner/pull/213) `feature/53` | — |
| 3 | [#46](https://github.com/vincentchalamon/bike-trip-planner/issues/46) | Invalidation messages Messenger | M | [#215](https://github.com/vincentchalamon/bike-trip-planner/pull/215) `feature/46` | — |

### Recette Sprint 9

- **Tests E2E :** `tests/recette/sprint-09.spec.ts`
- **Checklist manuelle :**
  - [ ] Upload GPX direct fonctionnel (drag & drop)
  - [ ] Import depuis Strava/RideWithGPS (si implémenté) (en attente de trace de test)
  - [x] Création de trip via URL avec paramètre link
  - [ ] Invalidation Messenger : pas de messages orphelins (comment tester ?)

---

## Sprint 10 — i18n & Documentation

| Ordre | ID | Titre | Effort | PRs                                                                  | Dépend de |
|-------|----|-------|--------|----------------------------------------------------------------------|-----------|
| 1 | [#44](https://github.com/vincentchalamon/bike-trip-planner/issues/44) | Support multi-langue (fr/en) | L | 3                                                                    | — |
| 2 | [#70](https://github.com/vincentchalamon/bike-trip-planner/issues/70) | i18n client-side export statique | S | 1                                                                    | [#44](https://github.com/vincentchalamon/bike-trip-planner/issues/44) |
| 3 | [#26](https://github.com/vincentchalamon/bike-trip-planner/issues/26) | Traduire documentation en français | S | 1                                                                    | — |
| 4 | [#27](https://github.com/vincentchalamon/bike-trip-planner/issues/27) | Améliorer présentation documentation | S | 1                                                                    | — |
| 5 | [#29](https://github.com/vincentchalamon/bike-trip-planner/issues/29) | Changer la licence | S | [#96](https://github.com/vincentchalamon/bike-trip-planner/pull/96) `fix/29-agpl-v3-license` | — |

### Recette Sprint 10

- **Tests E2E :** `tests/recette/sprint-10.spec.ts`
- **Checklist manuelle :**
  - [ ] Switcher fr/en fonctionnel
  - [ ] Tous les textes traduits (pas de clés i18n visibles)
  - [ ] Export statique compatible i18n
  - [ ] Documentation en français complète
  - [ ] Licence mise à jour

---

## Sprint 11 — Gestion des Trips (pré-persistance)

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#50](https://github.com/vincentchalamon/bike-trip-planner/issues/50) | Liste des trips | L | 2 | — |
| 2 | [#45](https://github.com/vincentchalamon/bike-trip-planner/issues/45) | Duplication de trip | M | 1 | — |
| 3 | [#52](https://github.com/vincentchalamon/bike-trip-planner/issues/52) | Verrouillage trips passés | M | 1 | — |

### Recette Sprint 11

- **Tests E2E :** `tests/recette/sprint-11.spec.ts`
- **Checklist manuelle :**
  - [ ] Liste des trips paginée et filtrable
  - [ ] Navigation liste → détail → retour
  - [ ] Duplication de trip fonctionnelle
  - [ ] Verrouillage automatique des trips passés (lecture seule)
  - [ ] Responsive : liste sur mobile

---

## Sprint 12 — Auth & Sécurité

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#75](https://github.com/vincentchalamon/bike-trip-planner/issues/75) | ADR auth passwordless | S | 1 | — |
| 2 | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76) | Auth backend JWT + magic link | L | 4 | [#75](https://github.com/vincentchalamon/bike-trip-planner/issues/75) |
| 3 | [#79](https://github.com/vincentchalamon/bike-trip-planner/issues/79) | Frontend auth | M | 1 | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76) |
| 4 | [#77](https://github.com/vincentchalamon/bike-trip-planner/issues/77) | Sécurisation endpoints | M | 1 | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76) |
| 5 | [#78](https://github.com/vincentchalamon/bike-trip-planner/issues/78) | Sécurisation Mercure | M | 1 | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76), #77 |

### Recette Sprint 12

- **Tests E2E :** `tests/recette/sprint-12.spec.ts`
- **Checklist manuelle :**
  - [ ] Flux magic link complet : demande → email → clic → connecté
  - [ ] Token expiré/utilisé → message d'erreur clair
  - [ ] Endpoints sécurisés (401 sans JWT)
  - [ ] Mercure : pas de fuite de données entre utilisateurs
  - [ ] Mobile : flux auth sur Capacitor

---

## Sprint 13 — Partage & Export Garmin

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#80](https://github.com/vincentchalamon/bike-trip-planner/issues/80) | Partage trip lecture seule | M | 1 | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76), #77 |
| 2 | [#42](https://github.com/vincentchalamon/bike-trip-planner/issues/42) | Bouton Partager | L | 2 | [#80](https://github.com/vincentchalamon/bike-trip-planner/issues/80) |
| 3 | [#65](https://github.com/vincentchalamon/bike-trip-planner/issues/65) | Garmin Connect | L | 3 | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76) |

### Recette Sprint 13

- **Tests E2E :** `tests/recette/sprint-13.spec.ts`
- **Checklist manuelle :**
  - [ ] Partage en lecture seule fonctionnel (lien anonyme)
  - [ ] Révocation du lien par le propriétaire
  - [ ] Bouton Partager : infographie + texte + lien
  - [ ] Garmin Connect : export course (si infra disponible)

---

## Sprint 14 — Mobile

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#74](https://github.com/vincentchalamon/bike-trip-planner/issues/74) | ADR mobile Capacitor | S | 1 | — |
| 2 | [#71](https://github.com/vincentchalamon/bike-trip-planner/issues/71) | URL API direct backend | S | 1 | — |
| 3 | [#69](https://github.com/vincentchalamon/bike-trip-planner/issues/69) | Scaffolding Capacitor | M | 1 | [#52](https://github.com/vincentchalamon/bike-trip-planner/issues/52), #53 |
| 4 | [#72](https://github.com/vincentchalamon/bike-trip-planner/issues/72) | Mode hors-ligne | L | 2 | — |
| 5 | [#73](https://github.com/vincentchalamon/bike-trip-planner/issues/73) | CI APK Android | M | 1 | — |
| 6 | [#51](https://github.com/vincentchalamon/bike-trip-planner/issues/51) | Consultation mobile | XL | 5 | [#69](https://github.com/vincentchalamon/bike-trip-planner/issues/69), #70, #71, #72, #73, #74 |

### Recette Sprint 14

- **Tests E2E :** `tests/recette/sprint-14.spec.ts`
- **Checklist manuelle :**
  - [ ] APK installable sur Android
  - [ ] Mode hors-ligne : consultation des données en cache
  - [ ] Bannière offline/online
  - [ ] Navigation tactile fluide
  - [ ] Retour en ligne : rafraîchissement automatique
  - [ ] Test sur vrai appareil Android

---

## Sprint 15 — Persistance

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#56](https://github.com/vincentchalamon/bike-trip-planner/issues/56) | Persistance BDD | XL | 5 | [#50](https://github.com/vincentchalamon/bike-trip-planner/issues/50), #45, #52, #76, #77, #80, #42, #65, #51 |

### Recette Sprint 15

- **Tests E2E :** `tests/recette/sprint-15.spec.ts`
- **Checklist manuelle :**
  - [ ] Trips persistés en PostgreSQL
  - [ ] Fermer le navigateur → rouvrir → trip retrouvé
  - [ ] Migrations Doctrine appliquées sans erreur
  - [ ] Performances acceptables (liste de trips, chargement d'un trip)

---

## Hors Sprints

| ID | Titre | Note |
|----|-------|------|
| #5 | Add unit tests | Continu, à chaque sprint |
| #67 | Générer un itinéraire (LLaMA 3B) | R&D, pas prioritaire |

---

## Récapitulatif

| Sprint | Thème | Tickets | PRs estimées |
|--------|-------|---------|---------------|
| 1 | Quick Wins Alertes | 5 | 5 |
| 2 | Alertes Frontend + UX | 3 | 3 |
| 3 | Hébergements | 3 | 4 |
| 4 | Configuration & Profil | 4 | 4 |
| 5 | Météo & Temps | 3 | 4 |
| 6 | Export | 3 | 3 |
| 7 | Carte Interactive | 4 | 9 |
| 8 | UX & Onboarding | 3 | 4 |
| 9 | Sources Routes & Infra | 3 | 5 |
| 10 | i18n & Documentation | 5 | 7 |
| 11 | Gestion Trips | 3 | 4 |
| 12 | Auth & Sécurité | 5 | 8 |
| 13 | Partage & Export Garmin | 3 | 6 |
| 14 | Mobile | 6 | 11 |
| 15 | Persistance | 1 | 5 |
| **Total** | | **57** | **~82** |
