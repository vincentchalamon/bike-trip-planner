# language: fr
Fonctionnalité: Recherche guidée en route
  En tant que cycliste sur la route,
  je veux toucher une question prédéfinie et lire quelques résultats à proximité,
  afin de trouver de l'eau, un abri ou un réparateur sans rien taper.

  Contexte:
    Étant donné que j'ai créé un voyage complet avec 3 étapes

  @desktop @critical
  Scénario: Ouvrir le panneau en route affiche les huit puces de questions
    Quand j'ouvre le panneau en route
    Alors les huit puces de questions en route sont visibles

  @desktop @critical
  Scénario: Trouver de l'eau à proximité
    Étant donné que j'ai partagé ma position
    Quand j'ouvre le panneau en route
    Et je touche la puce de question en route "water"
    Alors un récapitulatif en route est affiché
    Et des cartes de POI en route à proximité sont affichées

  @desktop
  Scénario: Élargir la recherche
    Étant donné que j'ai partagé ma position
    Quand j'ouvre le panneau en route
    Et je touche la puce de question en route "water"
    Et j'élargis la recherche en route
    Alors un récapitulatif en route est affiché

  @desktop
  Scénario: La bulle est désactivée hors ligne
    Quand l'application passe hors ligne
    Alors la bulle en route est désactivée
    Et le badge hors ligne en route est visible
