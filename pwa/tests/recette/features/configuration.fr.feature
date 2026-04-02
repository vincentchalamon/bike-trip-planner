# language: fr
Fonctionnalité: Configuration et paramètres
  En tant que cycliste,
  je veux configurer les paramètres de mon voyage,
  afin d'adapter le calcul des étapes à mon niveau et mon matériel.

  Contexte:
    Étant donné que je suis sur la page du voyage avec les étapes calculées

  @desktop @critique
  Scénario: Ouverture du panneau de paramètres
    Quand je clique sur le bouton "Ouvrir les paramètres"
    Alors le panneau de paramètres s'affiche

  @desktop @critique
  Scénario: Fermeture du panneau via le bouton ✕
    Quand j'ouvre le panneau de paramètres
    Et que je clique sur le bouton "Fermer les paramètres"
    Alors le panneau de paramètres est fermé

  @desktop
  Scénario: Fermeture du panneau via clic hors de la zone
    Quand j'ouvre le panneau de paramètres
    Et que je clique en dehors du panneau
    Alors le panneau de paramètres est fermé

  @desktop
  Scénario: Fermeture du panneau via la touche Échap
    Quand j'ouvre le panneau de paramètres
    Et que j'appuie sur Échap
    Alors le panneau de paramètres est fermé

  @desktop @critique
  Scénario: Filtrage des types d'hébergement
    Quand j'ouvre le panneau de paramètres
    Alors je vois les interrupteurs pour les types "Hôtel", "Auberge", "Camping", "Gîte", "Chambre d'hôte", "Motel", "Refuge"

  @desktop @critique
  Scénario: Le dernier type d'hébergement activé ne peut pas être désactivé
    Quand j'ouvre le panneau de paramètres
    Et que je désactive tous les types d'hébergement sauf le dernier
    Alors le dernier interrupteur est désactivé et ne peut pas être modifié

  @desktop
  Scénario: Modification de la vitesse moyenne
    Quand j'ouvre le panneau de paramètres
    Et que je modifie la vitesse moyenne à 20 km/h
    Alors les temps de trajet sont recalculés

  @desktop
  Scénario: Modification de la distance maximale par jour
    Quand j'ouvre le panneau de paramètres
    Et que je modifie la distance maximale à 70 km
    Alors les étapes sont recalculées en tenant compte de cette limite

  @desktop
  Scénario: Activation du mode e-bike
    Quand j'ouvre le panneau de paramètres
    Et que j'active le mode e-bike
    Alors les calculs tiennent compte d'une vitesse plus élevée

  @desktop
  Scénario: Réglage de l'heure de départ
    Quand j'ouvre le panneau de paramètres
    Et que je règle l'heure de départ à 9h00
    Alors l'heure d'arrivée prévue est recalculée pour chaque étape
