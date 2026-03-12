# Tracking

> **Note :** Dépendance circulaire #76 <-> #56 cassée volontairement : l'auth peut être implémentée avec sa propre table users sans attendre la persistance complète.

---

## Sprint 1 — Quick Wins Alertes

Backend pur, pattern `StageAnalyzerInterface` + `#[AutoconfigureTag]`. Reviews rapides (~100-150 lignes/PR).

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#88](https://github.com/vincentchalamon/bike-trip-planner/issues/88) | Alerte calendrier : dimanches | S | 1 | — |
| 2 | [#63](https://github.com/vincentchalamon/bike-trip-planner/issues/63) | Détection des pentes raides | S | 1 | — |
| 3 | [#66](https://github.com/vincentchalamon/bike-trip-planner/issues/66) | Détecter les points de charge VAE | S | 1 | — |
| 4 | [#58](https://github.com/vincentchalamon/bike-trip-planner/issues/58) | Détection des points d'eau | M | 1 | — |
| 5 | [#54](https://github.com/vincentchalamon/bike-trip-planner/issues/54) | Correction des dénivelés sous-estimés | M | 1 | — |

### Recette Sprint 1

- **Tests E2E :** `tests/recette/sprint-01.spec.ts`
- **Checklist manuelle :**
  - [x] Alerte dimanche visible sur une étape tombant un dimanche
  - [ ] Alerte pente raide visible sur une étape avec forte pente
  - [x] Alerte point de charge VAE visible (si VAE activé)
  - [ ] Points d'eau détectés et affichés par étape
  - [ ] Dénivelés corrigés cohérents avec la trace GPX

---

## Sprint 2 — Alertes Frontend + UX Feedback

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#28](https://github.com/vincentchalamon/bike-trip-planner/issues/28) | Résumer les suggestions et détections | S | 1 | — |
| 2 | [#41](https://github.com/vincentchalamon/bike-trip-planner/issues/41) | Badge de difficulté avec jauge visuelle | S | 1 | — |
| 3 | [#40](https://github.com/vincentchalamon/bike-trip-planner/issues/40) | Barre de progression segmentée | M | 1 | — |

### Recette Sprint 2

- **Tests E2E :** `tests/recette/sprint-02.spec.ts`
- **Checklist manuelle :**
  - [ ] Résumé des alertes visible dans le panneau trip
  - [ ] Badge de difficulté avec jauge colorée par étape
  - [ ] Barre de progression reflétant l'avancement du trip

---

## Sprint 3 — Hébergements

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#38](https://github.com/vincentchalamon/bike-trip-planner/issues/38) | Distance hébergement-endPoint | S | 1 | — |
| 2 | [#37](https://github.com/vincentchalamon/bike-trip-planner/issues/37) | Rayon de recherche | M | 1 | — |
| 3 | [#39](https://github.com/vincentchalamon/bike-trip-planner/issues/39) | Sélectionner un hébergement | L | 2 | — |

### Recette Sprint 3

- **Tests E2E :** `tests/recette/sprint-03.spec.ts`
- **Checklist manuelle :**
  - [ ] Distance hébergement-endPoint affichée
  - [ ] Modification du rayon de recherche + résultats mis à jour
  - [ ] Sélection d'un hébergement → recalcul itinéraire (endPoint + startPoint étape suivante)
  - [ ] Responsive : vérifier sur mobile

---

## Sprint 4 — Configuration & Profil

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#48](https://github.com/vincentchalamon/bike-trip-planner/issues/48) | Profil cyclo + presets | M | 1 | — |
| 2 | [#49](https://github.com/vincentchalamon/bike-trip-planner/issues/49) | Panneau configuration (sidebar) | M | 1 | [#48](https://github.com/vincentchalamon/bike-trip-planner/issues/48) |
| 3 | [#36](https://github.com/vincentchalamon/bike-trip-planner/issues/36) | Filtre types d'hébergements | M | 1 | [#49](https://github.com/vincentchalamon/bike-trip-planner/issues/49) |
| 4 | [#55](https://github.com/vincentchalamon/bike-trip-planner/issues/55) | Insertion jours de repos | M | 1 | — |

### Recette Sprint 4

- **Tests E2E :** `tests/recette/sprint-04.spec.ts`
- **Checklist manuelle :**
  - [ ] Presets cyclo sélectionnables (sportif, touring, etc.)
  - [ ] Panneau de configuration accessible et fonctionnel
  - [ ] Filtrage par type d'hébergement dans le panneau de configuration
  - [ ] Insertion d'un jour de repos → recalcul des étapes suivantes
  - [ ] Responsive : sidebar sur mobile

---

## Sprint 5 — Météo & Temps

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#43](https://github.com/vincentchalamon/bike-trip-planner/issues/43) | Météo étendue vent + confort | L | 2 | — |
| 2 | [#61](https://github.com/vincentchalamon/bike-trip-planner/issues/61) | Estimation temps de parcours | M | 1 | [#48](https://github.com/vincentchalamon/bike-trip-planner/issues/48) |
| 3 | [#62](https://github.com/vincentchalamon/bike-trip-planner/issues/62) | Horaires soleil + alerte nocturne | M | 1 | [#61](https://github.com/vincentchalamon/bike-trip-planner/issues/61) |

### Recette Sprint 5

- **Tests E2E :** `tests/recette/sprint-05.spec.ts`
- **Checklist manuelle :**
  - [ ] Vent relatif (face/dos) affiché par étape
  - [ ] Indice de confort cycliste visible
  - [ ] Estimation du temps de parcours cohérente avec le profil cyclo
  - [ ] Horaires lever/coucher de soleil affichés
  - [ ] Alerte arrivée nocturne si applicable

---

## Sprint 6 — Export (pré-auth)

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#47](https://github.com/vincentchalamon/bike-trip-planner/issues/47) | Exporter au format texte | S | 1 | — |
| 2 | [#64](https://github.com/vincentchalamon/bike-trip-planner/issues/64) | Téléchargement GPX global | S | 1 | — |
| 3 | [#59](https://github.com/vincentchalamon/bike-trip-planner/issues/59) | Budget récapitulatif | S | 1 | — |

### Recette Sprint 6

- **Tests E2E :** `tests/recette/sprint-06.spec.ts`
- **Checklist manuelle :**
  - [ ] Export texte complet et formaté
  - [ ] Téléchargement GPX global fonctionnel
  - [ ] Budget récapitulatif avec totaux cohérents

---

## Sprint 7 — Carte Interactive

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#30](https://github.com/vincentchalamon/bike-trip-planner/issues/30) | Carte interactive + profil altimétrique | XL | 5 | — |
| 2 | [#31](https://github.com/vincentchalamon/bike-trip-planner/issues/31) | Split view carte / timeline | M | 1 | [#30](https://github.com/vincentchalamon/bike-trip-planner/issues/30) |
| 3 | [#34](https://github.com/vincentchalamon/bike-trip-planner/issues/34) | Timeline ravitaillement | L | 2 | [#58](https://github.com/vincentchalamon/bike-trip-planner/issues/58) |
| 4 | [#35](https://github.com/vincentchalamon/bike-trip-planner/issues/35) | Points d'intérêt culturels | M | 1 | — |

### Recette Sprint 7

- **Tests E2E :** `tests/recette/sprint-07.spec.ts`
- **Checklist manuelle :**
  - [ ] Carte avec tracé coloré par étape
  - [ ] Profil altimétrique interactif (survol → curseur sur carte)
  - [ ] Synchronisation carte ↔ timeline
  - [ ] Split view fonctionnel
  - [ ] Timeline ravitaillement avec POI le long du tracé
  - [ ] Mode sombre : tuiles sombres
  - [ ] Responsive : carte sur mobile (tactile)

---

## Sprint 8 — UX & Onboarding

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#32](https://github.com/vincentchalamon/bike-trip-planner/issues/32) | Onboarding guide | S | 1 | — |
| 2 | [#57](https://github.com/vincentchalamon/bike-trip-planner/issues/57) | Undo/Redo | L | 2 | — |
| 3 | [#33](https://github.com/vincentchalamon/bike-trip-planner/issues/33) | Raccourcis clavier + aide | M | 1 | [#57](https://github.com/vincentchalamon/bike-trip-planner/issues/57) |

### Recette Sprint 8

- **Tests E2E :** `tests/recette/sprint-08.spec.ts`
- **Checklist manuelle :**
  - [ ] Onboarding guide affiché au premier lancement
  - [ ] Raccourcis clavier fonctionnels (Ctrl+Z, Ctrl+Y, etc.)
  - [ ] Bouton aide affichant la liste des raccourcis
  - [ ] Undo/Redo sur les actions clés (suppression étape, modification distance)

---

## Sprint 9 — Sources de Routes & Infra Backend

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#60](https://github.com/vincentchalamon/bike-trip-planner/issues/60) | Sources routes supplémentaires | L | 3 | — |
| 2 | [#53](https://github.com/vincentchalamon/bike-trip-planner/issues/53) | Création trip via URL | S | 1 | — |
| 3 | [#46](https://github.com/vincentchalamon/bike-trip-planner/issues/46) | Invalidation messages Messenger | M | 1 | — |

### Recette Sprint 9

- **Tests E2E :** `tests/recette/sprint-09.spec.ts`
- **Checklist manuelle :**
  - [ ] Upload GPX direct fonctionnel (drag & drop)
  - [ ] Import depuis Strava/RideWithGPS (si implémenté)
  - [ ] Création de trip via URL avec paramètre link
  - [ ] Invalidation Messenger : pas de messages orphelins

---

## Sprint 10 — i18n & Documentation

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#44](https://github.com/vincentchalamon/bike-trip-planner/issues/44) | Support multi-langue (fr/en) | L | 3 | — |
| 2 | [#70](https://github.com/vincentchalamon/bike-trip-planner/issues/70) | i18n client-side export statique | S | 1 | [#44](https://github.com/vincentchalamon/bike-trip-planner/issues/44) |
| 3 | [#26](https://github.com/vincentchalamon/bike-trip-planner/issues/26) | Traduire documentation en français | S | 1 | — |
| 4 | [#27](https://github.com/vincentchalamon/bike-trip-planner/issues/27) | Améliorer présentation documentation | S | 1 | — |
| 5 | [#29](https://github.com/vincentchalamon/bike-trip-planner/issues/29) | Changer la licence | S | 1 | — |

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
| 6 | [#80](https://github.com/vincentchalamon/bike-trip-planner/issues/80) | Partage trip lecture seule | M | 1 | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76), #77 |
| 7 | [#42](https://github.com/vincentchalamon/bike-trip-planner/issues/42) | Bouton Partager | L | 2 | [#80](https://github.com/vincentchalamon/bike-trip-planner/issues/80) |
| 8 | [#65](https://github.com/vincentchalamon/bike-trip-planner/issues/65) | Garmin Connect | L | 3 | [#76](https://github.com/vincentchalamon/bike-trip-planner/issues/76) |

### Recette Sprint 12

- **Tests E2E :** `tests/recette/sprint-12.spec.ts`
- **Checklist manuelle :**
  - [ ] Flux magic link complet : demande → email → clic → connecté
  - [ ] Token expiré/utilisé → message d'erreur clair
  - [ ] Endpoints sécurisés (401 sans JWT)
  - [ ] Mercure : pas de fuite de données entre utilisateurs
  - [ ] Partage en lecture seule fonctionnel (lien anonyme)
  - [ ] Bouton Partager : infographie + texte + lien
  - [ ] Garmin Connect : export course (si infra disponible)
  - [ ] Mobile : flux auth sur Capacitor

---

## Sprint 13 — Mobile

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#74](https://github.com/vincentchalamon/bike-trip-planner/issues/74) | ADR mobile Capacitor | S | 1 | — |
| 2 | [#71](https://github.com/vincentchalamon/bike-trip-planner/issues/71) | URL API direct backend | S | 1 | — |
| 3 | [#69](https://github.com/vincentchalamon/bike-trip-planner/issues/69) | Scaffolding Capacitor | M | 1 | [#52](https://github.com/vincentchalamon/bike-trip-planner/issues/52), #53 |
| 4 | [#72](https://github.com/vincentchalamon/bike-trip-planner/issues/72) | Mode hors-ligne | L | 2 | — |
| 5 | [#73](https://github.com/vincentchalamon/bike-trip-planner/issues/73) | CI APK Android | M | 1 | — |
| 6 | [#51](https://github.com/vincentchalamon/bike-trip-planner/issues/51) | Consultation mobile | XL | 5 | [#69](https://github.com/vincentchalamon/bike-trip-planner/issues/69), #70, #71, #72, #73, #74 |

### Recette Sprint 13

- **Tests E2E :** `tests/recette/sprint-13.spec.ts`
- **Checklist manuelle :**
  - [ ] APK installable sur Android
  - [ ] Mode hors-ligne : consultation des données en cache
  - [ ] Bannière offline/online
  - [ ] Navigation tactile fluide
  - [ ] Retour en ligne : rafraîchissement automatique
  - [ ] Test sur vrai appareil Android

---

## Sprint 14 — Persistance

| Ordre | ID | Titre | Effort | PRs | Dépend de |
|-------|----|-------|--------|-----|-----------|
| 1 | [#56](https://github.com/vincentchalamon/bike-trip-planner/issues/56) | Persistance BDD | XL | 5 | [#50](https://github.com/vincentchalamon/bike-trip-planner/issues/50), #45, #52, #76, #77, #80, #42, #65, #51 |

### Recette Sprint 14

- **Tests E2E :** `tests/recette/sprint-14.spec.ts`
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
|--------|-------|---------|--------------|
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
| 12 | Auth & Sécurité | 8 | 14 |
| 13 | Mobile | 6 | 11 |
| 14 | Persistance | 1 | 5 |
| **Total** | | **54** | **~82** |
