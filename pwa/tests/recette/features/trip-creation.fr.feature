# language: fr
Fonctionnalité: Création de voyage
  En tant que cycliste,
  je veux créer un voyage à partir d'un lien Komoot, Strava ou RideWithGPS,
  afin d'obtenir un plan d'étapes calculé automatiquement.

  Contexte:
    Étant donné que je suis sur la page d'accueil

  @desktop @critique
  Scénario: Affichage du champ de saisie du lien magique
    Alors je vois un champ de saisie avec le placeholder "Collez votre lien Komoot, Strava ou RideWithGPS ici..."

  @desktop @critique
  Scénario: Erreur de validation pour une URL invalide
    Quand je saisis "pas-une-url" dans le champ de lien magique
    Et que j'appuie sur Entrée
    Alors je vois le message d'erreur "Veuillez entrer une URL valide."

  @desktop
  Scénario: L'erreur disparaît quand je recommence à taper
    Quand je saisis "pas-une-url" dans le champ de lien magique
    Et que j'appuie sur Entrée
    Et que je saisis "https://" dans le champ de lien magique
    Alors je ne vois plus le message d'erreur

  @desktop @critique
  Scénario: Création du voyage sur une URL Komoot valide
    Quand je soumets un lien Komoot valide
    Alors je suis redirigé vers la page du voyage
    Et je vois le titre du voyage ou son squelette de chargement

  @desktop @critique
  Scénario: Affichage de la distance totale après le calcul des étapes
    Quand je soumets un lien Komoot valide
    Et que l'événement route_parsed est reçu
    Alors la distance totale affiche "187km"

  @desktop @critique
  Scénario: Affichage du dénivelé total après le calcul des étapes
    Quand je soumets un lien Komoot valide
    Et que l'événement route_parsed est reçu
    Alors le dénivelé total affiche "2850m"

  @desktop @critique
  Scénario: Affichage de 3 cartes d'étapes après stages_computed
    Quand je soumets un lien Komoot valide
    Et que les événements route_parsed et stages_computed sont reçus
    Alors je vois la carte de l'étape 1
    Et je vois la carte de l'étape 2
    Et je vois la carte de l'étape 3

  @desktop
  Scénario: Soumission automatique lors du collage d'une URL valide
    Quand je colle l'URL "https://www.komoot.com/fr-fr/tour/12345" dans le champ de lien magique
    Alors je suis redirigé vers la page du voyage

  @desktop
  Scénario: Création automatique via le paramètre ?link=
    Quand je navigue vers "/?link=https%3A%2F%2Fwww.komoot.com%2Ffr-fr%2Ftour%2F2795080048"
    Alors je suis redirigé vers la page du voyage

  @desktop
  Scénario: Paramètre ?link= invalide ignoré silencieusement
    Quand je navigue vers "/?link=url-invalide"
    Alors je reste sur la page d'accueil
    Et je vois le champ de saisie du lien magique

  @desktop
  Scénario: Affichage des villes de départ et d'arrivée après géocodage
    Quand je soumets un lien Komoot valide
    Et que les événements route_parsed et stages_computed sont reçus
    Alors l'étape 1 affiche "Aubenas" comme point de départ
    Et l'étape 1 affiche "Vals-les-Bains" comme point d'arrivée

  @desktop
  Plan du scénario: Création de voyage depuis différentes sources
    Quand je soumets le lien "<lien>"
    Alors je suis redirigé vers la page du voyage

    Exemples:
      | lien                                         |
      | https://www.komoot.com/fr-fr/tour/2795080048 |
      | https://www.strava.com/routes/12345          |
      | https://ridewithgps.com/routes/12345         |
