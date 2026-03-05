# Problèmes identifiés

## Alertes de restaurant ou commerce alimentaire

(Testé avec <https://www.komoot.com/fr-fr/tour/2795080048>)

L'alerte suivante est affichée sur chaque étape :
> Aucun restaurant ou commerce alimentaire détecté sur cette étape. Prévoyez des provisions.

Pourtant, l'itinéraire passe chaque jour par des villes ou communes proposant des restaurants ou commerces alimentaires (boulangeries, sandwicheries, supermarchés, etc.).

Comportement attendu : détecter les restaurants ou commerces alimentaires sur chaque étape afin de ne pas afficher cette alerte.

## Dénivelés totaux

(Testé avec <https://www.komoot.com/fr-fr/tour/2795080048>)

Les dénivelés positif + négatif totaux ne semblent pas bon.
Komoot indique un total de 610m positif + 610m négatif.
Gemini indique un total de 505m positif + 498m négatif, soit une différence totale de 105m positif + 112m négatif.
L'application indique un total de 397m positif + 370m négatif, soit une différence totale de 213m positif + 240m négatif.

Le dénivelé positif total correspond à la somme des dénivelés positifs de l'itinéraire. Le dénivelé négatif total correspond à la somme des dénivelés négatifs de l'itinéraire.

Comportement attendu : calculer les dénivelés positif et négatif totaux le plus précisément possible.

## Performances

(Testé avec <https://www.komoot.com/fr-fr/tour/2795080048>)

La détection, le calcul et l'affichage de certaines données prennent du temps. Par exemple : recherche d'hébergements, points d'intérêts, commerces, etc.

Comportement attendu : calculer, détecter et afficher les données en moins de 5 secondes.
