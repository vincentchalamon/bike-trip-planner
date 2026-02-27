# Plan de correction — Recette PWA Bike Trip Planner

Ce document detaille les corrections a appliquer pour resoudre chaque point
identifie dans `docs/plan/recette.md`. Chaque correction indique les fichiers
concernes, le diagnostic, et les actions a realiser.

**Legende :**

- **[BUG]** — Comportement casse, fonctionnalite non operationnelle.
- **[UX]** — Amelioration d'experience utilisateur.
- **[FEAT]** — Nouvelle fonctionnalite a implementer.

---

## 1. [BUG] Import Komoot Collection echoue

> `Computation failed: Collection data not found in Komoot page.`

**Diagnostic** — Le `KomootHtmlExtractor::extractCollectionTourIds()` cherche la
cle `page._embedded.collection` dans le JSON embarque. Or, Komoot utilise
`page._embedded.collectionHal` pour les pages de collection. Les tours sont
imbriques dans `collectionHal._embedded.compilation._embedded.items[]`.

**Fichiers** :

- `api/src/RouteFetcher/KomootHtmlExtractor.php`

**Actions** :

1. Dans `extractCollectionTourIds()`, remplacer la navigation
   `$embedded['collection']` par `$embedded['collectionHal']`.
2. Naviguer vers `collectionHal._embedded.compilation._embedded.items[]`
   au lieu de `collection._embedded.tours[]`.
3. Chaque item contient `id` (tour ID) et `name` — extraire ces deux champs.
4. Conserver le nom de la collection depuis `collectionHal['name']`.
5. Tester avec `https://www.komoot.com/fr-fr/collection/2367431/-la-diagonale-ardechoise`
   (4 tours attendus).

---

## 2. [BUG] Import Google Maps echoue (URL courte et URL Directions)

> `Cannot extract Google My Maps ID from URL` et
> `Please enter a valid Komoot or Google My Maps URL`.

**Diagnostic** — Deux problemes distincts :

**a) URL courte `maps.app.goo.gl/ZGxbgky6ThriXMeV8`** : cette URL redirige vers
`https://www.google.com/maps/dir/...` (Google Maps Directions), pas vers Google
My Maps (`/maps/d/...`). Le `GoogleMyMapsRouteFetcher` ne sait pas extraire un
Map ID d'une URL Directions.

**b) URL longue `google.com/maps/dir/...`** : le regex de validation frontend
(`SOURCE_URL_REGEX`) et le regex backend (`TripRequest.php`) n'acceptent pas le
format `/maps/dir/`. Le message d'erreur frontend bloque l'envoi a l'API.

**Decisions** :

- **Google Maps Directions** est un format d'URL different de Google My Maps.
  Il ne fournit pas de fichier KML exportable. Le supporter necessiterait un
  nouveau `RouteFetcher` qui extrait les waypoints des parametres URL et
  interroge l'API Google Directions.
- A court terme : accepter toutes les URL valides cote frontend (voir point 8),
  et retourner un message d'erreur explicite depuis l'API lorsque aucun
  `RouteFetcher` ne supporte l'URL.

**Fichiers** :

- `api/src/ApiResource/TripRequest.php` — Regex de validation
- `api/src/RouteFetcher/RouteFetcherRegistry.php` — Message d'erreur
- `pwa/src/components/magic-link-input.tsx` — Regex frontend

**Actions** :

1. **Backend** : Modifier le `#[Assert\Regex]` de `TripRequest::$sourceUrl` pour
   accepter toute URL HTTPS valide :
   `#^https://.+#` (ou supprimer le regex et utiliser `#[Assert\Url]`).
2. **Backend** : Dans `RouteFetcherRegistry`, quand aucun fetcher ne supporte
   l'URL, lever une exception avec un message explicite :
   `"No route fetcher supports the provided URL. Supported formats: Komoot Tour, Komoot Collection, Google My Maps."`.
3. **Frontend** : voir point 8 (Magic Link — accepter toutes les URL).

---

## 3. [BUG] Ajout de stage : HTTP 422

> Au clic sur "+ Add stage", une HTTP 422 survient.

**Diagnostic** — La fonction `handleAddStage` dans `trip-planner.tsx` envoie
`POST /trips/{tripId}/stages` avec uniquement `{ position }`. Le
`StageCreateProcessor` exige `startPoint` et `endPoint` non null, d'ou le 422.

**Fichiers** :

- `pwa/src/components/trip-planner.tsx` — `handleAddStage`
- `pwa/src/store/trip-store.ts` — acces aux stages

**Actions** :

1. Determiner les coordonnees du nouveau stage a partir des stages adjacents :
   - `startPoint` = `endPoint` du stage a l'index `afterIndex`.
   - `endPoint` = `startPoint` du stage a l'index `afterIndex + 1`
     (ou `startPoint` du premier stage si insertion en fin de boucle).
2. Envoyer le body complet au `POST` :

   ```json
   {
     "position": "afterIndex + 1",
     "startPoint": { "lat": "...", "lon": "...", "ele": "..." },
     "endPoint": { "lat": "...", "lon": "...", "ele": "..." }
   }
   ```

3. Si les coordonnees adjacentes ne sont pas disponibles (aucun stage), ouvrir
   un formulaire demandant a l'utilisateur de saisir les points de depart et
   d'arrivee (via `LocationCombobox`).

---

## 4. [BUG] Suppression de stage — contrainte minimum trop stricte

> Il doit etre possible de supprimer les stages meme s'il en reste 1 ou 0.

**Diagnostic** — `StageDeleteProcessor` retourne HTTP 422 si la suppression
laisse moins de 2 stages. La recette demande de permettre la suppression
jusqu'a 0 stage.

**Fichiers** :

- `api/src/State/StageDeleteProcessor.php` — Contrainte minimum
- `pwa/src/components/stage-card.tsx` — Bouton delete desactive si `<= 2`
- `pwa/src/components/trip-planner.tsx` — `handleDeleteStage`

**Actions** :

1. **Backend** : Supprimer la contrainte de minimum 2 stages dans
   `StageDeleteProcessor`. Permettre la suppression du dernier stage.
2. **Backend** : Si la suppression cree une incoherence (par exemple, `endPoint`
   du stage N != `startPoint` du stage N+1), generer une alerte de type
   `warning` sur le stage concerne avec un message explicite.
3. **Backend** : Si le trip a 0 stage apres suppression, ne pas lever
   d'exception. Stocker un tableau vide et publier un evenement Mercure
   `stages_computed` avec un tableau vide.
4. **Frontend** : Retirer la condition `totalStages <= 2` qui desactive le
   bouton de suppression dans `stage-card.tsx`.
5. **Frontend** : Quand le trip a 0 stage, afficher un message invitant
   l'utilisateur a ajouter des stages manuellement. Afficher une alerte
   `warning` indiquant que le parcours est incomplet.
6. **Frontend** : Quand le trip a 1 seul stage, afficher une alerte `warning`
   indiquant qu'il faut au moins 2 etapes pour un parcours complet.

---

## 5. [BUG] Departure/Arrival — valeurs non recuperees et non envoyees

> Les valeurs ne sont pas recuperees depuis l'API, elles sont toujours vides.
> La valeur selectionnee dans le moteur de recherche ne semble pas envoyee
> a l'API.

**Diagnostic** — Deux problemes :

**a) Valeurs non recuperees** : apres `stages_computed`, le hook `use-mercure`
appelle `resolveStageLabels()` qui fait du reverse geocoding sur les
coordonnees start/end de chaque stage. Les labels de depart/arrivee du trip
(`departureLabel`, `arrivalLabel`) sont calcules dans `trip-planner.tsx` a
partir du premier et du dernier stage, mais uniquement s'il y a des stages avec
des `startLabel`/`endLabel` non null. Le reverse geocoding peut echouer ou
retourner null, laissant les labels vides.

**b) Valeurs non envoyees** : les callbacks `onDepartureChange` et
`onArrivalChange` dans `trip-planner.tsx` sont des no-ops. La selection dans le
combobox ne declenche rien.

**Fichiers** :

- `pwa/src/components/trip-planner.tsx` — Callbacks vides, calcul labels
- `pwa/src/hooks/use-mercure.ts` — `resolveStageLabels`
- `pwa/src/store/trip-store.ts` — `updateStageLabel`
- `pwa/src/lib/geocode/client.ts` — `reverseGeocode`

**Actions** :

1. **Verifier le reverse geocoding** : s'assurer que `reverseGeocode()` retourne
   bien des resultats pour les coordonnees des stages. Logger les erreurs au lieu
   de les ignorer silencieusement.
2. **Implementer `onDepartureChange` et `onArrivalChange`** : quand
   l'utilisateur selectionne un lieu, mettre a jour le label du stage
   correspondant dans le store via `updateStageLabel(index, 'start'|'end', label)`.
3. **Envoyer la modification a l'API** : appeler
   `PATCH /trips/{tripId}/stages/{index}` avec le nouveau `label` pour persister
   la modification cote backend.

---

## 6. [BUG] Export PDF bloque sur "Computing..."

> Le bouton semble bloque sur "Computing...".

**Diagnostic** — Le bouton affiche "Computing..." quand `isProcessing === true`
(store UI). Ce flag est mis a `true` lors de la creation du trip et remis a
`false` par l'evenement Mercure `trip_complete`. Si la connexion Mercure est
interrompue ou l'evenement non recu, le flag reste a `true` indefiniment.

**Fichiers** :

- `pwa/src/components/export-pdf-button.tsx`
- `pwa/src/store/ui-store.ts`

**Actions** :

1. **Decoupler le bouton PDF du flag `isProcessing`** : le bouton PDF doit etre
   actif des que des stages sont disponibles, independamment du statut de
   calcul. Supprimer la condition `isProcessing` du texte du bouton.
2. **Alternative** : Ajouter un timeout de securite (par exemple 60 secondes)
   qui remet `isProcessing` a `false` si aucun `trip_complete` n'est recu.
3. **Desactiver le bouton uniquement si** : pas de trip, ou pas de stages.

---

## 7. [BUG] Meteo jamais affichee

> La meteo ne semble jamais affichee nulle part.

**Diagnostic** — Le hook `use-mercure.ts` gere l'evenement `weather_fetched` et
appelle `store.updateStageWeather(s.dayNumber, s.weather)`. L'action
`updateStageWeather` dans le store met a jour le stage dont le `dayNumber`
correspond. Verifier que :

- L'evenement Mercure `weather_fetched` est bien emis par le backend.
- Le `dayNumber` dans l'evenement correspond au `dayNumber` du stage dans le
  store (attention : le store utilise un index 0-based, le backend un
  `dayNumber` 1-based).
- Le composant `stage-metadata.tsx` affiche bien le `WeatherIndicator` quand
  `weather` est non null.

**Fichiers** :

- `pwa/src/hooks/use-mercure.ts` — Handler `weather_fetched`
- `pwa/src/store/trip-store.ts` — `updateStageWeather`
- `pwa/src/components/stage-metadata.tsx` — Affichage meteo
- `pwa/src/lib/mercure/types.ts` — Type `WeatherFetchedEvent`

**Actions** :

1. **Verifier le mapping `dayNumber`** : dans `updateStageWeather`, le store
   cherche un stage par `dayNumber`. Verifier que la valeur correspond bien
   au champ `dayNumber` des stages stockes (et non a l'index du tableau).
2. **Verifier le format de l'evenement** : comparer le type
   `WeatherFetchedEvent` avec les donnees reellement emises par le
   `FetchWeatherHandler` du backend. S'assurer que les champs correspondent.
3. **Verifier la condition d'affichage** : dans `stage-metadata.tsx`, confirmer
   que le `WeatherIndicator` est rendu quand `weather` est non null.
4. **Verifier les dates** : l'API meteo ne fonctionne que pour les dates dans
   les 7 prochains jours. Si le trip n'a pas de `startDate` ou si la date est
   trop eloignee, la meteo ne sera pas disponible. Documenter ce comportement.

---

## 8. [UX/BUG] Magic Link — accepter toutes les URL

> Toutes les URL doivent etre acceptees. Si une URL n'est pas supportee,
> afficher un message d'erreur explicite.

**Diagnostic** — Le regex frontend `SOURCE_URL_REGEX` n'accepte que les URL
Komoot, Google My Maps et `maps.app.goo.gl`. Les URL non reconnues sont
rejetees avant l'envoi a l'API.

**Fichiers** :

- `pwa/src/components/magic-link-input.tsx`
- `api/src/ApiResource/TripRequest.php`

**Actions** :

1. **Frontend** : Remplacer `SOURCE_URL_REGEX` par un simple test d'URL valide :

   ```typescript
   function isValidUrl(value: string): boolean {
     try {
       const url = new URL(value);
       return url.protocol === "https:" || url.protocol === "http:";
     } catch {
       return false;
     }
   }
   ```

2. Si l'URL est invalide (pas une URL), afficher :
   `"Veuillez saisir une URL valide."`.
3. Si l'URL est valide mais que l'API retourne une erreur (aucun RouteFetcher),
   afficher le message d'erreur retourne par l'API :
   `"URL non supportee. Formats acceptes : Komoot Tour, Komoot Collection, Google My Maps."`.
4. **Backend** : Ajuster la validation du `sourceUrl` dans `TripRequest.php`
   (voir point 2).

---

## 9. [UX] Magic Link — toujours editable et spinner cible

> Le champ doit toujours etre editable, meme pendant le chargement.
> Le spinner ne doit etre affiche que pour les donnees en cours de chargement.

**Fichiers** :

- `pwa/src/components/magic-link-input.tsx`
- `pwa/src/store/ui-store.ts`

**Actions** :

1. **Retirer le `disabled` de l'input** quand `isProcessing` est `true`.
   L'utilisateur doit pouvoir saisir une nouvelle URL a tout moment.
2. **Si une nouvelle URL est soumise pendant un calcul** : reset complet du
   store (`resetTrip()`), creation d'un nouveau trip avec la nouvelle URL.
3. **Deplacer le spinner** hors du champ de saisie. Le spinner de chargement
   global doit etre affiche dans la zone de timeline (voir point 15), pas dans
   le champ Magic Link.
4. **Garder un indicateur minimal** dans le champ (par exemple une icone de
   check verte quand l'URL a ete acceptee).

---

## 10. [FEAT] Champs fatigueFactor et elevationPenalty

> Il manque les champs `fatigueFactor` et `elevationPenalty` dans l'interface.

**Diagnostic** — Les deux champs existent dans le backend (`TripRequest.php`) et
sont exposes dans le contrat OpenAPI. Ils alimentent le moteur de pacing selon la
formule `Dn = base * fatigueFactor^(n-1) - (D+ / elevationPenalty)`. Le frontend
les envoie toujours en dur (`0.9` et `50`) sans permettre a l'utilisateur de les
modifier.

**Fichiers** :

- `pwa/src/components/trip-planner.tsx` — Valeurs hardcodees dans POST et PATCH
- `pwa/src/store/trip-store.ts` — Stocker les valeurs courantes
- Nouveau composant : `pwa/src/components/pacing-settings.tsx`

**Actions** :

1. **Ajouter au store** : deux champs `fatigueFactor` (defaut `0.9`) et
   `elevationPenalty` (defaut `50`) dans le trip store, persistes avec le trip.
2. **Creer `pacing-settings.tsx`** : un panneau de reglages avec deux inputs :
   - **Facteur de fatigue** (`fatigueFactor`) : slider ou input numerique,
     plage 0.5–1.0, pas de 0.01. Tooltip explicatif :
     `"Reduction quotidienne de la distance cible (0.9 = -10 %/jour)"`.
   - **Penalite d'elevation** (`elevationPenalty`) : input numerique, valeur
     positive. Tooltip explicatif :
     `"Metres de denivele positif equivalant a 1 km de distance en moins (defaut : 50)"`.
3. **Positionner le composant** : entre le calendrier et la timeline, ou dans
   un panneau depliable "Reglages avances".
4. **Supprimer les valeurs hardcodees** dans `trip-planner.tsx` : lire
   `fatigueFactor` et `elevationPenalty` depuis le store pour les envoyer dans
   le POST et le PATCH.
5. **Recalcul automatique** : quand l'utilisateur modifie un des deux champs,
   envoyer un `PATCH /trips/{id}` avec les nouvelles valeurs pour declencher
   un recalcul des stages (le backend re-dispatche `RecalculateStages`).
6. **Validation frontend** : `fatigueFactor` dans [0.5, 1.0],
   `elevationPenalty` > 0. Afficher un message d'erreur inline si les valeurs
   sont hors limites.

---

## 11. [BUG] Timeline — decoupage par date incorrect

> Les 2 premiers jours sont regroupes dans la timeline.

**Diagnostic** — Le composant `timeline.tsx` rend les stages sequentiellement
sans verifier les changements de `dayNumber`. Tous les stages sont affiches dans
une seule liste verticale sans separation par jour.

**Fichiers** :

- `pwa/src/components/timeline.tsx`

**Actions** :

1. **Grouper les stages par `dayNumber`** : avant le rendu, regrouper les
   stages dans un `Map<number, StageData[]>`.
2. **Rendre un en-tete par jour** : pour chaque `dayNumber`, afficher un
   separateur avec le texte `"Jour N"` (et la date si `startDate` est definie,
   par exemple `"Jour 1 — 15 mars 2026"`).
3. **Placer le bouton "+ Add stage"** entre chaque groupe de jour (pas entre
   chaque stage). Voir point 12.

---

## 12. [UX] Timeline — bouton "+ Add stage" entre chaque jour

> Le bouton doit etre present entre chaque jour.

**Fichiers** :

- `pwa/src/components/timeline.tsx`

**Actions** :

1. Ajouter un `AddStageButton` entre chaque groupe de jour (apres le dernier
   stage du jour N, avant le premier stage du jour N+1).
2. Le bouton doit permettre d'inserer un stage a la position correspondante
   (entre les deux jours).
3. Ne pas afficher de bouton avant le premier jour ni apres le dernier jour
   (sauf si le trip a 0 stage, auquel cas un bouton d'ajout est affiche seul).

---

## 13. [UX] Bouton "+ Add stage" — alignement et curseur

> Le bouton doit etre aligne avec les autres elements de la timeline.
> Le curseur doit indiquer que le bouton est cliquable.

**Fichiers** :

- `pwa/src/components/add-stage-button.tsx`

**Actions** :

1. **Alignement** : Retirer le `ml-10 md:ml-16` et aligner le bouton avec les
   `StageCard` en utilisant le meme offset que la timeline
   (par exemple `ml-6` ou equivalent au padding des cartes).
2. **Curseur** : Ajouter `cursor-pointer` sur le bouton.
3. **Hover** : Ajouter un effet visuel au survol (par exemple
   `hover:border-brand hover:text-brand`).

---

## 14. [UX] Bouton "+ Add accommodation" — presence et curseur

> Le bouton doit etre present dans chaque etape sauf la derniere.
> Le curseur doit indiquer que le bouton est cliquable.

**Diagnostic** — Le composant `stage-card.tsx` n'affiche le panneau
d'hebergement que pour les stages qui ne sont ni le premier ni le dernier. La
recette demande le bouton pour tous les stages sauf le dernier (donc y compris
le premier).

**Fichiers** :

- `pwa/src/components/stage-card.tsx`
- `pwa/src/components/add-accommodation-button.tsx`

**Actions** :

1. **Stage card** : Modifier la condition d'affichage de `AccommodationPanel`
   pour l'afficher sur tous les stages sauf le dernier (retirer la condition
   `isFirst`).
2. **Curseur** : Ajouter `cursor-pointer` sur le `AddAccommodationButton`.
3. **Hover** : Ajouter un effet visuel au survol.

---

## 15. [UX] Noms de lieux — fallback intelligent

> Detecter la ville/commune la plus proche, sinon afficher les coordonnees
> GPS simplifiees au lieu de "Unknown location".

**Fichiers** :

- `pwa/src/components/stage-locations.tsx`
- `pwa/src/hooks/use-mercure.ts` — `resolveStageLabels`
- `pwa/src/lib/geocode/client.ts` — `reverseGeocode`

**Actions** :

1. **Ameliorer `reverseGeocode()`** : s'assurer que la requete Nominatim
   utilise un zoom level suffisant pour retourner une commune (zoom 10-12
   au lieu de l'adresse precise).
2. **Fallback GPS** : si le reverse geocoding echoue ou retourne null, afficher
   les coordonnees simplifiees : `"50.638°N, 3.050°E"` au lieu de
   `"Unknown location"`.
3. **Appliquer le fallback** dans `stage-locations.tsx` et dans
   `location-fields.tsx` (depart/arrivee du trip).

---

## 16. [UX] Timeline — spinner de chargement initial

> Des la saisie de l'URL, la timeline doit s'afficher avec un spinner.

**Fichiers** :

- `pwa/src/components/trip-planner.tsx`
- `pwa/src/components/timeline.tsx`

**Actions** :

1. **Afficher la zone timeline** des que `isProcessing` est `true` et qu'un
   trip ID existe, meme si `stages` est vide.
2. **Afficher un skeleton/spinner** dans la zone timeline pendant le
   chargement : une animation de lignes grises simulant les cartes de stages.
3. **Remplacer le skeleton par les stages** des que `stages_computed` est recu.

---

## 17. [UX] Spinners granulaires par donnee

> Un spinner doit etre affiche a la place de chaque donnee en attente.

**Fichiers** :

- `pwa/src/components/stage-metadata.tsx` — Distance, elevation
- `pwa/src/components/weather-indicator.tsx` — Meteo
- `pwa/src/components/stage-locations.tsx` — Labels
- `pwa/src/components/trip-summary.tsx` — Totaux
- `pwa/src/store/trip-store.ts` — `computationStatus`

**Actions** :

1. **Utiliser `computationStatus`** du store pour determiner quelles donnees
   sont en cours de chargement. Chaque cle (`route`, `stages`, `weather`,
   `pois`, `accommodations`, etc.) a un statut (`pending`, `computing`,
   `done`).
2. **Stage metadata** : afficher un `Skeleton` inline pour distance et
   elevation tant que `computationStatus.stages !== 'done'`.
3. **Weather** : afficher un `Skeleton` tant que
   `computationStatus.weather !== 'done'` et que `weather` est null.
4. **Labels** : afficher un `Skeleton` pour les labels tant que le reverse
   geocoding est en cours.
5. **Trip summary** : afficher un `Skeleton` pour le total distance/elevation
   tant que `computationStatus.route !== 'done'`.

---

## 18. [FEAT] Bouton de changement de theme

> Ajouter un bouton pour changer le theme : systeme (defaut), clair, sombre.

**Fichiers** :

- Nouveau composant : `pwa/src/components/theme-toggle.tsx`
- `pwa/src/app/layout.tsx` — Positionnement

**Actions** :

1. **Creer `theme-toggle.tsx`** : un bouton dropdown avec 3 options
   (Systeme, Clair, Sombre) utilisant `next-themes` (deja configure via
   `ThemeProvider`).
2. **Icones** : `Monitor` (systeme), `Sun` (clair), `Moon` (sombre) depuis
   `lucide-react`.
3. **Positionnement** : placer le bouton en haut a droite de la page, dans un
   conteneur flex avec le bouton d'export PDF.
4. **Style** : bouton `ghost` avec icone seule, tooltip au survol.

---

## 19. [UX] Export PDF — repositionnement

> Le bouton doit etre positionne en haut a droite de la page, a cote du
> bouton de changement de theme.

**Fichiers** :

- `pwa/src/components/export-pdf-button.tsx`
- `pwa/src/components/trip-planner.tsx` — Layout

**Actions** :

1. **Creer un conteneur "toolbar"** en haut a droite de la page (position
   `fixed` ou `sticky`) contenant le bouton de theme et le bouton PDF.
2. **Style des boutons** : apparence coherente, meme taille, icones alignees.
3. **Bouton PDF** : icone `FileDown` avec tooltip `"Exporter en PDF"`.
   Masquer le texte sur mobile (icone seule).

---

## 20. [FEAT] Titre — liste de 20 noms et suggestion

> Completer la liste des noms suggeres jusqu'a 20 noms.
> Apres recuperation du titre depuis l'API, afficher une suggestion.

**Fichiers** :

- `pwa/src/components/trip-title.tsx`

**Actions** :

1. **Completer la liste** `FEMINIST_NAMES` a 20 noms. Femmes d'aventure
   celebres, differentes epoques et regions du monde. Suggestions :

   ```text
   Annie Londonderry, Alfonsina Strada, Evelyne Carrer, Beryl Burton,
   Eileen Sheridan, Marianne Martin, Dervla Murphy, Reine Bestel,
   Junko Tabei, Wangari Maathai, Freya Stark, Nellie Bly,
   Bessie Coleman, Valentina Tereshkova, Gertrude Bell,
   Isabelle Eberhardt, Sacagawea, Amelia Earhart,
   Alexandra David-Neel, Jeanne Baret
   ```

2. **Message de suggestion** : apres que le titre est mis a jour depuis l'API
   (evenement `route_parsed`), afficher un bandeau sous le titre :

   ```text
   "Et si vous l'appeliez « {nom_aleatoire} » ?" [Appliquer] [✕]
   ```

3. Le nom suggere doit etre different du titre actuel.
4. **Clic sur "Appliquer"** : remplace le titre, masque le bandeau.
5. **Clic sur "✕"** : masque le bandeau sans changer le titre.
6. Le bandeau n'apparait qu'une seule fois par chargement (ne pas re-afficher
   apres fermeture).

---

## 21. [UX] Departure/Arrival — moteur de recherche et icones

> Le moteur de recherche doit s'afficher des le clic.
> Les icones d'edition doivent toujours etre visibles.

**Fichiers** :

- `pwa/src/components/location-fields.tsx`
- `pwa/src/components/editable-field.tsx`

**Actions** :

1. **Ouverture au clic** : dans `location-fields.tsx`, passer directement en
   mode edition (afficher le `LocationCombobox`) au clic sur le champ. Pas de
   double clic ou de clic sur l'icone requise.
2. **Fermeture a la selection** : a la selection d'un resultat dans le
   combobox, fermer le combobox et afficher la valeur selectionnee.
3. **Icones toujours visibles** : dans `editable-field.tsx`, retirer
   `md:opacity-0 md:group-hover:opacity-100` de l'icone crayon. L'icone doit
   toujours etre visible (par exemple `opacity-60` au repos,
   `opacity-100` au survol).

---

## 22. [UX] Calendrier — centrage, animation, contraintes de dates

> Mois/annee centres. Animation de pliage/depliage. Contraintes sur les dates.

**Fichiers** :

- `pwa/src/components/calendar-widget.tsx`
- `pwa/src/hooks/use-calendar.ts`

**Actions** :

1. **Centrage** : utiliser `justify-between` sur le conteneur de navigation
   avec les boutons fleche a gauche et a droite, et le texte mois/annee en
   `text-center flex-1` au milieu.
2. **Boutons de navigation** : position fixe via `w-8 shrink-0` pour eviter
   les deplacements quand le texte du mois change de taille.
3. **Animation** : utiliser `max-height` + `overflow-hidden` + `transition-all`
   pour animer l'expansion/reduction du calendrier. Calcul du `max-height`
   en fonction du nombre de semaines affichees.
4. **Date de fin >= date de debut** : dans `use-calendar.ts`, a la selection
   d'une date de fin, verifier qu'elle est >= date de debut. Si inferieure,
   ignorer la selection ou traiter comme une nouvelle date de debut.
5. **Re-selection de date de debut** : si l'utilisateur a deja une plage
   selectionnee et clique a nouveau, traiter le clic comme une nouvelle date
   de debut et effacer la date de fin.

---

## 23. [UX] Hebergements — icones, prix, lien, tri, edition

> Remplacer les types bruts par des icones. Afficher les prix au format
> numerique. Afficher le lien cliquable. Trier par prix. Bouton d'edition
> unique.

**Fichiers** :

- `pwa/src/components/accommodation-item.tsx`
- `pwa/src/components/accommodation-panel.tsx`
- `pwa/src/components/add-accommodation-button.tsx`

**Actions** :

1. **Icones de type** : creer un mapping type vers icone + label :

   | Type        | Icone         | Label FR      |
   |-------------|---------------|---------------|
   | `hotel`     | `Hotel`       | Hotel         |
   | `chalet`    | `Home`        | Gite          |
   | `camp_site` | `Tent`        | Camping       |

   Afficher l'icone + le label au lieu du texte brut.

2. **Lien cliquable** : si l'hebergement a un champ `url` (a ajouter dans le
   schema Zod `AccommodationSchema` et le DTO backend `Accommodation`), afficher
   le hostname du lien a cote du nom :

   ```text
   Mont des Bruyeres — www.campingmontdesbruyeres.com
   ```

   Le lien s'ouvre dans un nouvel onglet.

3. **Format de prix** : afficher `"10 € – 17 €"` (si `!isExactPrice`) ou
   `"17 €"` (si `isExactPrice`). Utiliser
   `Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' })`.

4. **Tri par prix** : dans `accommodation-panel.tsx`, trier les hebergements
   par `estimatedPriceMin` croissant avant le rendu.

5. **Bouton d'edition unique** : remplacer les multiples `EditableField` par
   un mode edition global par hebergement. Un seul bouton `Pencil` (a cote du
   bouton `Trash2`) qui bascule tous les champs en mode edition. Champs
   editables : titre, URL, type (select parmi les 3 valeurs), prix min,
   prix max.

6. **Nouveau bloc editable** : au clic sur "+ Add accommodation", creer un
   bloc avec tous les champs en mode edition par defaut (formulaire vide).

---

## 24. [FEAT] Traduction API via Accept-Language

> Traduire les textes de l'API grace a l'en-tete Accept-Language.

**Diagnostic** — Actuellement, les messages d'alertes, les labels de stages et
les textes du PDF sont en dur (majoritairement en francais ou anglais). Il n'y a
pas de systeme de traduction configure.

**Fichiers** :

- `api/config/packages/translation.php` (a creer)
- `api/translations/` (repertoire a creer)
- `api/src/Analyzer/Rules/*.php` — Messages d'alertes
- `api/templates/pdf/roadbook.html.twig` — Textes PDF
- `pwa/src/lib/api/client.ts` — En-tete Accept-Language

**Actions** :

1. **Configurer le composant Translation** de Symfony :

   ```php
   // config/packages/translation.php
   $containerConfigurator->extension('framework', [
       'default_locale' => 'fr',
       'translator' => [
           'default_path' => '%kernel.project_dir%/translations',
           'fallbacks' => ['fr'],
       ],
   ]);
   ```

2. **Creer les fichiers de traduction** : `messages.fr.yaml`,
   `messages.en.yaml` avec tous les messages d'alertes et labels.
3. **Injecter le `TranslatorInterface`** dans les analyzers et handlers qui
   produisent des messages textuels.
4. **Propager la locale** : la locale doit etre extraite de l'en-tete
   `Accept-Language` de la requete HTTP initiale, stockee dans le cache avec
   le trip, et transmise aux message handlers via le message payload.
5. **Frontend** : ajouter l'en-tete `Accept-Language` dans le client API :

   ```typescript
   headers: {
     "Accept-Language": navigator.language,
   }
   ```

6. **PDF** : utiliser le `TranslatorInterface` dans le controleur de generation
   PDF pour traduire les textes du template Twig.

---

## Ordre d'execution recommande

Les corrections sont classees par priorite et dependances.

### Phase 1 — Bugs bloquants (import/export)

| N   | Correction                           | Points |
|-----|--------------------------------------|--------|
| 1   | Import Komoot Collection             | 1      |
| 2   | Import Google Maps (URL validation)  | 2, 8   |
| 3   | Ajout de stage (HTTP 422)            | 3      |
| 4   | Suppression de stage (contrainte)    | 4      |
| 5   | Export PDF bloque                    | 6      |

### Phase 2 — Donnees manquantes

| N   | Correction                           | Points |
|-----|--------------------------------------|--------|
| 6   | Departure/Arrival non recuperes      | 5      |
| 7   | Meteo non affichee                   | 7      |
| 8   | Noms de lieux fallback               | 15     |

### Phase 3 — UX composants

| N   | Correction                           | Points |
|-----|--------------------------------------|--------|
| 9   | Bouton theme + repositionnement PDF  | 18, 19 |
| 10  | Magic Link editable + spinner        | 9      |
| 11  | fatigueFactor et elevationPenalty     | 10     |
| 12  | Timeline decoupage par jour          | 11, 12 |
| 13  | Spinners granulaires                 | 17     |
| 14  | Timeline spinner initial             | 16     |
| 15  | Calendrier (centrage, animation)     | 22     |
| 16  | Boutons curseur + alignement         | 13, 14 |

### Phase 4 — Hebergements

| N   | Correction                           | Points |
|-----|--------------------------------------|--------|
| 17  | Icones, prix, lien, tri             | 23     |

### Phase 5 — Titre et edition

| N   | Correction                           | Points |
|-----|--------------------------------------|--------|
| 18  | 20 noms + suggestion                 | 20     |
| 19  | Departure/Arrival UX (icones, clic)  | 21     |

### Phase 6 — Internationalisation

| N   | Correction                           | Points |
|-----|--------------------------------------|--------|
| 20  | Accept-Language + traductions         | 24     |

---

## Verification

Apres chaque phase, executer :

```bash
make qa          # PHPStan, PHP-CS-Fixer, ESLint, Prettier, TypeScript
make test-php    # PHPUnit
make markdownlint
```

Apres la derniere phase, executer la suite complete :

```bash
make test        # QA + PHPUnit + Playwright + OpenAPI lint + Security check
```

Reprendre les scenarios de recette decrits dans `docs/plan/recette.md` pour
valider chaque correction.
