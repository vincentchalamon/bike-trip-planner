# language: fr
Fonctionnalité: Mobile et mode hors ligne
  En tant que cycliste en déplacement,
  je veux utiliser l'application sur mobile et sans connexion internet,
  afin de consulter mon itinéraire même dans des zones sans réseau.

  Contexte:
    Étant donné que j'utilise l'application sur un appareil mobile

  @mobile @critique
  Scénario: Bandeau hors ligne non visible quand connecté
    Alors le bandeau hors ligne n'est pas visible

  @mobile @critique
  Scénario: Bandeau hors ligne visible lors de la perte de connexion
    Quand la connexion internet est perdue
    Alors le bandeau hors ligne est visible
    Et il contient le texte "Hors ligne"

  @mobile @critique
  Scénario: Attributs ARIA du bandeau hors ligne
    Quand la connexion internet est perdue
    Alors le bandeau hors ligne a role="status" et aria-live="polite"

  @mobile @critique
  Scénario: Bandeau de reconnexion après retour en ligne
    Quand la connexion internet est perdue
    Et que la connexion est rétablie
    Alors le bandeau affiche "Connexion rétablie"

  @mobile
  Scénario: Bandeau de reconnexion disparaît automatiquement après 3s
    Quand la connexion internet est perdue
    Et que la connexion est rétablie
    Et que 3 secondes s'écoulent
    Alors le bandeau hors ligne n'est plus visible

  @mobile @critique
  Scénario: Saisie du lien magique désactivée hors ligne
    Quand la connexion internet est perdue
    Alors le champ de saisie du lien magique est désactivé

  @mobile @critique
  Scénario: Bouton d'import GPX désactivé hors ligne
    Quand la connexion internet est perdue
    Alors le bouton d'import GPX est désactivé

  @mobile @critique
  Scénario: Saisie réactivée après retour en ligne
    Quand la connexion internet est perdue
    Et que la connexion est rétablie
    Alors le champ de saisie est à nouveau actif

  @mobile @critique
  Scénario: Voyage sauvegardé dans IndexedDB après trip_complete
    Quand un voyage complet est créé
    Alors le voyage est sauvegardé localement dans IndexedDB

  @mobile @critique
  Scénario: Consultation hors ligne d'un voyage précédemment sauvegardé
    Étant donné qu'un voyage a été précédemment sauvegardé localement
    Quand la connexion internet est perdue
    Et que j'ouvre ce voyage
    Alors je peux consulter les étapes du voyage

  @mobile
  Scénario: Interface responsive sur écran 390px
    Quand je redimensionne la fenêtre à 390px de largeur
    Alors l'interface s'adapte correctement sans défilement horizontal

  @mobile
  Scénario: Gestes de balayage sur la carte
    Quand je fais glisser la carte avec un doigt
    Alors la carte se déplace en suivant le geste
