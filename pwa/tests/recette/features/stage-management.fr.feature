# language: fr
Fonctionnalité: Gestion des étapes
  En tant que cycliste,
  je veux gérer les étapes de mon voyage,
  afin de personnaliser ma planification jour par jour.

  Contexte:
    Étant donné que j'ai créé un voyage complet avec 3 étapes

  @desktop @critique
  Scénario: Affichage de la difficulté de chaque étape
    Alors la carte de l'étape 1 affiche le niveau de difficulté
    Et la carte de l'étape 2 affiche le niveau de difficulté
    Et la carte de l'étape 3 affiche le niveau de difficulté

  @desktop @critique
  Scénario: Édition du titre du voyage
    Quand je clique sur le titre du voyage
    Et que je saisis "Mon voyage en Ardèche"
    Et que j'appuie sur Entrée
    Alors le titre affiché est "Mon voyage en Ardèche"

  @desktop
  Scénario: Annulation de l'édition du titre par Échap
    Quand je clique sur le titre du voyage
    Et que je saisis "Titre temporaire"
    Et que j'appuie sur Échap
    Alors le titre n'a pas été modifié

  @desktop
  Scénario: Fusion de deux étapes (glisser-déposer)
    Quand je fusionne l'étape 1 avec l'étape 2
    Alors je ne vois plus que 2 cartes d'étapes

  @desktop
  Scénario: Division d'une étape
    Quand je divise l'étape 1 à mi-parcours
    Alors je vois 4 cartes d'étapes

  @desktop
  Scénario: Déplacement d'un point de fin d'étape sur la carte
    Quand je déplace le point de fin de l'étape 1 sur la carte
    Alors la distance de l'étape 1 est recalculée

  @desktop
  Scénario: Ajout d'un jour de repos entre deux étapes
    Quand j'ajoute un jour de repos après l'étape 1
    Alors je vois un indicateur de jour de repos entre l'étape 1 et l'étape 2

  @desktop
  Scénario: Suppression d'un jour de repos
    Étant donné qu'un jour de repos existe après l'étape 1
    Quand je supprime le jour de repos
    Alors il n'y a plus d'indicateur de jour de repos entre l'étape 1 et l'étape 2

  @desktop
  Scénario: Affichage de la durée de voyage estimée
    Alors je vois la durée totale du voyage en jours

  @desktop
  Scénario: Badge de difficulté correct selon la distance et le dénivelé
    Alors les badges de difficulté de toutes les étapes sont cohérents avec leurs valeurs

  @desktop
  Scénario: Undo/Redo d'une modification d'étape
    Quand je modifie une étape
    Et que j'annule l'action avec Ctrl+Z
    Alors l'étape est revenue à son état précédent
    Quand je rétablis l'action avec Ctrl+Y
    Alors l'étape est à nouveau modifiée

  @desktop
  Scénario: Barre de progression pendant le calcul
    Quand je soumets un lien Komoot valide
    Alors je vois une barre de progression pendant le calcul des étapes
