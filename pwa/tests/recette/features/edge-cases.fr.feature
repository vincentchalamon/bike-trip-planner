# language: fr
Fonctionnalité: Cas limites et robustesse
  En tant que développeur et testeur,
  je veux vérifier que l'application gère correctement les cas limites,
  afin d'assurer une robustesse maximale en production.

  @desktop @critique
  Scénario: Gestion d'une erreur 500 de l'API
    Quand l'API renvoie une erreur 500 lors de la création du voyage
    Alors un message d'erreur compréhensible est affiché
    Et l'application reste utilisable

  @desktop @critique
  Scénario: Gestion d'une erreur réseau lors de la création du voyage
    Quand la connexion réseau est coupée lors de la soumission du lien
    Alors un message d'erreur est affiché
    Et l'application reste utilisable

  @desktop @critique
  Scénario: URL de source non supportée
    Quand je soumets "https://www.exemple.com/route/12345"
    Alors je vois un message indiquant que la source n'est pas supportée

  @desktop
  Scénario: Fichier GPX vide ou corrompu
    Quand j'importe un fichier GPX vide
    Alors un message d'erreur approprié s'affiche

  @desktop
  Scénario: Fichier GPX avec un seul point de trace
    Quand j'importe un fichier GPX avec un seul waypoint
    Alors un message expliquant que le fichier est insuffisant s'affiche

  @desktop
  Scénario: Voyage avec une seule étape
    Étant donné qu'un voyage ne comprend qu'une seule étape
    Quand je consulte ce voyage
    Alors la carte de l'étape 1 s'affiche correctement
    Et les boutons de fusion d'étape sont désactivés

  @desktop
  Scénario: Titre de voyage très long tronqué correctement
    Quand je saisis un titre de voyage de 200 caractères
    Alors le titre est tronqué correctement dans l'interface

  @desktop
  Scénario: Rechargement de page pendant le calcul en cours
    Quand je recharge la page pendant que le calcul des étapes est en cours
    Alors l'état du calcul est correctement récupéré

  @desktop
  Scénario: Navigation vers un voyage inexistant
    Quand je navigue vers "/trips/voyage-inexistant"
    Alors je vois une page 404 ou un message d'erreur

  @desktop
  Scénario: Plusieurs onglets ouverts sur le même voyage
    Étant donné que j'ai le voyage ouvert dans deux onglets
    Quand je modifie le voyage dans l'onglet 1
    Alors l'onglet 2 reflète le changement ou affiche un avertissement

  @desktop @performance
  Scénario: Import d'un fichier GPX de grande taille (15MB)
    Quand j'importe un fichier GPX de 15MB
    Alors l'import est traité en moins de 30 secondes
    Et aucune erreur de mémoire ne se produit

  @desktop
  Scénario: Comportement stable avec des données météo manquantes
    Quand les données météo ne sont pas disponibles pour une étape
    Alors les cartes d'étapes s'affichent correctement sans données météo
