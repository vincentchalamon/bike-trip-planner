# language: fr
Fonctionnalité: Page d'atterrissage
  En tant que visiteur non connecté,
  je veux découvrir le produit sur la page d'accueil publique,
  afin de comprendre la proposition de valeur et de demander l'accès.

  Contexte:
    Étant donné que je consulte la page d'atterrissage publique

  @desktop @critique
  Scénario: Section héros avec titre et appels à l'action
    Alors la section héros est visible
    Et l'appel à l'action "Créer un itinéraire" est visible
    Et le bouton de démonstration est visible

  @desktop
  Scénario: Section "Comment ça marche"
    Alors la section "Comment ça marche" est visible

  @desktop
  Scénario: Bento des avantages
    Alors la section des avantages est visible
    Et la grille bento est visible

  @desktop @critique
  Scénario: Sources supportées affichées
    Alors la section des sources est visible
    Et la source "komoot" est affichée
    Et la source "strava" est affichée
    Et la source "ridewithgps" est affichée
    Et la source "gpx" est affichée

  @desktop
  Scénario: Section plateformes
    Alors la section des plateformes est visible

  @desktop
  Scénario: Témoignages et cas d'usage
    Alors la section des témoignages est visible

  @desktop @critique
  Scénario: Footer avec liens légaux
    Alors le footer est visible
    Et le lien GitHub du footer est visible
    Et le lien légal du footer est visible
    Et le lien de confidentialité du footer est visible

  @desktop
  Scénario: Appel à l'action redirige vers la connexion quand non connecté
    Alors le bouton "Créer un itinéraire" pointe vers "/login"

  @mobile @critique
  Scénario: Page d'atterrissage responsive sur mobile
    Étant donné que je consulte la page d'atterrissage publique sur mobile
    Alors la page d'atterrissage est visible
    Et la section héros est visible
    Et l'appel à l'action "Créer un itinéraire" est visible
