# language: fr
Fonctionnalité: Météo et temps de voyage
  En tant que cycliste,
  je veux voir la météo prévisionnelle et les temps de trajet par étape,
  afin de planifier mes journées de vélo de manière réaliste.

  Contexte:
    Étant donné que j'ai créé un voyage complet avec 3 étapes

  @desktop @critique
  Scénario: Affichage de la météo sur les cartes d'étapes
    Alors la carte de l'étape 1 affiche les conditions météo
    Et la carte de l'étape 2 affiche les conditions météo

  @desktop @critique
  Scénario: Affichage de la température min-max
    Alors je vois la plage de températures "14-26°C" sur l'étape 1

  @desktop @critique
  Scénario: Affichage du temps de trajet estimé
    Alors chaque carte d'étape affiche un temps de trajet estimé

  @desktop
  Scénario: Affichage de l'heure d'arrivée prévue
    Quand l'heure de départ est configurée à 8h00
    Alors je vois l'heure d'arrivée prévue sur chaque étape

  @desktop
  Scénario: Alerte météo si température inférieure à 5°C
    Quand la météo de l'étape 1 prévoit des températures sous 5°C
    Alors je vois une alerte de froid sur l'étape 1

  @desktop
  Scénario: Alerte météo si précipitations importantes
    Quand la météo de l'étape 2 prévoit plus de 10mm de pluie
    Alors je vois une alerte pluie sur l'étape 2

  @desktop
  Scénario: Affichage de l'icône météo correspondante
    Alors chaque étape affiche une icône météo correspondant aux conditions

  @desktop
  Scénario: Recalcul du temps de trajet selon la vitesse configurée
    Quand je modifie la vitesse moyenne à 20 km/h dans les paramètres
    Alors les temps de trajet de toutes les étapes sont mis à jour

  @desktop
  Scénario: Prise en compte du facteur de fatigue dans le rythme
    Quand le facteur de fatigue est activé
    Alors la distance cible des étapes diminue progressivement

  @desktop
  Scénario: Mode e-bike — vitesse plus élevée
    Quand le mode e-bike est activé
    Alors les temps de trajet sont recalculés avec une vitesse supérieure
