# language: fr
Fonctionnalité: Export GPX et FIT
  En tant que cycliste,
  je veux exporter mes étapes au format GPX et FIT,
  afin de les charger sur mon GPS ou application de navigation.

  Contexte:
    Étant donné que j'ai créé un voyage complet avec 3 étapes

  @desktop @critique
  Scénario: Bouton GPX activé après le calcul des étapes
    Alors le bouton "Télécharger le GPX" de l'étape 1 est actif

  @desktop @critique
  Scénario: Téléchargement GPX d'une étape déclenche l'appel API
    Quand je clique sur "Télécharger le GPX" de l'étape 1
    Alors une requête GET vers /trips/*/stages/0.gpx est envoyée

  @desktop @critique
  Scénario: Bouton GPX global visible après le calcul des étapes
    Alors le bouton "Télécharger le GPX complet" est visible et actif

  @desktop @critique
  Scénario: Téléchargement GPX global déclenche l'appel API du voyage
    Quand je clique sur "Télécharger le GPX complet"
    Alors une requête GET vers /trips/*.gpx est envoyée

  @desktop @critique @fixme
  Scénario: Upload d'un fichier GPX depuis l'ordinateur
    Quand je clique sur le bouton "Importer un GPX"
    Et que je sélectionne un fichier GPX valide
    Alors le voyage est créé à partir du fichier GPX

  @desktop
  Scénario: Rejet d'un fichier GPX invalide
    Quand je tente d'importer un fichier non-GPX
    Alors un message d'erreur s'affiche

  @desktop @critique
  Scénario: Téléchargement FIT d'une étape
    Quand je clique sur "Télécharger le FIT" de l'étape 1
    Alors une requête GET vers /trips/*/stages/0.fit est envoyée

  @desktop
  Scénario: Bouton FIT désactivé pendant le calcul
    Quand le calcul des étapes est en cours
    Alors le bouton FIT de l'étape 1 est désactivé

  @desktop
  Plan du scénario: Export par format de fichier
    Quand je clique sur le bouton export de format "<format>" de l'étape 1
    Alors le fichier téléchargé a l'extension ".<extension>"

    Exemples:
      | format | extension |
      | GPX    | gpx       |
      | FIT    | fit       |
