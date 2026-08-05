# Audit — libellés de localité hors ligne

Mesures demandées par [#880](https://github.com/vincentchalamon/bike-trip-planner/issues/880)
(sprint 48). Elles arbitrent deux choix : la source des libellés de localité (nœuds `place=*`
au plus proche **contre** polygones administratifs), et la correction du défaut de couverture
soupçonné dans l'issue.

**Réponse courte.** Le défaut est confirmé : `osm.admin_boundaries` est **vide** sur le jeu
local et `osm.coverage` ne contient qu'une ligne à géométrie `NULL`. L'option `place=*` est
écartée par la mesure : elle ne désigne la bonne commune que dans **72,1 %** des cas contre
**99,5 %** pour les polygones communaux, pour un écart de coût qui reste dans le bruit d'un
import de plusieurs minutes (+7,0 Mo de PBF filtré, +54 s d'`osm2pgsql`).

## Jeu de données mesuré

| | |
|---|---|
| Régions provisionnées | `nord-pas-de-calais` + `rhone-alpes` |
| PBF fusionné (`default.osm.pbf`) | 767 068 802 octets |
| Import de référence | `osm.metadata.refreshed_at` = `2026-08-04 13:09:44+00` |
| Points de contrôle qualité | 3 000 lignes de `osm.accommodations` (échantillon `TABLESAMPLE SYSTEM (20)`) |

## 1. Le défaut de couverture est confirmé

```text
$ psql -c "SELECT admin_level, count(*) FROM osm.admin_boundaries GROUP BY 1 ORDER BY 1;"
 admin_level | count
-------------+-------
(0 rows)

$ psql -c "SELECT count(*) FROM osm.admin_boundaries;"
 total_boundaries
------------------
                0

$ psql -c "SELECT ST_IsEmpty(geom) AS empty, geom IS NULL AS isnull FROM osm.coverage;"
 empty | isnull
-------+--------
       | t
```

Cause : les extraits régionaux Geofabrik sont **découpés**. La relation frontière du pays a
donc des ways manquants, `as_multipolygon()` renvoie `nil` et la ligne est ignorée. Le
`ST_Union(geom) WHERE admin_level = 2` de `PostgisImporter::buildDerived()` s'exécute alors
sur zéro ligne et produit une ligne à géométrie `NULL`.

Conséquences observées dans le code de lecture :

- `App\Osm\CoverageRepository` teste `geom IS NOT NULL AND NOT ST_Covers(...)`, donc aucun
  voyage n'est signalé hors zone — le contrôle est **désactivé**, pas faux-positif. L'issue
  supposait l'inverse.
- `AdminBoundaryRepository::findCountryAt()` ne résout **aucun** pays, donc
  `CheckBorderCrossingHandler` n'émet jamais rien.

Taux de reconstruction par niveau, mesuré en comparant le nombre de relations présentes dans
le PBF au nombre de polygones effectivement importés :

| `admin_level` | relations dans le PBF | polygones importés | commentaire |
|---|---|---|---|
| 2 (pays) | 12 | 0 | jamais complet sur un extrait régional |
| 4 (région) | 23 | 0 | idem |
| 6 (département) | 64 | 11 | exactement les départements entièrement contenus |
| 8 (commune) | 4 795 | 4 308 | 89,8 % ; les manquants sont sur la frange de l'extrait |

D'où les deux corrections : importer les niveaux 2, 4, 6 et 8, et construire `osm.coverage`
par l'union de **tous** les niveaux importés. Les niveaux s'emboîtant, un département présent
rebouche les trous laissés par ses communes de frange.

## 2. Coût : nœuds `place=*` contre polygones administratifs

Coût marginal sur le PBF fusionné (`osmium tags-filter`) :

| Filtre ajouté | Taille produite | Objets |
|---|---|---|
| `n/place=city,town,village,hamlet` | 621 750 o | 26 169 nœuds |
| `r/admin_level=2,4,6,8` | 9 297 249 o | 5 077 relations, 18 578 ways, 1 270 394 nœuds |

Chaîne complète, exécutée deux fois sur le même PBF source (osmium + `osm2pgsql --create
--slim --drop`, cache 800 Mo, PostgreSQL 18 / PostGIS 3.6 limité à 1 Go) :

| | référence (`r/admin_level=2`) | retenu (`r/admin_level=2,4,6,8`) | écart |
|---|---|---|---|
| `osmium tags-filter` | 11 s | 12 s | +1 s |
| PBF filtré | 184 564 517 o | 191 586 757 o | +7 022 240 o (+3,8 %) |
| `osm2pgsql` | 106 s | 160 s | +54 s (+51 %) |
| Lignes dans `admin_boundaries` | 0 | 4 319 | +4 319 |
| Union de couverture | 0 s (géométrie `NULL`) | 11 s | +11 s |
| Couverture obtenue | néant | 92 887 points, 57 991 km² | — |

Le surcoût réel est donc de **+3,8 % de PBF filtré** et **+65 s** sur un import qui en prenait
106 : significatif en relatif sur l'étape `osm2pgsql`, négligeable devant le cycle complet de
provisionnement (téléchargement des extraits inclus, ~10 min). Ce n'est pas « trivial », mais
c'est acquis pour un défaut de couverture réparé.

## 3. Qualité : la recherche du plus proche se trompe une fois sur quatre

Protocole : pour chacun des 3 000 points réels, comparer le nom de la commune **contenante**
(`ST_Covers` sur le polygone `admin_level = 8`) au nom du nœud `place=*` le **plus proche**
(`ORDER BY geom <-> point`), avec et sans les hameaux.

| Mesure | Valeur |
|---|---|
| Points avec une commune contenante | 2 985 / 3 000 (**99,5 %**) |
| Plus proche `city,town,village` = commune contenante | 2 152 / 2 985 (**72,1 %**) |
| Plus proche `city,town,village,hamlet` = commune contenante | 1 309 / 2 985 (**43,9 %**) |
| Distance au plus proche `city,town,village` | médiane 1 025 m, p90 2 792 m, max 10 284 m |
| Distance au plus proche en incluant les hameaux | médiane 539 m, p90 1 709 m |

Les 28 % d'écarts ne sont pas des équivalents acceptables, ce sont les erreurs qu'un
utilisateur repère immédiatement :

```text
      commune contenante   |  place=* le plus proche  | distance
---------------------------+--------------------------+----------
 Saint-Étienne             | Saint-Priest-en-Jarez    |   861 m
 Bourg-Saint-Maurice       | Arc 1600                 |  1007 m
 La Plagne-Tarentaise      | Mâcot-la-Plagne          |  4537 m
 Saint-Martin-d'Uriage     | Chamrousse               |  2958 m
 Crolles                   | Bernin                   |  1034 m
 Corenc                    | La Tronche               |   775 m
```

Inclure les hameaux dégrade encore : le point le plus proche devient un lieu-dit que personne
ne reconnaît (43,9 % d'accord seulement).

**Décision.** Polygones communaux. Le libellé est exact par construction (appartenance, pas
proximité), disponible pour 99,5 % des points, et la table `osm.admin_boundaries` existe déjà —
aucune migration, aucune table supplémentaire. La table `place=*` n'est **pas** importée : elle
n'apporterait qu'un repli pour les 0,5 % de points en frange d'extrait, au prix d'une seconde
source de vérité pour le même libellé.

## 4. Résolution de localité, sans réseau

`AdminBoundaryRepository::findLocalityAt()` prend le polygone **le plus fin** couvrant le point
(`admin_level >= 7`, `ORDER BY admin_level DESC`), avec la chaîne de repli de nom habituelle
`name:<locale>` → `name:en` → `name`. Hors zone provisionnée, il renvoie `null` et l'étape
continue d'afficher ses coordonnées.

`ResolveStageLabelsHandler` consomme cette méthode : plus d'appel Nominatim, donc plus de
dépendance externe sur le chemin de calcul ni de plafond à 1 requête/seconde. `App\Geo\ReverseGeocoder`
n'avait plus d'autre appelant et a été supprimé ; le client Nominatim reste utilisé par
`App\Geo\Geocoder` et `App\Controller\GeocodeController` pour la recherche interactive.

En prime, `findCountryAt()` / `findCountryCodeAt()` se rabattent sur l'`ISO3166-2` d'un
département ou d'une région couvrante (`FR-59` → `FR`, localisé via ICU) quand aucun polygone
de niveau 2 n'a pu être construit. La détection de franchissement de frontière et le
calendrier multi-pays cessent donc d'être muets sur un extrait régional.
