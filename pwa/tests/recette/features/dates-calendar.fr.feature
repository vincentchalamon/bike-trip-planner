# language: fr
Fonctionnalité: Dates et calendrier
  En tant que cycliste,
  je veux définir et visualiser les dates de mon voyage sur un calendrier,
  afin de planifier mes départs et arrivées à des dates précises.

  Contexte:
    Étant donné que j'ai créé un voyage complet avec 3 étapes

  @desktop @critique
  Scénario: Sélection d'une date de départ
    Quand j'ouvre le sélecteur de dates
    Et que je sélectionne le 15 juin 2026 comme date de départ
    Alors la date de départ affichée est "15 juin 2026"

  @desktop @critique
  Scénario: Calcul automatique des dates d'arrivée par étape
    Quand je définis le 15 juin 2026 comme date de départ
    Alors l'étape 1 est prévue le 15 juin 2026
    Et l'étape 2 est prévue le 16 juin 2026
    Et l'étape 3 est prévue le 17 juin 2026

  @desktop @critique
  Scénario: Date d'arrivée décalée par un jour de repos
    Quand je définis le 15 juin 2026 comme date de départ
    Et qu'un jour de repos est ajouté après l'étape 1
    Alors l'étape 2 est prévue le 17 juin 2026

  @desktop
  Scénario: Affichage du calendrier des étapes
    Quand je définis une date de départ
    Alors le calendrier affiche toutes les étapes avec leurs dates

  @desktop
  Scénario: Affichage de la météo associée aux dates du voyage
    Quand je définis une date de départ dans les 7 prochains jours
    Alors les prévisions météo sont associées aux dates des étapes

  @desktop
  Scénario: Réinitialisation des dates
    Quand je définis une date de départ
    Et que je supprime la date de départ
    Alors les étapes n'affichent plus de dates

  @desktop
  Scénario: Dates non affichées sur un voyage sans dates
    Étant donné que le voyage n'a pas de date de départ
    Alors les cartes d'étapes n'affichent pas de dates

  @desktop
  Scénario: Navigation dans le calendrier vers le mois suivant
    Quand j'ouvre le calendrier
    Et que je navigue vers le mois suivant
    Alors le mois suivant est affiché
