# language: fr
Fonctionnalité: Visualisation cartographique
  En tant que cycliste,
  je veux visualiser mon itinéraire sur une carte interactive avec profil altimétrique,
  afin de comprendre visuellement le parcours et les dénivelés.

  Contexte:
    Étant donné que j'ai créé un voyage avec des étapes contenant des données géométriques

  @desktop @critique
  Scénario: Le panneau carte n'est pas visible avant le chargement d'un voyage
    Étant donné que je suis sur la page d'accueil
    Alors le panneau carte n'est pas visible

  @desktop @critique
  Scénario: Le panneau carte s'affiche après le calcul des étapes
    Alors le panneau carte est visible

  @desktop @critique
  Scénario: La vue carte est affichée dans le panneau
    Alors la vue MapLibre est visible dans le panneau carte

  @desktop @critique
  Scénario: Le profil altimétrique s'affiche avec les données de géométrie
    Alors le profil altimétrique est visible

  @desktop
  Scénario: Le profil altimétrique est masqué sans données de géométrie
    Étant donné que les étapes n'ont pas de données de géométrie
    Alors le profil altimétrique n'est pas visible

  @desktop
  Scénario: Réticule et info-bulle au survol du profil altimétrique
    Quand je survole le profil altimétrique
    Alors le réticule vertical est visible
    Et l'info-bulle altimétrique est affichée

  @desktop
  Scénario: Bouton "Vue globale" absent en vue globale
    Alors le bouton "Réinitialiser la vue" n'est pas visible

  @desktop
  Scénario: Bouton "Vue globale" apparu quand une étape est ciblée
    Quand je sélectionne l'étape 1 sur la carte
    Alors le bouton "Réinitialiser la vue" est visible

  @desktop
  Scénario: Retour à la vue globale via le bouton reset
    Quand je sélectionne l'étape 1 sur la carte
    Et que je clique sur "Réinitialiser la vue"
    Alors la vue revient à l'ensemble du parcours

  @desktop
  Scénario: Bascule entre vue carte seule et vue splitée
    Quand je clique sur le bouton de mode "carte seule"
    Alors je vois uniquement le panneau carte
    Quand je clique sur le bouton de mode "vue splitée"
    Alors je vois les deux panneaux côte à côte

  @desktop
  Scénario: Couleur différente par étape sur la carte
    Alors chaque étape est représentée avec une couleur distincte sur la carte

  @mobile @critique
  Scénario: Carte responsive sur mobile
    Quand je consulte le voyage sur un écran mobile
    Alors la carte s'adapte à la taille de l'écran
