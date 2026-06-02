# Ordre 3 — Manifeste d'éléments attendus

Manifeste **présence + position approximative** des éléments par écran, dérivé de l'export Claude Design vendoré sous [`design/`](design/). Destiné à une comparaison automatisée (Playwright) app vs design.

**Rappel de convention :** seules la **présence** et la **position approximative** (région : header / sidebar / main / footer ; ordre vertical) sont vérifiables automatiquement. Couleur, typo, espacement exact, polish => **regard humain** (capture côte-à-côte en recette manuelle).

L'export design est en **1280px** (desktop, hauteur ~860px par page). Les libellés ci-dessous sont **verbatim** depuis le design (français) ; l'app réelle peut différer selon la locale active.

## Design tokens (`design/tokens.jsx`)

- **Polices :** serif `Fraunces`, sans `Inter Tight`, mono `JetBrains Mono`.
- **Palette clair (accent ambre) :** fond `#faf7f0`, surface `#ffffff`, surface alt `#f3ece0`, ligne `#e4dbc9`, encre `#2a2418`, encre douce `#5a4e3a`, accent `#c2671e`.
- **Sévérités :** rouge `#b84420` (critique), vert `#4a7a3e` (ok), bleu `#3d6b91` (info).
- Mode sombre supporté via toggle thème.

## Notation des positions

- **header** : barre supérieure pleine largeur.
- **sidebar-L / sidebar-R** : colonne latérale gauche / droite.
- **main** : zone centrale principale.
- **footer** : bas de page.
- **overlay** : modale / popover centré au-dessus du contenu.
- Ordre indiqué top-to-bottom dans chaque région.

---

## Landing — `/` anon (`design/pages-auth.jsx` `PageLandingDesktop`)

| Région | Éléments attendus (ordre vertical) |
|---|---|
| header | logo + "Bike Trip Planner" ; sélecteur langue FR/EN ; toggle thème ; bouton "Se connecter" ; bouton "Demander l'accès" (accent) |
| main (hero) | pill "Beta privée · accès sur demande" ; H1 ; sous-titre sources ; CTA "Créer mon itinéraire" + "Voir la démo" ; indicateur de scroll |
| main | section "De l'import au roadbook, en quatre étapes." (4 cartes numérotées) |
| main | bento "Tout ce qu'il faut pour rouler serein." (terrain, météo, ravitaillement, IA, hébergements, alertes, exports) |
| main | section sources "Importez depuis ce que vous avez." (Komoot, RideWithGPS, Strava, GPX, IA) |
| main | aperçu écrans "Sur tous vos écrans." (mockup desktop + mobile) |
| main | CTA "Rejoignez la beta privée." (champ email + "Demander l'accès") |
| footer | copyright + liens FAQ / Confidentialité / Mentions légales / GitHub |

## Login — `/login` (`PageLoginDesktop`)

| Région | Éléments attendus |
|---|---|
| overlay (carte 440px centrée) | logo + titre ; H2 "Bon retour." ; champ email ; bouton "Envoyer le lien magique" ; lien "Pas encore de compte ? Demander l'accès" |
| overlay (état envoyé) | icône check ; H2 "Email envoyé, vérifie ta boîte." ; texte expiration 15 min ; bouton "Renvoyer un lien (Ns)" (disabled pendant cooldown) |

## Auth verify — `/auth/verify/[token]` (`PageAuthVerifyDesktop`)

| Région | Éléments attendus |
|---|---|
| overlay (480px) état verifying | loader ; H2 "Vérification du lien…" ; texte |
| overlay état expiré | icône alerte (rouge) ; H2 "Lien expiré ou invalide." ; boutons "Renvoyer un lien" + "Retour" |

## Access request verify — `/access-requests/verify` (`PageAccessRequestDesktop`)

| Région | Éléments attendus |
|---|---|
| overlay (480px) | icône check (accent) ; H2 "Demande prise en compte." ; texte ; bouton "Retour à l'accueil" |

## FAQ — `/faq` (`PageFaqDesktop`)

| Région | Éléments attendus |
|---|---|
| header | TopBar |
| sidebar-L (240px) | label "Sections" ; liens (Prise en main, Import & formats, Calculs & météo, Partage, Hors-ligne, Tarifs) ; carte lien GitHub |
| main | H1 "Foire aux questions" ; texte ; accordéon FAQ |
| footer | footer |

## Account settings — `/account/settings` (`PageAccountSettingsDesktop`)

| Région | Éléments attendus |
|---|---|
| header | TopBar |
| sidebar-L (240px) | avatar + nom + email ; bouton "Mon compte" (actif) ; bouton déconnexion (rouge) |
| main | H1 "Mon compte" ; carte Email + "Modifier via magic link" ; carte Préférences (langue FR/EN + thème Clair/Sombre/Auto) ; carte RGPD + "Télécharger mes données (JSON)" ; Danger Zone + "Supprimer mon compte…" (rouge) |
| footer | footer |

## Privacy — `/privacy` (`design/pages-extra.jsx` `PagePrivacyDesktop`)

| Région | Éléments attendus |
|---|---|
| sidebar-L (220px) | "Sommaire" + liens (Introduction, Base légale, Données collectées, Conservation, Vos droits, Cookies & analytics, Contact DPO) |
| main | pill "Politique de confidentialité" ; H1 "Confidentialité" ; sous-titre date + base légale RGPD ; sections de contenu |
| footer | footer |

## Legal — `/legal` (`PageLegalDesktop`)

| Région | Éléments attendus |
|---|---|
| sidebar-L (220px) | menu (Éditeur, Hébergeur, Contact, Propriété intellectuelle, Licence code source) |
| main | pill "Mentions légales" ; H1 "Mentions légales" ; sous-titre ; sections |
| footer | footer |

## Trips list — `/trips` (`design/pages-trips.jsx` `PageTripsListDesktop`)

| Région | Éléments attendus |
|---|---|
| header | TopBar |
| main (header) | H1 "Mes voyages" ; recherche ; bouton "Filtrer par date" ; bouton "Nouveau voyage" (accent) |
| main (grille) | cartes voyage (2 col) : vignette carte ~190px ; pill statut (En cours / Terminé / Brouillon / Archivé) ; H3 titre ; route + date ; stats distance + durée |
| footer | footer |

## Roadbook (détail) — `/trips/[id]` (`design/pages-roadbook.jsx` `PageRoadbookDesktop`)

| Région | Éléments attendus |
|---|---|
| header | barre de progression recalcul (au-dessus) ; TopBar avec undo + share |
| main (header voyage) | breadcrumb "Mes voyages › [titre]" ; H1 titre + dates + km + D+ + pill difficulté ; **carte synthèse IA globale** (droite) |
| sidebar-L (280px) | header "Étapes" ; timeline verticale (cercle jour + nom + km + D+ + points d'alerte) ; boutons "ajouter étape / jour de repos" |
| main (centre) | carte OSM (étape active) + overlay POI ; contrôles carte (coordonnées + zoom) ; toggle "Carte" / "Satellite" ; profil altimétrique "Profil altimétrique — [étape]" ; `SupplyTimeline` |
| sidebar-R (360px) | pill "JOUR N · [date]" + boutons "GPX" / "FIT" ; H2 nom étape ; **synthèse IA d'étape** ; métriques (KM éditable, D+, DEP, ETA) ; jauge difficulté ; carte météo ; alertes groupées (critique/attention/info) ; événements (repliable) ; hébergements (sélection + alternatives + "Élargir la zone (5 km)" + "Ajouter manuellement") |
| overlay | **bulle de chat IA** |
| footer | footer |

## Vue partagée — `/s/[code]` (`PagePublicSharedDesktop`)

| Région | Éléments attendus |
|---|---|
| header | bannière "Vue partagée en lecture seule — vous consultez un itinéraire partagé par [nom]." (bleu) ; top bar simplifiée (logo + "Télécharger GPX" + "Télécharger FIT") |
| main (hero) | carte OSM pleine largeur + overlay titre/route (bas gauche) + stats (bas droite) |
| main (grille) | cartes étapes (barre de couleur + cercle jour + date + H3 route + profil + stats km/D+) |
| footer | footer |

## Wizard step 1 — préparation (`design/pages-wizard.jsx` `PageTripsNewDesktop`)

| Région | Éléments attendus |
|---|---|
| header | TopBar (page new) |
| sidebar-L | `WizardStepper` |
| main | H1 "D'où vient votre tracé ?" ; sélecteur onglets "Lien URL" / "Fichier GPX" / "Assistant IA" |
| main (onglet URL) | champ URL ; badge détection ("Komoot Tour détecté") ; carte aperçu route ; bouton "Continuer — Aperçu" |
| main (onglet GPX) | drop zone (icône + label selon état + barre upload) ; bouton "Continuer — Aperçu" (succès) |
| main (onglet IA) | conteneur chat (header accent "Assistant IA · Génération d'itinéraire" ; messages ; champ + "Envoyer" ; "Valider et continuer") |
| footer | footer |

## Wizard step 2 — aperçu & config (`PageTripsNewDesktopStep2`)

| Région | Éléments attendus |
|---|---|
| sidebar-L | `WizardStepper` |
| main-L | titre voyage éditable + icône edit ; carte OSM ; profil altimétrique ; stats (Distance, Dénivelé +, Dénivelé −, Durée est.) |
| main-R (panneau config) | sélecteur profil (Débutant/Intermédiaire/Expert) ; 5 sliders pacing (distance max, vitesse, départ, fatigue, pénalité dénivelé) ; toggle e-bike ; dates + types hébergement (pills) ; section "Affiner avec l'IA" (textarea + "Effacer"/"Appliquer" + changements proposés) ; CTA "Lancer l'analyse" (accent) + "Retour" |
| footer | footer |

## Wizard step 3 — analyse (`PageProcessingDesktop`)

| Région | Éléments attendus |
|---|---|
| sidebar-L | `WizardStepper` |
| main-L | H1 titre voyage ; texte "Traitement en parallèle…" ; carte progression globale (label + % + barre) ; liste des actes de calcul (7 items : done/running/pending + label + badges + barre running) |
| main-R | label "Aperçu temps réel" ; carte OSM ; carte profil altimétrique ; stats (Distance, Dénivelé +, POI trouvés) |
| footer | footer |

## Modales (`design/modals.jsx`)

### Modale Partage (`ModalShareDesktop`, 600px)

| Section | Éléments |
|---|---|
| header | H3 "Partager ce voyage" + sous-titre + bouton fermer |
| Lien partageable | URL (mono, accent) ; boutons "Copier" + "Révoquer" (rouge) ; info accès lecture seule |
| Infographie PNG | label + badge "1080×1080" ; aperçu canvas ; bouton "Télécharger PNG (1080×1080)" |
| Résumé texte | bloc texte mono (titre, distance, D+, dates, étapes) ; bouton "Copier le texte" |
| Garmin Connect | état non connecté : "Connecter Garmin" (vert) ; état connecté : email + "Envoyer ce voyage vers Garmin Connect" |

### Modale Config (`ModalConfigPanel`, 360px)

| Section | Éléments |
|---|---|
| header | H3 "Paramètres" |
| Dates | inputs "Départ" / "Retour" |
| Profil cycliste | 3 boutons profil + 5 sliders + toggle e-bike |
| Hébergements | 9 toggles type (Gîte, Hôtel, Camping, …) |
| Thème + Langue | sélecteur thème (Clair/Sombre/Auto) + langue (FR/EN) |
| Actions | "Dupliquer ce voyage" + "Partager" |

### Modale Aide (`ModalHelp`, 520px)

| Onglet | Éléments |
|---|---|
| Raccourcis | liste : `J`/`K`, `Ctrl+Z`, `Ctrl+Y`, `?`, `Esc`, `T`, `M` |
| FAQ rapide | 4 cartes Q/R |

### Modale FAQ (`ModalFaq`, 640px)

- accordéon 7 items (premier ouvert par défaut).

## Écrans système (`not-found.tsx`, `error.tsx`)

- **404** (`PageNotFoundDesktop`) : "404" serif ; H2 "Hors-piste." ; CTA "Retour à l'accueil" + "Mes voyages".
- **500** (`PageErrorDesktop`) : icône alerte ; badge "Erreur serveur · 500" ; bloc `request_id` + timestamp ; CTA "Réessayer" + "Signaler le problème".

## Références design utiles (non routées)

Présentes dans l'export comme planches de référence, à utiliser pour la recette visuelle manuelle :

- **États UI** (`PageStatesDesktop`) : empty / skeleton / error / form states.
- **Légende pictos** (`PagePictoLegendDesktop`) : fins d'étape, hébergements (9), eau & ravitaillement, services vélo, POI culturels.
- **POI popover** (`design/pages-poi.jsx`) : variante enrichie (Wikidata/DataTourisme) vs minimale (OSM).
- **Infographie** (`PageInfographicDesktop`) : format 1080×1080 du partage PNG.
