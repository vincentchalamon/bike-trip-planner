# language: fr
Fonctionnalité: Gestion des voyages
  En tant que cycliste,
  je veux gérer mes voyages sauvegardés,
  afin de retrouver, dupliquer ou supprimer mes planifications.

  @desktop @connecte @critique
  Scénario: Affichage de la liste de mes voyages
    Étant donné que je suis connecté et que j'ai 3 voyages sauvegardés
    Quand je navigue vers la page d'accueil
    Alors je vois la liste de mes 3 voyages

  @desktop @connecte @critique
  Scénario: Accès à un voyage depuis la liste
    Étant donné que je suis connecté et que j'ai un voyage sauvegardé
    Quand je clique sur ce voyage dans la liste
    Alors je suis redirigé vers la page détail du voyage

  @desktop @connecte @critique
  Scénario: Duplication d'un voyage
    Étant donné que je suis connecté et que j'ai un voyage sauvegardé
    Quand je duplique ce voyage
    Alors un nouveau voyage identique apparaît dans ma liste

  @desktop @connecte @critique
  Scénario: Suppression d'un voyage
    Étant donné que je suis connecté et que j'ai un voyage sauvegardé
    Quand je supprime ce voyage
    Alors il n'apparaît plus dans ma liste

  @desktop @connecte
  Scénario: Voyage récent affiché sur la page d'accueil
    Étant donné que j'ai récemment consulté un voyage
    Quand je navigue vers la page d'accueil
    Alors je vois le voyage récent dans la section "Récents"

  @desktop @connecte
  Scénario: Voyage verrouillé après modification par un autre utilisateur
    Étant donné qu'un voyage a été verrouillé par un autre utilisateur
    Quand j'ouvre ce voyage
    Alors je vois un indicateur de verrouillage
    Et les boutons de modification sont désactivés

  @desktop @connecte
  Scénario: Page vide quand aucun voyage n'existe
    Étant donné que je suis connecté sans voyage
    Quand je navigue vers la page d'accueil
    Alors je vois un état vide invitant à créer un voyage

  @desktop @connecte
  Scénario: Rechargement d'un voyage sans dates
    Étant donné que j'ai un voyage sans dates de départ ni d'arrivée
    Quand j'ouvre ce voyage
    Alors les étapes s'affichent correctement sans dates

  @desktop
  Scénario: Indicateur de chargement pendant la récupération des voyages
    Quand la liste des voyages est en cours de chargement
    Alors je vois un indicateur de chargement
