# Idées en vrac

## Mettre à jour la documentation

Vérifier que la documentation du projet est à jour avec les derniers développements.

### Traduire toute la documentation en français

Tout est dit dans le titre...

### Améliorer la présentation

Résumer les fonctionnalités globales du projet.

### Résumer les suggestions et détections

Résumer les suggestions et détections d'un côté, et les alertes de l'autre.

### Changer la licence

Changer la licence en OpenCreative. Est-ce approprié pour ce projet ?

## Suggestion de déjeuner

Estimer un lieu pour déjeuner (en fonction des commerces disponibles à mi-chemin) et recommander jusqu'à 3 lieux de ravitaillement (restaurants, commerces alimentaires ou supermarchés). Au clic sur chacun d'entre eux, une nouvelle fenêtre mène vers le lien du lieu de ravitaillement.

Uniquement pour les étapes >= 40km.

Si aucun lieu n'est détecté pour le déjeuner, afficher l'alerte LunchNudgeAnalyzer en indiquant "déjeuner".

## Suggestion de dîner

Estimer un lieu pour dîner (dans un rayon de 5km autour de `endPoint`) et recommander jusqu'à 3 lieux de ravitaillement (restaurants, commerces alimentaires ou supermarchés). Au clic sur chacun d'entre eux, une nouvelle fenêtre mène vers le lien du lieu de ravitaillement.

Uniquement pour les étapes >= 40km, sauf la dernière étape.

Si aucun lieu n'est détecté pour le dîner, afficher l'alerte LunchNudgeAnalyzer en indiquant "dîner".

## Suggestion de points d'intérêt culturels

Détecter les points d'intérêt culturels à proximité de l'itinéraire de chaque étape, et les suggérer via un message. Un bouton doit être prévu sur la suggestion invitant l'utilisateur à ajouter ce point d'intérêt dans son itinéraire. Au clic sur l'acceptation, l'itinéraire est recalculé ainsi que ses données : distance, dénivelé, etc.

=> ADR 17 : Valhalla

## Rayon de recherche des hébergements

Lorsqu'aucun hébergement n'est trouvé au `endPoint` d'une étape, le message suivant est affiché :
> Aucun hébergement détecté à proximité.

Modifier ce message en indiquant le rayon utilisé :
> Aucun hébergement détecté à proximité dans un rayon de X km.

Suggérer d'élargir le rayon par paliers de 2 km. Au clic sur la suggestion, une nouvelle recherche d'hébergements est lancée avec le nouveau rayon en paramètre.

Lorsque des hébergements sont détectés près du `endPoint`, la suggestion d'élargir le rayon doit toujours être présente jusqu'à atteindre un rayon de 15km autour du `endPoint`. Une fois cette limite atteinte, la suggestion n'est plus affichée.

## Distance entre l'hébergement et le `endPoint`

Pour chaque hébergement détecté ou proposé, afficher la distance qui le sépare du `endPoint` de l'étape. Par exemple :
> La Paille Haute - <https://www.la-paille-haute.com/> (3 km)

## Sélectionner un hébergement

Pouvoir sélectionner un hébergement. Lorsqu'un hébergement est sélectionné, cela supprime automatiquement les autres hébergements et recalcule l'itinéraire pour modifier le `endPoint` de l'étape vers cet hébergement et le `startPoint` de l'étape suivante depuis cet hébergement. Les données annexes sont également mises à jour : distance, dénivelés, etc.

=> ADR 17 : Valhalla

## Invalidation de messages Messenger

Si je modifie un critère (par exemple, indice de fatigue), des messages Messenger sont dispatchés pour traitement asynchrone. Le temps que les calculs soient effectués, je peux modifier d'autres critères qui envoient d'autres messages Messenger. Auquel cas, les premiers messages deviennent caduques car les critères ont changé.

Est-il possible d'identifier et d'invalider ces anciens messages devenus obsolètes, s'ils n'ont pas déjà été traités ?

## Exporter au format texte

Ajouter un bouton permettant d'exporter l'itinéraire au format texte formatté, pouvant être facilement copié (bouton à prévoir) à destination d'un collage externe (par exemple : WhatsApp).

```text
**TITRE**

- 🚴‍ Distance totale : {distance}km
- 🏔 Dénivelé total : ⬆️ {dénivelé}m ⬇️ {dénivelé}m
- 🧭 https://www.komoot.com/fr-fr/tour/2795080048

**{date}** : {distance}km, ⬆️ {dénivelé}m ⬇️ {dénivelé}m, {nom hébergement} ({lien hébergement}) {prix}-{prix}€
**{date}** : {distance}km, ⬆️ {dénivelé}m ⬇️ {dénivelé}m, {nom hébergement} ({lien hébergement}) {prix}-{prix}€
**{date}** : {distance}km, ⬆️ {dénivelé}m ⬇️ {dénivelé}m
```

---

## Profil altimétrique

Afficher un graphique d'élévation (sparkline) dans chaque `stage-card`. Les données `ele` existent déjà dans les points de chaque étape (`geometry`).

La visualisation de la difficulté est plus intuitive qu'un simple chiffre de D+. Utiliser une librairie légère côté frontend (ex : recharts, lightweight-charts, ou SVG pur).

## Insertion de jours de repos

Bouton "Ajouter un jour de repos" entre deux étapes. Le `startPoint` de l'étape suivante reste identique, les dates se décalent.

Détections et suggestions automatiques :

- Après N jours consécutifs (configurable, ex : tous les 3 jours)
- Lorsque le `CalendarAlertAnalyzer` détecte un jour férié
- Lorsque la météo prévoit des conditions défavorables (pluie forte, vent violent)

Nouveau type d'alerte `NUDGE` pour suggérer un repos.

## Persistance en base de données

PostgreSQL + Doctrine ORM pour stocker les trips de manière pérenne. Remplace le state purement en mémoire (Zustand sans persist) et le cache Redis TTL 30min.

Permet de retrouver un trip après fermeture du navigateur. Les détails architecturaux feront l'objet d'un ADR dédié.

## Undo/Redo

Historique d'actions sur le store Zustand (Ctrl+Z / Ctrl+Y). Compatible avec Immer (snapshots d'état immutables).

Actions couvertes :

- Modification de distance
- Suppression/ajout d'étape
- Modification de dates
- Ajustement du pacing

Particulièrement utile en cas de suppression accidentelle d'une étape.

## Détection des points d'eau

Requête Overpass : `amenity=drinking_water|water_point` et `man_made=water_tap`. Inclure les cimetières français (`landuse=cemetery`) qui proposent souvent de l'eau potable.

Afficher les points d'eau le long de chaque étape avec la distance depuis le départ. Alerte `NUDGE` si aucun point d'eau détecté sur un tronçon > 30 km.

**Note :** Non implémenté actuellement malgré l'impression initiale — confirmé par recherche dans le code.

## Budget récapitulatif

Somme des fourchettes `estimatedPriceMin` / `estimatedPriceMax` de tous les hébergements. Affichage dans le `trip-summary` : "Budget estimé : X€ — Y€".

Mise à jour dynamique quand un hébergement est ajouté, modifié ou supprimé. Intégrer dans l'export texte (idée existante) le total du budget.

## Support de sources de routes supplémentaires

- **Upload GPX direct** : drag & drop ou sélection de fichier (le `GpxStreamParser` existe déjà côté backend)
- **Strava routes** : nouveau `RouteFetcher` dans le `RouteFetcherRegistry` (Strategy pattern)
- **RideWithGPS** : idem, nouveau fetcher

Le pattern Strategy rend l'ajout de nouvelles sources trivial. Ajouter les regex de validation dans les contraintes de sécurité.

## Estimation du temps de parcours

Calculer une heure d'arrivée estimée pour chaque étape en fonction de la distance, du dénivelé et du type de surface.

Règle type Naismith adaptée au vélo : 15 km/h de base, -2 km/h par 500m de D+, -1 km/h sur gravel.

Heure de départ configurable par l'utilisateur (paramètre trip-level, ex : 8h00 par défaut). Afficher "Départ ~8h → Arrivée ~16h30" dans la `stage-card`.

## Horaires lever/coucher de soleil + alerte arrivée nocturne

Calcul local via `date_sun_info()` natif PHP (aucune API externe, aucune dépendance) basé sur la date et les coordonnées du `endPoint`. Fournit aussi le crépuscule civil (`civil_twilight_end`) pour "il fait encore assez clair pour rouler".

Nouveau `SunsetAlertAnalyzer` : alerte `WARNING` si l'heure d'arrivée estimée dépasse le coucher de soleil.

**Dépend de l'idée "Estimation du temps de parcours"** (nécessite l'heure d'arrivée estimée).

## Détection des pentes raides

L'`ElevationAlertAnalyzer` actuel ne vérifie que le D+ total > 1200m. Analyser le profil point par point pour détecter les sections avec gradient > 8% sur plus de 500m.

Les données `ele` existent déjà dans les points de chaque étape. Nouveau `SteepGradientAnalyzer` : alerte `WARNING` avec localisation et pourcentage de la pente.

Crucial pour un vélo chargé (bikepacking).

## Export GPX enrichi avec waypoints

Le GPX actuel ne contient que la trace (`<trk>`). Ajouter des `<wpt>` pour : hébergements, points d'eau, commerces alimentaires.

Le `GpxWriter` existe déjà dans `api/src/Spatial/`, il suffit d'y ajouter les waypoints. Permet au rider d'avoir toutes les infos sur son GPS sans connexion.

## Bouton de téléchargement GPX global

Ajouter un bouton de téléchargement au format GPX de tout l'itinéraire, en plus du bouton de téléchargement GPX par étape (déjà présent).

## Export FIT

Supporter le format FIT (propriétaire Garmin).
Ajouter un bouton de téléchargement global + par étape du format FIT, en parallèle au format GPX.

## Garmin Connect Courses API

Ajouter un bouton "Envoyer sur mon GPS Garmin".
Cf. [ADR 18](adr/adr-018-garmin-export-and-device-sync-strategy.md).

**Prérequis** : Phase 1 + Persistance BDD + infrastructure de production (OAuth PKCE nécessite un callback URL publique HTTPS) + approbation au Garmin Developer Program.

## Docker multi-arch

Prévoir la possibilité de builder les images Docker en multi-arch, pour faciliter le déploiement sur la plupart des infrastructures.
