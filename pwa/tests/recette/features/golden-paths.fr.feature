# language: fr
Fonctionnalité: Parcours complets de bout en bout
  En tant que cycliste,
  je veux dérouler un parcours complet de planification depuis l'import jusqu'au partage,
  afin de valider que toutes les briques fonctionnent ensemble sur les sources Komoot, Strava et GPX.

  @desktop @critique @golden-path-a
  Scénario: Golden Path A — import Komoot et calcul des étapes
    Étant donné que je suis sur la page d'accueil
    Quand je soumets un lien Komoot valide
    Et que l'événement route_parsed est reçu
    Alors je suis redirigé vers la page du voyage
    Et la distance totale affiche "187km"
    Et le dénivelé total affiche "2850m"
    Quand les événements route_parsed et stages_computed sont reçus
    Alors je vois la carte de l'étape 1
    Et je vois la carte de l'étape 2
    Et je vois la carte de l'étape 3

  @desktop @critique @golden-path-a
  Scénario: Golden Path A — carte et profil altimétrique du parcours Komoot
    Étant donné que j'ai créé un voyage avec des étapes contenant des données géométriques
    Alors le panneau carte est visible
    Et le profil altimétrique est visible

  @desktop @critique @golden-path-a
  Scénario: Golden Path A — dates, profil cyclo touring et mode VAE
    Étant donné que j'ai créé un voyage complet avec 3 étapes
    Quand j'ouvre le sélecteur de dates
    Et que je sélectionne le 19 juin 2026 comme date de départ
    Et que j'ouvre le panneau de paramètres
    Et que je modifie la distance maximale à 70 km
    Alors les étapes sont recalculées en tenant compte de cette limite
    Quand j'active le mode e-bike
    Alors les calculs tiennent compte d'une vitesse plus élevée

  @desktop @critique @golden-path-a
  Scénario: Golden Path A — jour de repos décalant les dates
    Étant donné que j'ai créé un voyage complet avec 3 étapes
    Quand je définis le 15 juin 2026 comme date de départ
    Et qu'un jour de repos est ajouté après l'étape 1
    Alors je vois un indicateur de jour de repos entre l'étape 1 et l'étape 2

  @desktop @critique @golden-path-a
  Scénario: Golden Path A — sélection d'un hébergement recalcule le point d'arrivée
    Étant donné que j'ai créé un voyage complet avec 3 étapes
    Et que des hébergements ont été trouvés pour les étapes 1 et 2
    Quand je sélectionne l'hébergement "Hotel du Pont" de l'étape 1
    Alors l'hébergement "Hotel du Pont" est marqué comme sélectionné pour l'étape 1

  @desktop @critique @golden-path-a
  Scénario: Golden Path A — export texte et GPX global
    Étant donné que j'ai créé un voyage complet avec un lien de partage actif
    Quand j'ouvre la modale de partage
    Et que je clique sur "Copier le texte"
    Alors le texte résumé contenant le titre du voyage est copié
    Quand j'appuie sur Échap
    Et je clique sur "Télécharger le GPX complet"
    Alors une requête GET vers /trips/*.gpx est envoyée

  @desktop @critique @golden-path-a
  Scénario: Golden Path A — partage public puis révocation du lien
    Étant donné que j'ai créé un voyage complet avec un lien de partage actif
    Quand je clique sur le bouton de partage
    Alors la modale de partage s'affiche
    Et je vois le lien de partage court
    Quand je révoque le lien
    Alors le lien de partage n'est plus visible
    Et le bouton "Créer un lien de partage" s'affiche

  @desktop @critique @connecte @golden-path-a
  Scénario: Golden Path A — duplication puis reconsultation du voyage
    Étant donné que je suis connecté et que j'ai un voyage sauvegardé
    Quand je duplique ce voyage
    Alors un nouveau voyage identique apparaît dans ma liste

  @desktop @critique @golden-path-b
  Scénario: Golden Path B — import Strava et calcul des étapes
    Étant donné que je suis sur la page d'accueil
    Quand je soumets le lien "https://www.strava.com/routes/12345"
    Et que l'événement route_parsed est reçu
    Alors je suis redirigé vers la page du voyage
    Et la distance totale affiche "187km"
    Et le dénivelé total affiche "2850m"
    Quand les événements route_parsed et stages_computed sont reçus
    Alors je vois la carte de l'étape 1
    Et je vois la carte de l'étape 2
    Et je vois la carte de l'étape 3

  @desktop @critique @golden-path-b
  Scénario: Golden Path B — configuration et profil altimétrique du parcours Strava
    Étant donné que je crée un voyage complet depuis "https://www.strava.com/routes/12345"
    Et que les étapes calculées contiennent un tracé géométrique
    Alors le panneau carte est visible
    Et le profil altimétrique est visible
    Quand j'ouvre le panneau de paramètres
    Et que je modifie la vitesse moyenne à 20 km/h
    Alors les temps de trajet sont recalculés

  @desktop @critique @golden-path-b
  Scénario: Golden Path B — export GPX global du parcours Strava
    Étant donné que je crée un voyage complet depuis "https://www.strava.com/routes/12345"
    Alors le bouton "Télécharger le GPX complet" est visible et actif
    Quand je clique sur "Télécharger le GPX complet"
    Alors une requête GET vers /trips/*.gpx est envoyée

  @desktop @critique @golden-path-c
  Scénario: Golden Path C — import par fichier GPX et calcul des étapes
    Étant donné que je crée un voyage en important un fichier GPX
    Et que les événements route_parsed et stages_computed sont reçus
    Alors je vois la carte de l'étape 1
    Et je vois la carte de l'étape 2
    Et je vois la carte de l'étape 3

  @desktop @critique @golden-path-c
  Scénario: Golden Path C — carte, profil et export du parcours GPX
    Étant donné que je crée un voyage en important un fichier GPX
    Et que les événements route_parsed et stages_computed sont reçus
    Et que les étapes calculées contiennent un tracé géométrique
    Alors le panneau carte est visible
    Et le profil altimétrique est visible
    Et le bouton "Télécharger le GPX" de l'étape 1 est actif
