# Idées en vrac

## Mettre à jour la documentation

Résumer les fonctionnalités globales du projet. Par exemple :
> Depuis un lien Komoot, découper l'itinéraire en étapes avec suggestions, détections et analyses, etc.

### Liens des technologies utilisées

Lister dans le fichier README.md les ressources et technologies utilisées. Par exemple :
- Symfony
- API Platform
- OpenStreetMap (+ Geofabrik)
- etc.

### Résumer les détections et analyses

Lister dans le fichier README.md les détections et analyses faites dans le projet. Par exemple :
- hébergements à proximité du lieu d'arrivée d'une étape
- restaurants et commerces alimentaires
- etc.

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

## Rayon de recherche des hébergements

Lorsqu'aucun hébergement n'est trouvé au `endPoint` d'une étape, le message suivant est affiché :
> Aucun hébergement détecté à proximité.

Modifier ce message en indiquant le rayon utilisé :
> Aucun hébergement détecté à proximité dans un rayon de X km.

Suggérer d'élargir le rayon par paliers de 2 km. Au clic sur la suggestion, une nouvelle recherche d'hébergements est lancée avec le nouveau rayon en paramètre.

Lorsque des hébergements sont détectés près du `endPoint`, la suggestion d'élargir le rayon doit toujours être présente jusqu'à atteindre un rayon de 15km autour du `endPoint`. Une fois cette limite atteinte, la suggestion n'est plus affichée.

## Distance entre l'hébergement et le `endPoint`

Pour chaque hébergement détecté ou proposé, afficher la distance qui le sépare du `endPoint` de l'étape. Par exemple :
> La Paille Haute - https://www.la-paille-haute.com/ (3 km)

## Sélectionner un hébergement

Pouvoir sélectionner un hébergement. Lorsqu'un hébergement est sélectionné, cela supprime automatiquement les autres hébergements et recalcule l'itinéraire pour modifier le `endPoint` de l'étape vers cet hébergement et le `startPoint` de l'étape suivante depuis cet hébergement. Les données annexes sont également mises à jour : distance, dénivelés, etc.

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
