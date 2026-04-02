# language: fr
Fonctionnalité: Alertes et analyse
  En tant que cycliste,
  je veux voir des alertes pertinentes sur mes étapes,
  afin d'anticiper les difficultés et adapter mon planning.

  Contexte:
    Étant donné que j'ai créé un voyage complet avec 3 étapes

  @desktop @critique
  Scénario: Affichage des alertes de distance excessive
    Quand une étape dépasse la distance maximale configurée
    Alors je vois une alerte de distance excessive sur cette étape

  @desktop @critique
  Scénario: Affichage des alertes de dénivelé important
    Quand une étape a un dénivelé supérieur à 2000m
    Alors je vois une alerte de dénivelé important sur cette étape

  @desktop @critique
  Scénario: Alerte météo sur une étape avec conditions défavorables
    Quand les données météo indiquent de la pluie sur l'étape 2
    Alors je vois une alerte météo sur la carte de l'étape 2

  @desktop
  Scénario: Alerte d'hébergement manquant
    Quand aucun hébergement n'est trouvé dans un rayon de 15 km pour une étape
    Alors je vois une alerte d'hébergement sur cette étape

  @desktop
  Scénario: Alerte de point de ravitaillement manquant
    Quand une longue portion de route ne contient aucun point de ravitaillement
    Alors je vois une alerte de ravitaillement sur l'étape concernée

  @desktop
  Scénario: Alerte de fatigue en fin de voyage
    Quand les dernières étapes cumulent trop de dénivelé
    Alors je vois une alerte de fatigue progressive sur les dernières étapes

  @desktop
  Scénario: Alerte de POI culturel remarquable
    Quand une étape passe près d'un site touristique majeur
    Alors je vois une notification de POI culturel sur cette étape

  @desktop
  Scénario: Aucune alerte sur un voyage équilibré
    Étant donné que toutes les étapes sont dans des limites raisonnables
    Alors aucune alerte critique n'est affichée

  @desktop
  Scénario: Les alertes sont triées par priorité
    Quand plusieurs alertes existent sur une même étape
    Alors elles s'affichent dans l'ordre de sévérité décroissante

  @desktop
  Plan du scénario: Seuils de difficulté par type d'alerte
    Quand l'étape a une distance de <distance> km et un dénivelé de <elevation> m
    Alors le niveau de difficulté est "<niveau>"

    Exemples:
      | distance | elevation | niveau |
      | 40       | 500       | Facile |
      | 65       | 1000      | Moyen  |
      | 90       | 2000      | Difficile |
