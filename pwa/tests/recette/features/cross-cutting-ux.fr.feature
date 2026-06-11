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

  @desktop @onboarding
  Scénario: Le tour d'onboarding s'affiche au premier lancement
    Étant donné que le tour d'onboarding est actif au premier lancement
    Alors le popover du tour d'onboarding est visible

  @desktop @onboarding
  Scénario: La première étape cible le champ de lien magique
    Étant donné que le tour d'onboarding est actif au premier lancement
    Alors le popover du tour d'onboarding est visible
    Et l'étape du tour cible la carte de lien magique

  @desktop @onboarding
  Scénario: La deuxième étape cible le bouton d'import GPX
    Étant donné que le tour d'onboarding est actif au premier lancement
    Quand j'avance à l'étape suivante du tour
    Alors l'étape du tour cible la carte d'import GPX

  @desktop @onboarding
  Scénario: Fermeture du tour d'onboarding par Échap
    Étant donné que le tour d'onboarding est actif au premier lancement
    Quand j'appuie sur Échap
    Alors le popover du tour d'onboarding n'est plus visible

  @desktop @onboarding
  Scénario: Fermeture du tour d'onboarding par clic sur l'overlay
    Étant donné que le tour d'onboarding est actif au premier lancement
    Quand je clique sur l'overlay du tour d'onboarding
    Alors le popover du tour d'onboarding n'est plus visible

  @desktop @onboarding
  Scénario: Le tour ne réapparaît plus après fermeture
    Étant donné que le tour d'onboarding a déjà été vu
    Quand je recharge la page
    Alors le popover du tour d'onboarding n'est plus visible

  @desktop @sombre
  Scénario: Le bouton de thème bascule entre clair et sombre
    Quand je bascule vers le thème clair
    Et que je clique sur le bouton de thème
    Alors le thème sélectionné est "dark"
    Quand je clique sur le bouton de thème
    Alors le thème sélectionné est "light"

  @desktop @sombre
  Scénario: Par défaut, le thème suit la préférence du système d'exploitation
    Étant donné que le système d'exploitation préfère le mode sombre
    Quand je recharge la page
    Alors l'interface s'affiche avec un fond sombre

  @desktop @sombre
  Scénario: Le choix de thème est persisté après rechargement
    Quand je bascule vers le thème sombre
    Et que je recharge la page
    Alors l'interface s'affiche avec un fond sombre

  @mobile
  Scénario: Labels de langue compacts sur écran étroit
    Étant donné que j'affiche l'application sur un écran étroit
    Alors le label compact de langue "FR" est visible

  @mobile
  Scénario: Permutation de langue sur mobile
    Étant donné que j'utilise l'application sur un appareil mobile
    Quand je change la langue vers "English"
    Alors l'interface s'affiche en anglais

  @desktop
  Scénario: Le choix de langue est persisté après rechargement
    Quand je change la langue vers "English"
    Et que je recharge la page
    Alors l'interface s'affiche en anglais
