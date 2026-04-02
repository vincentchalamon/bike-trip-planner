# language: fr
Fonctionnalité: Hébergements
  En tant que cycliste,
  je veux voir et gérer les hébergements disponibles à chaque étape,
  afin de planifier mes nuits sans avoir à chercher manuellement.

  Contexte:
    Étant donné que j'ai créé un voyage complet avec 3 étapes
    Et que des hébergements ont été trouvés pour les étapes 1 et 2

  @desktop @critique
  Scénario: Affichage des hébergements suggérés sur une carte d'étape
    Alors la carte de l'étape 1 affiche "Camping Les Oliviers"
    Et la carte de l'étape 1 affiche "Hotel du Pont"

  @desktop @critique
  Scénario: Affichage du type d'hébergement
    Alors la carte de l'étape 1 affiche le label "Camping"
    Et la carte de l'étape 1 affiche le label "Hôtel"

  @desktop @critique
  Scénario: Affichage de la distance à l'hébergement
    Alors la carte de l'étape 1 affiche la distance "1.2 km"
    Et la carte de l'étape 1 affiche la distance "0.5 km"

  @desktop @critique
  Scénario: Ajout manuel d'un hébergement
    Quand je clique sur "Ajouter un hébergement" sur la carte de l'étape 1
    Alors le formulaire d'ajout d'hébergement s'affiche

  @desktop @critique
  Scénario: Suppression d'un hébergement
    Quand je supprime "Hotel du Pont" sur la carte de l'étape 1
    Alors "Hotel du Pont" n'apparaît plus sur la carte de l'étape 1

  @desktop
  Scénario: Pas de panneau hébergement sur la dernière étape
    Alors la carte de la dernière étape n'affiche pas le bouton "Ajouter un hébergement"

  @desktop
  Scénario: Message "aucun hébergement trouvé" avec rayon de recherche
    Quand aucun hébergement n'est trouvé dans un rayon de 5 km pour l'étape 1
    Alors je vois un message indiquant un rayon de 5 km
    Et je vois un bouton pour élargir à 7 km

  @desktop
  Scénario: Bouton d'élargissement du rayon de recherche visible
    Quand des hébergements sont trouvés dans un rayon de 5 km
    Alors je vois un bouton pour élargir à 7 km

  @desktop
  Scénario: Bouton d'élargissement masqué au rayon maximum
    Quand des hébergements sont trouvés dans un rayon de 15 km
    Alors le bouton d'élargissement à 17 km n'est pas visible

  @desktop
  Scénario: Élargissement du rayon déclenche un nouveau scan
    Quand aucun hébergement n'est trouvé dans un rayon de 5 km
    Et que je clique sur "Élargir à 7 km"
    Alors une requête de scan avec radiusKm=7 est envoyée

  @desktop
  Scénario: Badge de distance masqué pour les hébergements sur place
    Quand un hébergement est exactement sur le point d'arrivée
    Alors aucun badge de distance n'est affiché pour cet hébergement
