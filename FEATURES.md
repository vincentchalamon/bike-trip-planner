# Features

Inventaire complet des fonctionnalités de **Bike Trip Planner** — livrées et planifiées. Pour la roadmap détaillée par sprint (issues, PRs, recettes), voir [`TRACKING.md`](TRACKING.md).

## Légende des statuts

| Symbole | Signification |
|---------|---------------|
| ✅      | Livré et intégré |
| 🚧      | En cours (sprint actif) |
| 📅      | Planifié (sprint à venir) |

---

## 1. Planification d'un voyage

### Import d'itinéraire

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Upload GPX direct | Drag & drop ou sélection fichier, jusqu'à 30 MB. Parsing XMLReader streaming. Sprint 9 / Sprint 17 |
| ✅ | Import URL Komoot (tour) | `komoot.com/[xx-xx/]tour/{id}` — extraction auto du tracé |
| ✅ | Import URL Komoot (collection) | `komoot.com/[xx-xx/]collection/{id}` — multi-segments |
| ✅ | Import URL Strava | `strava.com/routes/{id}` |
| ✅ | Import URL RideWithGPS | `ridewithgps.com/routes/{id}` |
| ✅ | Création via query param `?link=...` | Démarrage direct depuis un lien partagé. Sprint 9 |
| ✅ | Card Selection mutuellement exclusive | 3 cartes : Lien / GPX / Assistant IA (IA grisée « bientôt »). Sprint 22 |
| 📅 | Génération d'itinéraire par IA | L'IA génère un tracé à partir d'une description texte (modèle du fournisseur). Sprint 28 / Hors sprint #67 |

### Wizard 4 étapes

| Statut | Étape | Détail |
|---|---|---|
| ✅ | **Acte 1 — Préparation** | Card Selection Lien/GPX/IA. Sprint 22 |
| ✅ | **Acte 1.5 — Aperçu** | Écran de prévisualisation avant analyse (stats, map, stages, météo), 3 CTAs. Sprint 22 |
| ✅ | **Acte 2 — Analyse** | Écran SSE avec progression (Messenger + 5 workers async) |
| ✅ | **Acte 2 — Progression narrative par catégorie** | Étapes nommées selon `ComputationName` (ROUTE, STAGES, OSM_SCAN, POIS, WEATHER…). Sprint 23 |
| ✅ | **Acte 3 — Mon voyage** | Roadbook complet avec carte, timeline, détail par étape |
| ✅ | **Acte 3 — Alertes repliables** | Regroupement par sévérité avec compteurs. Sprint 23 |
| ✅ | Composant Stepper | Navigation visuelle 4 étapes desktop + mobile. Sprint 21 |

### Configuration cyclo

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | 3 presets | `beginner` (50 km/j, 10 km/h), `intermediate` (80 km/j, 15 km/h), `expert` (120 km/j, 20 km/h). Sprint 4 |
| ✅ | Slider distance max / jour | Plancher à 30 km (minimum threshold) |
| ✅ | Slider vitesse moyenne | 5 à 35 km/h |
| ✅ | Slider facteur fatigue | Formule : `target_day_n = base * (0.9^(n-1)) - (elevation/50)` |
| ✅ | Slider pénalité dénivelé | 0 à 100 % |
| ✅ | Heure de départ | Influence l'alerte coucher de soleil |
| ✅ | Mode e-bike | Active l'alerte autonomie batterie (portée effective 80 km - dénivelé/25) |
| ✅ | Filtre types d'hébergements | 9 types cochables. Sprint 4 / Sprint 20 |
| ✅ | Insertion jours de repos | Décale les dates des étapes suivantes. Sprint 4 |
| ✅ | Dates de voyage | Picker start/end, influence météo et événements |
| ✅ | Panneau de configuration latéral | Drawer accessible depuis le roadbook. Sprint 4 |
| ✅ | Affinage par IA (prompt texte) | Text area pour demander ajustements (modèle de chat du fournisseur). Sprint 27 |

---

## 2. Analyse & enrichissement

### Moteur d'alertes

Pipeline d'analyseurs (auto-découverts via `#[AutoconfigureTag('app.stage_analyzer')]` ou handlers Messenger dédiés). 3 sévérités : `critical`, `warning`, `nudge`.

| Statut | Règle | Sévérité | Déclencheur |
|---|---|---|---|
| ✅ | **Continuity** | critical | Écart > 500 m entre étapes consécutives |
| ✅ | **Continuity** | warning | Écart 100-500 m entre étapes |
| ✅ | **Elevation** | warning | Dénivelé > 1 200 m sur une étape. Sprint 1 #54 |
| ✅ | **Steep gradient** | warning | Pente soutenue ≥ 8 % sur ≥ 500 m. Sprint 1 #63 |
| ✅ | **Surface** | warning | Section non pavée ≥ 500 m |
| ✅ | **Surface** | warning | Données OSM surface manquantes sur ≥ 30 % |
| ✅ | **Traffic** | critical | Route primaire/trunk sans infra cyclable ≥ 500 m |
| ✅ | **Traffic** | warning | Route secondaire, pas de piste cyclable, vitesse > 50 km/h |
| ✅ | **Traffic** | nudge | Route secondaire, vitesse ≤ 50 km/h |
| ✅ | **E-bike range** | warning | Distance jour > portée effective. Sprint 1 #66 |
| ✅ | **Sunset** | warning | Heure d'arrivée dépasse le crépuscule civil. Sprint 5 #62 |
| ✅ | **Calendar** | nudge | Étape un jour férié français. Sprint 1 #88 |
| ✅ | **Calendar** | nudge | Étape un dimanche (commerces fermés). Sprint 1 #88 |
| ✅ | **Wind** | warning | Vent de face ≥ 25 km/h sur ≥ 60 % des étapes. Sprint 5 #43 |
| ✅ | **Comfort** | warning | Indice de confort < 40/100. Sprint 5 #43 |
| ✅ | **Bike shops** | nudge | Aucun atelier vélo dans 2 km du milieu d'étape (trips > 5 étapes) |
| ✅ | **Bike shops** | nudge | Magasin proche vend des vélos mais pas de réparation |
| ✅ | **Resupply** | nudge | Étape ≥ 40 km sans POI ravitaillement |
| ✅ | **Resupply** | warning | Tous les POI ravitaillement fermés à l'heure estimée |
| ✅ | **Accommodation** | warning | Tous les hébergements détectés probablement fermés (saisonnalité) |
| ✅ | **Water points** | nudge | Tronçon > 30 km sans point d'eau détecté. Sprint 1 #58 |
| ✅ | **Rest day** | nudge | Tous les N jours consécutifs sans repos (défaut : 3) |
| ✅ | **Cultural POI** | nudge | Musée/monument/château/église/belvédère dans 500 m du tracé. Sprint 7 #35 / Sprint 20 |
| ✅ | **Railway station** | nudge | Aucune gare dans 10 km d'un point d'étape (évacuation). Sprint 18 #283 |
| ✅ | **Health services** | nudge | Aucune pharmacie/hôpital dans 15 km. Sprint 18 #284 |
| ✅ | **Border crossing** | nudge | Traversée frontière internationale détectée. Sprint 18 #285 |
| 📅 | **Départ avant l'aube** | warning | Heure de départ avant le lever du soleil. Sprint 18 #313 |
| 📅 | **Traversée cours d'eau sans pont** | nudge | Gué ou passage sans pont détecté. Sprint 18 #314 |

### Alertes actionnables

| Statut | Type d'action | Label FR | Usage |
|---|---|---|---|
| ✅ | `AUTO_FIX` | « Corriger » | Correction automatique (ex: élargir rayon hébergement). Sprint 18 |
| ✅ | `DETOUR` | « Calculer un itinéraire alternatif » | Proposer un bypass |
| ✅ | `NAVIGATE` | « Naviguer » | Ouvrir point cible dans la carte |
| ✅ | `DISMISS` | « Écarter l'alerte » | Ignorer l'alerte pour ce voyage |

### Météo & temps

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Météo multi-jours | Open-Meteo, 14 jours max, cache Redis 3h |
| ✅ | Température min/max | Par étape |
| ✅ | Vent force + direction | Avec direction relative au parcours (headwind/tailwind/crosswind). Sprint 5 #43 |
| ✅ | Humidité et pluie | % humidité + probabilité précipitation |
| ✅ | Indice de confort cycliste | 0-100 composite (température, vent, humidité). Sprint 5 #43 |
| ✅ | Horaires soleil | Lever et coucher, influence alerte nocturne. Sprint 5 #62 |
| ✅ | Estimation temps de parcours | Durée calculée par étape selon profil cyclo. Sprint 5 #61 |

### Terrain

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Smoothing élévation | Seuil 3 m pour filtrer le bruit GPS |
| ✅ | Décimation Douglas-Peucker | Tolérance 20 m, ~25k → ~1.5k points |
| ✅ | Correction dénivelés sous-estimés | Re-calcul à partir du DEM SRTM. Sprint 1 #54 |
| ✅ | Détection surfaces | Bitume / gravier / pavés / terre via OSM tags |
| ✅ | Profil altimétrique | Graphique interactif avec curseur synchronisé à la carte. Sprint 7 #30 |

### Sources de données

| Statut | Source | Rôle | Licence |
|---|---|---|---|
| ✅ | **OpenStreetMap** (Overpass) | Routes, infra cyclable, eau, ateliers, ravitaillement, POI, hébergements de base | ODbL |
| ✅ | **DataTourisme** | Enrichissement hébergements + POI culturels + événements datés (France) | Licence Ouverte 2.0 |
| ✅ | **Wikidata** (SPARQL) | Descriptions multilingues, images, liens Wikipedia (Q-IDs) | CC0 |
| ✅ | **data.gouv.fr** | Marchés forains hebdomadaires (import manuel) | Licence Ouverte 2.0 |
| ✅ | **Open-Meteo** | Prévisions météo | CC-BY |

Caches : OSM 24h, Wikidata 7j, DataTourisme (par ressource), Open-Meteo 3h.

---

## 3. Roadbook interactif

### Timeline

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Timeline verticale des étapes | Cartes déroulées avec détail complet |
| ✅ | Marqueurs de jour | Numéro + date formatée |
| ✅ | Barre de progression segmentée | Progression globale. Sprint 2 #40 |
| ✅ | Cards de jour de repos | Insertion manuelle ou auto-suggérée |
| ✅ | Bouton « + Ajouter une étape » | Split d'une étape existante |
| ✅ | Bouton « + Jour de repos » | Insertion entre deux étapes |

### Détail d'étape (StageCard)

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Points de départ / arrivée | Nom + coordonnées |
| ✅ | Métadonnées | Distance, D+, D-, durée estimée |
| ✅ | Badge de difficulté avec jauge | Visuelle + score composite. Sprint 2 #41 |
| ✅ | Éditeur de distance manuel | Force une distance différente du GPX |
| ✅ | Description route | Extrait pertinent |
| ✅ | Météo étendue | Cf. §Météo |
| ✅ | Hébergements sélectionnables | Cf. §Hébergements |
| ✅ | Alertes groupées | Cf. §Alertes |
| ✅ | Supply Timeline (eau + ravitaillement) | Frise horizontale avec clusters. Sprint 7 #34 |
| ✅ | Events panel | Fêtes, jours fériés, festivals datés |
| ✅ | Téléchargements par étape | GPX, GeoJSON, texte |
| ✅ | Export FIT par étape | Format Garmin natif (`FitEncoder`/`FitNormalizer`). Sprint 31 |
| ✅ | Résumé IA par étape | Narratif + insights + suggestions, passe 1 d'analyse (`StageAiSummary`). Sprint 27 #306 |
| 📅 | Shimmer/skeleton recalcul | État visuel pendant recomputation. Sprint 24 |
| 📅 | Diff post-recalcul | Surbrillance des changements. Sprint 24 |

### Carte & élévation

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Carte MapLibre GL interactive | Tuiles OSM, zoom, pan, markers. Sprint 7 #30 |
| ✅ | Tracé coloré par jour | Polylines distinctes par étape |
| ✅ | Markers numérotés fin d'étape | Pastilles colorées |
| ✅ | Markers d'hébergements | 9 types distincts |
| ✅ | Markers POI | Eau, ravitaillement, ateliers, culture, gares, pharmacies |
| ✅ | Split view timeline + carte | Synchronisation hover. Sprint 7 #31 |
| ✅ | ViewModeToggle 3 modes | Timeline / Split / Carte (split masqué < 1024px) |
| ✅ | Profil altimétrique interactif | Curseur sync avec carte. Sprint 7 #30 |
| ✅ | Mode sombre | Tuiles assombries cohérentes |
| ✅ | Attribution OSM | Obligation légale ODbL |

---

## 4. Hébergements

| Statut | Type | Requête OSM | Tarif heuristique |
|---|---|---|---|
| ✅ | `hotel` | `tourism=hotel` | 50 – 120 € |
| ✅ | `motel` | `tourism=motel` | 45 – 90 € |
| ✅ | `guest_house` | `tourism=guest_house` | 40 – 80 € |
| ✅ | `chalet` | `tourism=chalet` | 30 – 70 € |
| ✅ | `hostel` | `tourism=hostel` | 20 – 35 € |
| ✅ | `alpine_hut` | `tourism=alpine_hut` | 25 – 45 € |
| ✅ | `camp_site` | `tourism=camp_site` | 8 – 25 € (8-15 si `backpack=yes`) |
| ✅ | `wilderness_hut` | `tourism=wilderness_hut` | Gratuit / donation. Sprint 20 #345 |
| ✅ | `shelter` | `amenity=shelter` + filtres | Gratuit. Sprint 20 #345 |

### Fonctionnalités

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Distance hébergement → endPoint | Affichage kilométrique. Sprint 3 #38 |
| ✅ | Rayon de recherche configurable | Modifiable par étape. Sprint 3 #37 |
| ✅ | Sélection d'un hébergement | Recalcul itinéraire : endPoint + startPoint étape suivante. Sprint 3 #39 |
| ✅ | Ajout manuel d'hébergement | Formulaire nom + coords + type |
| ✅ | Hébergements enrichis DataTourisme | Description, horaires, prix réels (France). Sprint 20 #347 |
| ✅ | Enrichissement Wikidata | Photo, Wikipedia link si Q-ID. Sprint 20 #350 |
| ✅ | Filtre par type | Panneau config. Sprint 4 #36 |

---

## 5. Événements & POI culturels

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Jours fériés français | Détection auto |
| ✅ | Dimanches | Alerte commerces fermés |
| ✅ | Marchés forains hebdomadaires | Import data.gouv.fr via `make markets-import`. Sprint 20 #351 |
| ✅ | Festivals datés | DataTourisme. Sprint 20 #349 |
| ✅ | POI culturels basiques | OSM (château, musée, belvédère). Sprint 7 #35 |
| ✅ | POI culturels enrichis | Description + horaires + prix + Wikipedia. Sprint 20 #348 |

---

## 6. Partage & export

### Export

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Export texte résumé | Roadbook formaté. Sprint 6 #47 |
| ✅ | Téléchargement GPX global | Trace complète. Sprint 6 #64 |
| ✅ | Téléchargement GPX par étape | Par jour |
| ✅ | Téléchargement GeoJSON | Par étape |
| ✅ | Budget récapitulatif | Fourchette min/max (hébergement + repas). Sprint 6 #59 |
| ✅ | Infographie PNG | Export image pour partage social. Sprint 14 #42 |
| ✅ | Export FIT natif | Par étape, points de parcours Garmin. Sprint 31 |

### Partage

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Lien de partage lecture seule | `/s/{code}` accessible sans compte. Sprint 14 #80 |
| ✅ | Révocation du lien | Reset du code côté owner |
| ✅ | Modale Partager | Lien + infographie + texte. Sprint 14 #42 |
| 📅 | Push Garmin Connect | OAuth 2.0 PKCE + envoi du cours. Sprint 31 |

---

## 7. Authentification & gestion de compte

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Magic link (passwordless) | JWT + cookie refresh. Sprint 13 #75 #76 |
| ✅ | Silent refresh | Rafraîchissement automatique du JWT |
| ✅ | Sécurisation endpoints | Tous les endpoints privés requièrent JWT. Sprint 13 #77 |
| ✅ | Sécurisation Mercure | Isolation SSE par utilisateur. Sprint 13 #78 |
| ✅ | Système d'accès anticipé | Form email + token HMAC + throttling. Sprint 19 #287 #288 |
| ✅ | Vérification accès (`/access-requests/verify`) | Page de confirmation post-email |
| ✅ | Suppression / anonymisation (RGPD) | `DELETE /users/me` : anonymise l'email + purge des voyages (cascade) + révoque les refresh tokens. Sprint 34 #549 |
| ✅ | Export des données (RGPD) | `GET /users/me/export` : archive JSON (profil + voyages + préférences). Sprint 34 #549 |
| ✅ | Page `/account/settings` | Compte, préférences, export RGPD, zone de danger. Sprint 34 #383 |

---

## 8. Liste des voyages & gestion

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Liste paginée | 10 items / page, tri par date. Sprint 12 #50 |
| ✅ | Recherche par titre | Debounced 350ms |
| ✅ | Filtre par dates | Start / end |
| ✅ | Suppression de voyage | Avec modale de confirmation |
| ✅ | Duplication de voyage | Copie complète avec nouveau titre. Sprint 12 #45 |
| ✅ | Verrouillage automatique | Trips passés en lecture seule. Sprint 12 #52 |
| ✅ | Badge de statut | Brouillon / En cours / Terminé / Archivé |
| ✅ | Liste avec statuts + header « Mes voyages » | Sprint 21 #318 |
| ✅ | Persistance PostgreSQL | Doctrine ORM 3 + migrations. Sprint 11 #56 |

---

## 9. UX transverse

### Éditions & contrôles

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Undo / Redo | Temporal store Zustand. Sprint 8 #57 |
| ✅ | Raccourcis clavier | Ctrl+Z, Ctrl+Y, etc. Sprint 8 #33 |
| ✅ | Modale d'aide raccourcis | Bouton « ? » dans la top bar |
| ✅ | Bandeau voyage verrouillé | Affiché sur trips en lecture seule |
| ✅ | Batch mode (ModificationQueue) | Accumulation modifs + recalcul unique. Sprint 24 #327 |
| ✅ | Refonte top bar + aide unifiée | Top bar desktop redessinée, modale d'aide unifiée (raccourcis + FAQ). Sprint 34 #384 |

### Onboarding & aide

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Tour guidé driver.js | Premier usage, étapes mises en surbrillance. Sprint 8 #32 |
| ✅ | Page FAQ | Documentation publique. Sprint 19 #289 |
| ✅ | Landing page marketing | 8 sections (hero, bento, témoignages…). Sprint 19 #286 |

### Thème & i18n

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Thème clair / sombre / auto | Toggle + détection système |
| ✅ | Internationalisation FR / EN | next-intl. Sprint 10 #44 |
| ✅ | Export statique i18n | Compatibilité Next.js export. Sprint 10 #70 |
| ✅ | Documentation en français | README.fr.md + docs. Sprint 10 #26 #27 |
| ✅ | Licence AGPL-3.0 | Migration depuis MIT. Sprint 10 #29 |

### États & feedback

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Hydration boundary | Évite flash pendant restauration |
| ✅ | Error boundary | Catch erreurs composant trip-planner |
| ✅ | Toasts (sonner) | Feedback actions utilisateur |
| ✅ | Pages 404 / 500 / global-error | Gestion des erreurs Next.js |

---

## 10. Mobile & hors-ligne

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Responsive design | Breakpoints mobile / tablet / desktop |
| ✅ | Scaffolding Capacitor | Bundle natif Android. Sprint 15 #69 |
| ✅ | URL API direct backend | Mode mobile bypass proxy. Sprint 15 #71 |
| ✅ | Mode hors-ligne | Consultation des voyages sauvegardés. Sprint 15 #72 |
| ✅ | Bandeau offline / online | Feedback état réseau |
| ✅ | CI APK Android | Build automatique. Sprint 15 #73 |
| 🚧 | Consultation mobile complète | Parcours complet sur APK. Sprint 15 #51 |

---

## 11. Intelligence artificielle

> IA **optionnelle, multi-fournisseur, à clé personnelle** (`symfony/ai-platform`, **ADR-042**). L'IA est activée par utilisateur en configurant un fournisseur (Anthropic/Claude, Google/Gemini, OpenAI) et son propre token dans les réglages du compte ; il n'y a pas de toggle d'environnement. Token chiffré au repos. Dégradation gracieuse : sans clé (ou clé invalide / quota / fournisseur indisponible), les résumés IA sont masqués et les alertes restent affichées. L'ancienne pile Ollama auto-hébergée (ADR-028 / ADR-030) est retirée.

### Fondations backend

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | IA optionnelle multi-fournisseur (BYO token) | `PlatformLlmClient` + `LlmClientFactory` par-utilisateur (Anthropic/Gemini/OpenAI). ADR-042 |
| ✅ | Token IA chiffré + API compte | `AiTokenEncryptor` (libsodium) + `/users/me/ai-settings`. ADR-042 |
| ✅ | Taxonomie d'erreurs + mode dégradé | `AiFailureReason` + `AiErrorClassifier` (token invalide / quota / rate-limit / indisponible). ADR-042 |
| ✅ | Gate mechanism | Blocage/déblocage dans ComputationTracker (`LlmAnalysisTracker`). Sprint 25 #299 |
| ✅ | System prompts cyclotourisme versionnés | Template FR/EN. Sprint 25 #300 |
| ✅ | Adoption `symfony/ai` | `symfony/ai-platform` + bridges cloud (ADR-030, migré en ADR-042). |
| 🗑️ | Service OllamaClient + Docker Ollama | Retiré (ADR-042) ; remplacé par les bridges cloud par-utilisateur. Anciennement Sprint 25 #298 / ADR-028 |

### Analyse 2 passes (IA — modèle d'analyse du fournisseur)

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Passe 1 — analyse par étape | Message Messenger parallélisable (`AnalyzeStageWithLlmHandler`). Sprint 26 #301 |
| ✅ | Passe 2 — vue d'ensemble du trip | Résumé global (`AnalyzeTripOverviewWithLlmHandler`). Sprint 26 #302 |
| ✅ | Pipeline gate → IA → TRIP_READY | Orchestration Mercure. Sprint 26 #303 |
| ✅ | Fallback gracieux sans IA | Dégradation propre (`AiUnavailableException` + raison). Sprint 26 #304 / ADR-042 |

### Frontend IA

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Résumé IA global dans Mon voyage | Passe 2 d'analyse (`TripAiOverview`). Sprint 27 #305 |
| ✅ | Résumé IA par étape + layout hybride | Résumé + alertes repliables (`StageAiSummary`). Sprint 27 #306 |
| ✅ | Bandeau « Actualiser l'analyse IA » | Si différé ou désactivé. Sprint 27 #307 |
| ✅ | Fallback frontend sans IA | Alertes dépliées, résumé masqué. Sprint 27 #308 |

### Bulle IA conversationnelle (modèle de chat du fournisseur)

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Endpoint chat IA | Context-aware (modèle de chat du fournisseur). Sprint 28 #309 |
| ✅ | Composant AiBubble | Bulle flottante + panneau chat. Sprint 28 #310 |
| ✅ | Intégration ↔ recomputation inline | `skipAiAnalysis` flag. Sprint 28 #311 |
| ✅ | Chat in-ride (POI à proximité) | Détection d'intention + calcul de détour avec géolocalisation (`InRide/*`), historique persisté (`TripChatMessage`). Sprint 32 |
| 📅 | Génération d'itinéraire par IA | À partir d'un prompt texte. Hors sprint #67 |

---

## 12. Analytics & confidentialité (RGPD)

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Politique de confidentialité `/privacy` | Responsable, base légale, finalités, conservation, droits, sous-traitants, contact (sommaire ancré). Sprint 34 #552 |
| ✅ | Mentions légales `/legal` | Éditeur, hébergeur, contact, propriété intellectuelle. Sprint 34 |
| ✅ | Suppression / anonymisation compte | `DELETE /users/me` (cf. §7). Sprint 34 #549 |
| ✅ | Export des données | `GET /users/me/export` (cf. §7). Sprint 34 #549 |
| ✅ | Analytics Plausible auto-hébergé | Script chargé selon la seule configuration d'environnement — sans cookie, sans bannière de consentement (intérêt légitime). Events custom typés (`trackEvent`). ADR-034. Sprint 34 #557 #572 |
| ✅ | Events d'usage | `import_komoot/strava/rwgps/gpx`, `trip_created`, `trip_shared`, `accommodation_selected`, `alert_action_clicked`, `ai_chat_opened`. Sprint 34 #561 |
| 📅 | Service Plausible CE (Docker) | PostgreSQL + ClickHouse auto-hébergés, sous-domaine dédié. ADR-034 |

> Pas de Google Analytics ni Posthog. Analytics produit : **Plausible Community Edition** auto-hébergé, sans cookie ni PII (IP et User-Agent anonymisés) — voir [ADR-034](docs/adr/adr-034-usage-analytics-plausible.md). Suivi des erreurs : SDK **Sentry** (backend + PWA), compatible GlitchTip — en beta (Sprint 34.5) pointé sur **Sentry SaaS free**, GlitchTip auto-hébergé conservé mais non déployé (réversible) — voir [ADR-031](docs/adr/adr-031-error-tracking-strategy.md). L'implémentation native `UsageEvent` initialement envisagée a été abandonnée au profit de Plausible.

---

## 13. Intégrations externes

| Statut | Intégration | Détail |
|---|---|---|
| ✅ | OpenStreetMap Overpass | Cache Redis 24h |
| ✅ | DataTourisme API | Rate limiter fixed_window, cache par ressource. Sprint 20 #346 |
| ✅ | Wikidata SPARQL batch | Cache 7 jours. Sprint 20 #350 |
| ✅ | Open-Meteo | Pas de clé API. Sprint 5 |
| ✅ | data.gouv.fr | Import ponctuel manuel. Sprint 20 #351 |
| 📅 | Garmin Developer Program | Inscription préalable ~2j approbation |
| 📅 | Garmin Connect OAuth 2.0 PKCE | Flux authentification + callback. Sprint 31 |
| 📅 | Push course vers Garmin Connect | Envoi GPX/FIT comme activité. Sprint 31 #65 |

---

## 14. Infrastructure & qualité

### Pipeline asynchrone

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | Symfony Messenger | Transport Redis, 5 workers parallèles |
| ✅ | Mercure SSE | Push temps réel vers le frontend. Sécurisé par user. |
| ✅ | ComputationTracker | Suivi statut des 17 computations (ROUTE, STAGES, POIS…) |
| ✅ | Invalidation messages obsolètes | Évite messages orphelins. Sprint 9 #46 |
| ✅ | Timeouts scraping hébergements réduits | Sprint 17 #277 |
| ✅ | Batch Overpass per-stage | Fusion requêtes. Sprint 17 #278 |
| ✅ | Cache warming ScanAllOsmData | Optimisé. Sprint 17 #279 |
| ✅ | Upload GPX 30 MB | Caddy + PHP configurés. Sprint 17 #280 |
| 📅 | Events Mercure dual mode | computation_step_completed + TRIP_READY + STAGE_UPDATED. Sprint 23 #324 |

### Sécurité

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | HTTP clients scopés | Max 2 redirects, timeout 10s, SSRF prevention |
| ✅ | XMLReader hardened | `LIBXML_NONET` + `LIBXML_NOENT` (XXE prevention) |
| ✅ | Upload max 30 MB | Caddy + PHP |
| ✅ | Memory limit PHP 128 MB | Protection OOM |
| 📅 | Headers sécurité Caddy | CSP, HSTS, X-Frame-Options. Sprint 30 |
| 📅 | Audit isolation Mercure | Sprint 30 |
| ✅ | Rate limiting | Magic link, trip create, chat (`config/packages/rate_limiter.php`). Sprint 30 |

### Tests

| Statut | Catégorie | Détail |
|---|---|---|
| ✅ | PHPUnit 13 | Tests unitaires + fonctionnels backend |
| ✅ | PHPStan Level 9 | Analyse statique stricte |
| ✅ | PHP-CS-Fixer | PSR-12 / Symfony |
| ✅ | Rector | Refactoring automatique |
| ✅ | ESLint + Prettier | Frontend |
| ✅ | TypeScript strict | Typage fort |
| ✅ | Playwright 1.60 | Tests E2E (mocked + intégration) |
| ✅ | Recette par sprint | `tests/recette/sprint-NN.spec.ts` |
| ✅ | Recette globale Gherkin FR + EN | 30 fichiers `.feature`. Sprint 16 #240 |
| ✅ | playwright-bdd | Automatisation Gherkin (`make test-recette`). Sprint 16 #246 |
| 📅 | axe-core Playwright | Accessibilité. Sprint 30 |
| 📅 | Lighthouse CI | Performance. Sprint 30 |
| 📅 | Script i18n-check | Complétude FR/EN. Sprint 30 |
| 📅 | Visual regression screenshots | 36 baselines Playwright. Sprint 30 |

### Déploiement & observabilité

| Statut | Fonctionnalité | Détail |
|---|---|---|
| ✅ | CI/CD production | Workflow `deploy.yml` : build/push images GHCR, upload source maps, trigger Coolify, smoke test. ADR-019 |
| ✅ | Oracle Cloud (OCI) Always Free | VM hôte de production. ADR-019 |
| ✅ | Coolify | Orchestration des déploiements via webhook. ADR-019 |
| ✅ | Docker prod | `compose.yaml` (FrankenPHP edge : Caddy + Mercure embarqués, PostgreSQL, Redis, Valhalla) ; dev iso-prod via `compose.dev.yaml`. ADR-037 |
| ✅ | Healthchecks | `GET /api/healthz` (liveness) + `GET /api/health` (readiness). Sprint 30 #497 |
| ✅ | Suivi des erreurs | SDK Sentry (backend + PWA). **Beta (Sprint 34.5 #568) : Sentry SaaS free**, GlitchTip auto-hébergé conservé mais non déployé — réversible. ADR-031. Sprint 30 #500 #495 |
| ✅ | Monitoring uptime | **Beta (Sprint 34.5 #568) : UptimeRobot externe seul** sur `/api/healthz` ; Uptime Kuma auto-hébergé conservé mais non déployé. Alertes → incidents GitHub. Sprint 30 #499 #502 |
| ✅ | Refresh OSM manuel | `make provision-update` + `docker compose restart valhalla`. ADR-036 (supersède ADR-033) |
| ✅ | Migrations & rollback | Stratégie documentée. ADR-032 |
| 📅 | Feature-deploy (preview par PR) | Sprint 32 #312 |

---

## Ressources

- [docs/README.md](docs/README.md) — index de la documentation (par besoin)
- [README.md](README.md) — présentation produit (EN)
- [README.fr.md](README.fr.md) — présentation produit (FR)
- [TRACKING.md](TRACKING.md) — roadmap détaillée par sprint
- [docs/architecture.md](docs/architecture.md) — vue d'ensemble du système
- [docs/adr/](docs/adr/) — Architecture Decision Records
- [docs/getting-started.md](docs/getting-started.md) — démarrage rapide
- [docs/contributing.md](docs/contributing.md) — guide de contribution
- [docs/legal-and-licensing.md](docs/legal-and-licensing.md) — licence, attribution des données, RGPD

---

_Document généré à partir du code source et de `TRACKING.md`. Pour toute incohérence, `TRACKING.md` fait foi sur la roadmap, le code sur ce qui est livré._
