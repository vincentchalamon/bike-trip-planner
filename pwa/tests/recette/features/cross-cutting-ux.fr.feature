# language: fr
Fonctionnalité: UX transverse
  En tant qu'utilisateur,
  je veux une expérience utilisateur cohérente et accessible,
  afin de naviguer dans l'application avec efficacité et confort.

  @desktop @critique
  Scénario: Raccourci clavier Ctrl+Z pour annuler
    Étant donné que j'ai effectué une modification d'étape
    Quand j'appuie sur Ctrl+Z
    Alors la modification est annulée

  @desktop @critique
  Scénario: Raccourci clavier Ctrl+Y pour rétablir
    Étant donné que j'ai annulé une modification
    Quand j'appuie sur Ctrl+Y
    Alors la modification est rétablie

  @desktop
  Scénario: Affichage du changement de locale FR vers EN
    Quand je change la langue vers "English"
    Alors l'interface s'affiche en anglais

  @desktop
  Scénario: Affichage du changement de locale EN vers FR
    Étant donné que l'interface est en anglais
    Quand je change la langue vers "Français"
    Alors l'interface s'affiche en français

  @desktop @sombre
  Scénario: Basculement vers le mode sombre
    Quand je bascule vers le thème sombre
    Alors l'interface s'affiche avec un fond sombre

  @desktop @sombre
  Scénario: Basculement vers le mode clair
    Étant donné que le thème sombre est activé
    Quand je bascule vers le thème clair
    Alors l'interface s'affiche avec un fond clair

  @desktop @critique
  Scénario: Message d'onboarding visible au premier lancement
    Étant donné que je suis un nouvel utilisateur
    Quand je navigue vers la page d'accueil
    Alors je vois le guide de démarrage

  @desktop
  Scénario: Fermeture de l'onboarding
    Étant donné que le guide de démarrage est visible
    Quand je le ferme
    Alors il n'est plus visible

  @desktop
  Scénario: Navigation clavier dans les formulaires
    Quand je navigue avec la touche Tab dans le formulaire
    Alors le focus se déplace correctement entre les champs

  @desktop
  Scénario: Toast de confirmation après une action réussie
    Quand j'effectue une action qui génère une notification
    Alors un toast de confirmation s'affiche brièvement

  @desktop @critique
  Scénario: Gestion de l'erreur réseau avec message utilisateur
    Quand l'API backend est indisponible
    Alors un message d'erreur compréhensible est affiché à l'utilisateur

  @desktop
  Scénario: Bouton "Retour en haut" sur les listes longues
    Étant donné que la liste d'étapes dépasse la hauteur de l'écran
    Quand je fais défiler vers le bas
    Alors un bouton "Retour en haut" apparaît
