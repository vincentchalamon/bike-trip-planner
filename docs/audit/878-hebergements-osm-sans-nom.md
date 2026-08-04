# Audit — hébergements OSM sans nom, par catégorie

Spike de mesure demandé par [#878](https://github.com/vincentchalamon/bike-trip-planner/issues/878)
(sprint 47). Il arbitre le gate de complétude et le résolveur de nom du sprint 49
([#884](https://github.com/vincentchalamon/bike-trip-planner/issues/884)).

**Question posée.** La décision « nom résolu ou entrée exclue » repose sur une prémisse non
mesurée : on ignore combien d'hébergements OSM sont sans nom, dans quelles catégories, et
combien seraient rattrapés par la chaîne de repli envisagée (`name:fr`, `official_name`,
`alt_name`, `operator`, `brand`).

**Réponse courte.** La chaîne de repli ne rattrape rien (4,3 % des entrées sans nom) et, sur
`shelter`, produit majoritairement des libellés nuisibles (« JCDecaux »). `wilderness_hut`
n'est pas concerné : 6,3 % sans nom, comme les catégories commerciales. Le problème est
entièrement concentré sur `shelter`, où le nom est un **mauvais discriminant** : l'exclure
supprime 63 % des abris réellement utiles au bikepacker tout en conservant 2 516 abribus
nommés. Recommandation : contrainte de complétude sur toutes les catégories **sauf
`shelter`**, et filtrage des abris sur `shelter_type` plutôt que sur le nom.

## Jeu de données mesuré

| | |
|---|---|
| Régions provisionnées | `nord-pas-de-calais` + `rhone-alpes` |
| Import | 2026-08-04 (`osm.metadata.refreshed_at` = `2026-08-04 13:09:44+00`) |
| `osm.accommodations` | 16 886 lignes |
| `tourism.accommodations` | 125 966 lignes (import DataTourisme du 22/07, non retouché) |

Rhône-Alpes a été ajouté à la sélection pour cette mesure : Nord-Pas-de-Calais seul ne
contient **aucun** `wilderness_hut` et un seul `alpine_hut`, soit précisément les catégories
sur lesquelles porte la question.

Deux réserves à retenir avant de généraliser :

- **Deux régions, pas la France.** Les proportions sont mesurées sur 16 886 lignes. Les
  ordres de grandeur sont nets (63 % contre 6 %), mais un chiffre exact France entière
  demanderait un provisionnement complet.
- **Image du provisioner du 22/06.** Elle mappe encore `tourism=apartment` sur la catégorie
  `apartment` ; le code courant le mappe sur `rental` ([#906](https://github.com/vincentchalamon/bike-trip-planner/pull/906)).
  Lire `apartment` comme `rental` dans les tableaux : la correspondance est 1 pour 1. Le diff
  entre le `tier1.lua` de l'image et celui de `main` se limite à ce renommage et à
  l'élargissement du tag `website` ; ni la sélection des lignes ni les colonnes `name` /
  `tags` ne changent, donc la mesure reste fidèle.

L'étape DataTourisme a été désactivée pendant ce provisionnement (`DATATOURISME_FLUX_ID` et
`DATATOURISME_APP_KEY` vidées) pour ne pas retoucher les 125 966 lignes déjà en place. Au
passage, la prémisse citée par l'issue est confirmée sur le jeu courant : **0 nom vide sur
125 966 lignes** DataTourisme. Le problème est bien exclusivement OSM.

## 1. Volume et proportion, par catégorie

Requête de l'issue, exécutée telle quelle :

| category | total | sans_nom | % sans nom | rattrapables |
|---|---:|---:|---:|---:|
| `shelter` | 8 062 | 5 123 | 63,5 % | 210 |
| `hotel` | 2 706 | 70 | 2,6 % | 0 |
| `chalet` | 1 423 | 253 | 17,8 % | 26 |
| `camp_site` | 1 405 | 64 | 4,6 % | 2 |
| `guest_house` | 1 386 | 90 | 6,5 % | 3 |
| `apartment` (→ `rental`) | 1 069 | 114 | 10,7 % | 7 |
| `wilderness_hut` | 316 | 20 | 6,3 % | 0 |
| `alpine_hut` | 290 | 8 | 2,8 % | 0 |
| `hostel` | 218 | 10 | 4,6 % | 0 |
| `motel` | 11 | 2 | 18,2 % | 0 |
| **Total** | **16 886** | **5 754** | **34,1 %** | **248** |

Hors `shelter` : 8 824 lignes, 631 sans nom (**7,2 %**), 38 rattrapables.

Le tiers d'entrées sans nom est donc un artefact d'agrégation : **89 % des entrées sans nom
sont des `shelter`**. Les catégories réservables se situent entre 2,6 % et 17,8 %.

Répartition par région, pour les catégories sensibles :

| région | category | total | sans_nom |
|---|---|---:|---:|
| nord-pas-de-calais | `shelter` | 896 | 862 (96,2 %) |
| nord-pas-de-calais | `chalet` | 194 | 103 |
| nord-pas-de-calais | `camp_site` | 326 | 10 |
| nord-pas-de-calais | `alpine_hut` | 1 | 1 |
| rhone-alpes | `shelter` | 7 166 | 4 261 (59,5 %) |
| rhone-alpes | `chalet` | 1 229 | 150 |
| rhone-alpes | `camp_site` | 1 079 | 54 |
| rhone-alpes | `wilderness_hut` | 316 | 20 |
| rhone-alpes | `alpine_hut` | 289 | 7 |

Les 96 % d'abris sans nom en Nord-Pas-de-Calais annoncent la suite : dans une région sans
relief, `amenity=shelter` désigne presque exclusivement du mobilier urbain.

## 2. Ce que la chaîne de repli rattraperait réellement

Clés disponibles sur les 5 754 entrées sans nom :

| category | sans_nom | `name:fr` | `official_name` | `alt_name` | `operator` | `brand` |
|---|---:|---:|---:|---:|---:|---:|
| `shelter` | 5 123 | 0 | 0 | 11 | 199 | 0 |
| `chalet` | 253 | 0 | 0 | 0 | 25 | 1 |
| `apartment` (→ `rental`) | 114 | 0 | 0 | 0 | 7 | 0 |
| `guest_house` | 90 | 0 | 0 | 0 | 3 | 0 |
| `hotel` | 70 | 0 | 0 | 0 | 0 | 0 |
| `camp_site` | 64 | 0 | 0 | 0 | 2 | 0 |
| `wilderness_hut` | 20 | 0 | 0 | 0 | 0 | 0 |
| `hostel` | 10 | 0 | 0 | 0 | 0 | 0 |
| `alpine_hut` | 8 | 0 | 0 | 0 | 0 | 0 |
| `motel` | 2 | 0 | 0 | 0 | 0 | 0 |

Trois des cinq clés de la chaîne sont vides ou anecdotiques : `name:fr` **0 occurrence**,
`official_name` **0**, `alt_name` **11** — et ces 11 portent tous la même valeur,
« Salle hors-sac », qui est une catégorie, pas un nom. `brand` compte **1** occurrence
(« Gîtes de France »). Tout le rattrapage repose donc sur `operator` : 236 lignes, soit
**4,1 % des entrées sans nom**.

### Utilité réelle des valeurs d'`operator`

Échantillon des valeurs les plus fréquentes sur les entrées sans nom (40 valeurs les plus
fréquentes, quantités à droite) :

| category | operator | n | libellé utile ? |
|---|---|---:|---|
| `shelter` | JCDecaux | 87 | non — afficheur publicitaire, l'abri est un abribus |
| `shelter` | STAS | 33 | non — réseau de bus de Saint-Étienne |
| `shelter` | Transdev | 31 | non — transporteur |
| `shelter` | Transdev Saint-Étienne | 12 | non — transporteur |
| `shelter` | S.N.C.F. / SNCF | 13 | non — transporteur |
| `shelter` | Keolis / TCL / Sytral / TAC / Stas | 8 | non — transporteurs |
| `shelter` | Région Auvergne-Rhône-Alpes | 3 | marginal — désigne le propriétaire, pas le lieu |
| `shelter` | Commune de Jongieux, commune de Maisoncelle | 2 | oui — « Abri communal, Jongieux » est actionnable |
| `shelter` | Département de l'Isère, CD62, Grenoble Alpes Métropole | 3 | marginal — même remarque |
| `shelter` | Privé | 1 | non — ce n'est pas un nom |
| `shelter` | Sogedo, Ondea, Arc Vezerontin, Institution Sainte-Marie | 5 | non — sans rapport avec un hébergement |
| `chalet` | Huttopia | 10 | oui — enseigne identifiable |
| `chalet` | Camping la Digue | 6 | oui — désigne le lieu |
| `chalet` | Claire et Gilles Belanger, Martine et Gaby Jay | 4 | oui — usage courant pour un gîte |
| `chalet` | Commune de Sonthonnax-la-Montagne | 1 | oui |
| `chalet` | OVO Network, Immo Select, À Petits Pas, Wam Park | 4 | oui — enseignes |
| `apartment` (→ `rental`) | Pierre et Vacances, Goélia, Dormio Resort, Gite de France | 6 | oui — enseignes |
| `guest_house` | CléVacances, Gite les chamois, Paclaz | 3 | oui |
| `camp_site` | Camping du Lac du Sautet | 2 | oui |

Le verdict se lit par catégorie, pas globalement :

- **Sur `shelter`, `operator` est nuisible.** 175 des 199 valeurs sont des transporteurs ou
  des afficheurs (`~* 'jcdecaux|transdev|keolis|sncf|stas|tcl|sytral|tac|cars|bus|mobilit'`).
  Proposer « JCDecaux » comme hébergement à un bikepacker est pire que ne rien proposer :
  c'est un abribus présenté comme un abri de bivouac. Restent une vingtaine de valeurs de
  collectivités, exploitables mais qui décrivent le propriétaire, pas le lieu.
- **Hors `shelter`, `operator` est utile mais négligeable en volume.** Les 38 valeurs
  concernées sont presque toutes de vrais libellés (enseignes, campings, noms de
  propriétaires). Mais 38 lignes sur 8 824, cela ne justifie pas une chaîne de repli en
  cinq clés dont trois sont vides.

## 3. `shelter` : le nom est un mauvais discriminant

C'est le résultat décisif. `amenity=shelter` mélange des objets sans rapport entre eux. En
classant les 8 062 abris par `shelter_type` :

| classe | `shelter_type` | nommés | sans nom |
|---|---|---:|---:|
| **bruit** | `public_transport`, `carport`, `gazebo`, `sun_shelter`, `umbrella`, `pergola`, `shopping_cart`, `changing_rooms`, `animal_shelter`, `fuel_station`, `market`, `wildlife_hide`, … | 2 523 | 3 606 |
| **pertinent** | `weather_shelter`, `lean_to`, `picnic_shelter`, `basic_hut`, `field_shelter`, `rock_shelter`, `roof`, `basic` | 250 | 429 |
| **indéterminé** | tag absent | 166 | 1 088 |

À lui seul, `shelter_type=public_transport` compte 6 010 lignes, soit 75 % de la catégorie.

Un gate sur le nom trie donc exactement à l'envers de l'intention :

- il **supprime 63 %** des abris pertinents (429 sur 679) ;
- il **conserve 2 516 abribus** nommés (`shelter_type=public_transport` portant un nom
  d'arrêt), qui continuent de polluer la couche.

Les 1 088 abris sans nom et sans `shelter_type` ne sont pas un gisement caché : 630 portent
un tag `building` ou une `source` cadastrale (`cadastre-dgi-fr`), c'est-à-dire des emprises
de bâti importées en masse et taguées `amenity=shelter` sans autre information. Seulement 7
portent un indice de transport public, 69 un `bench`. Sur les 1 517 abris du résidu
pertinent + indéterminé, 24 seulement portent une `description` et 24 un attribut de bivouac
(`fireplace`, `mattress`, `capacity`), 14 un `access` fermé.

## 4. `wilderness_hut` : hypothèse non confirmée

L'issue soupçonnait une concentration sur `shelter` **et** `wilderness_hut`. La mesure ne
confirme que la première :

- `wilderness_hut` : 20 sans nom sur 316, soit **6,3 %** — même ordre que `guest_house`
  (6,5 %) ou `camp_site` (4,6 %) ;
- `alpine_hut` : 8 sur 290, **2,8 %**.

Le détail des 20 `wilderness_hut` sans nom confirme qu'il n'y a rien à sauver en volume :
5 portent `access=private`, 8 se réduisent à `tourism=wilderness_hut` seul ou accompagné
d'une emprise cadastrale, aucun n'a d'`operator`, de `brand`, de `name:fr` ni
d'`official_name`. Deux seulement mériteraient d'être conservés pour leur `description`
(« Des bat-flancs pour 4 personnes… », « Salle hors sac »).

Une exemption pour `wilderness_hut` coûterait donc la peine d'une exception dans le schéma
pour au mieux 15 lignes utiles sur 316.

## 5. Ce que le code fait déjà

Deux comportements existants encadrent la décision :

- `api/src/AccommodationSource/OsmAccommodationSource.php:39-45` **écarte déjà** toute
  entrée sans nom, au moment de la lecture. La couche des abris non nommés est donc **déjà
  vide en production** : le gate du sprint 49 ne ferait que déplacer à l'import une
  exclusion qui a lieu à la lecture. Le coût mesuré ici est un coût déjà payé, pas un coût à
  venir.
- `api/src/InRide/InRideAssistant.php:147-163` tient la position inverse pour l'in-ride :
  restauration et mécanique sans nom sont écartées, **eau et abris sont conservés** avec un
  libellé générique (« Point d'eau », « Abri »), au motif que les coordonnées suffisent à
  agir. Les deux chemins divergent aujourd'hui sur la même donnée.

## Recommandation

**1. Contrainte de complétude sur `name`, pour toutes les catégories d'hébergement sauf
`shelter`.** Coût mesuré : 631 lignes sur 8 824 (7,2 %), dont une part sont des emprises
cadastrales sans information. `wilderness_hut` et `alpine_hut` entrent dans la contrainte
sans exemption : 6,3 % et 2,8 %, aucun rattrapage possible, 5 des 20 huttes concernées étant
de surcroît `access=private`.

**2. Exemption de `shelter` seul, avec libellé générique côté lecture.** Sur cette catégorie
le nom ne discrimine pas la qualité : la contrainte supprimerait 1 517 abris (dont 429
explicitement pertinents) et laisserait 2 516 abribus nommés. La bonne clé de tri est
`shelter_type`, pas `name`. En conséquence :

- filtrer les abris à l'import sur une liste blanche de `shelter_type`
  (`weather_shelter`, `lean_to`, `picnic_shelter`, `basic_hut`, `field_shelter`,
  `rock_shelter`, `roof`, `basic`, plus tag absent) et écarter le bruit urbain
  (`public_transport` en tête) : 6 129 lignes de bruit en moins, dont 2 523 actuellement
  servies parce qu'elles ont un nom ;
- servir les abris sans nom avec le libellé générique « Abri », comme le fait déjà
  `InRideAssistant`, au lieu de les écarter dans `OsmAccommodationSource`.

**3. Abandonner la chaîne de repli à cinq clés.** Mesure : 248 rattrapages sur 5 754 entrées
sans nom (4,3 %), dont 175 sont des noms de transporteurs sur des abribus. `name:fr` et
`official_name` n'ont **aucune** occurrence, `alt_name` 11 (toutes « Salle hors-sac »),
`brand` 1. Si un repli est conservé, le réduire à `operator` puis `brand` **hors `shelter`**,
pour un gain de 38 lignes : à décider au regard du coût du résolveur, pas de son rendement
supposé.

**4. Ne pas rouvrir l'option « catégorie *spots* séparée ».** Elle n'est justifiée ni pour
`wilderness_hut` (6,3 % sans nom, aucun résidu significatif), ni pour `shelter`, dont le
résidu se traite par l'exemption ci-dessus. Créer une catégorie hors
`TripRequest::ALL_ACCOMMODATION_TYPES` imposerait un nouveau vocabulaire au filtre front et
aux DTO pour un gain nul par rapport à une exemption d'une seule catégorie.

## Reproduire la mesure

```bash
# Sélection des régions, puis provisionnement (l'étape DataTourisme est neutralisée)
printf '{"slugs":["nord-pas-de-calais","rhone-alpes"]}' > .docker/osm/data/regions.json
docker compose --profile provisioning run --rm -T \
  -e DATATOURISME_FLUX_ID= -e DATATOURISME_APP_KEY= provisioner --no-interaction

# Décompte par catégorie
docker compose exec -T database psql -U app -d bike_trip_planner -c "
SELECT category, count(*) AS total,
       count(*) FILTER (WHERE name IS NULL OR btrim(name) = '') AS sans_nom,
       count(*) FILTER (WHERE (name IS NULL OR btrim(name) = '')
                          AND (tags ? 'operator' OR tags ? 'brand' OR tags ? 'official_name'
                            OR tags ? 'alt_name' OR tags ? 'name:fr')) AS rattrapables
FROM osm.accommodations GROUP BY 1 ORDER BY 2 DESC;"

# Classement des abris par shelter_type
docker compose exec -T database psql -U app -d bike_trip_planner -c "
SELECT coalesce(tags->>'shelter_type', '(absent)') AS shelter_type, count(*) AS total,
       count(*) FILTER (WHERE name IS NULL OR btrim(name) = '') AS sans_nom
FROM osm.accommodations WHERE category = 'shelter' GROUP BY 1 ORDER BY 2 DESC;"
```
