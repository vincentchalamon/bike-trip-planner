# language: fr
Fonctionnalité: Partage de voyage
  En tant que cycliste,
  je veux partager mon voyage avec d'autres personnes,
  afin qu'elles puissent consulter mon itinéraire sans se connecter.

  Contexte:
    Étant donné que j'ai créé un voyage complet avec un lien de partage actif

  @desktop @critique
  Scénario: Bouton de partage ouvre la modale avec le lien
    Quand je clique sur le bouton de partage
    Alors la modale de partage s'affiche
    Et je vois le lien de partage court

  @desktop @critique
  Scénario: Copie du lien de partage dans le presse-papiers
    Quand j'ouvre la modale de partage
    Et que je clique sur "Copier le lien"
    Alors le lien court est copié dans le presse-papiers

  @desktop @critique
  Scénario: Révocation du lien masque le lien et affiche le bouton de création
    Quand j'ouvre la modale de partage
    Et que je clique sur "Révoquer le lien"
    Alors le lien de partage n'est plus visible
    Et le bouton "Créer un lien de partage" s'affiche

  @desktop @critique
  Scénario: Re-création d'un lien après révocation
    Quand j'ouvre la modale de partage
    Et que je révoque le lien
    Et que je clique sur "Créer un lien de partage"
    Alors un nouveau lien de partage est généré

  @desktop @critique
  Scénario: Bouton "Créer un lien" quand aucun lien actif n'existe
    Étant donné qu'aucun lien de partage n'est actif
    Quand j'ouvre la modale de partage
    Alors je vois le bouton "Créer un lien de partage"
    Et le lien n'est pas encore visible

  @desktop
  Scénario: Téléchargement de l'infographie PNG
    Quand j'ouvre la modale de partage
    Et que je clique sur "Télécharger l'infographie"
    Alors un fichier PNG est téléchargé

  @desktop
  Scénario: Copie du texte résumé du voyage
    Quand j'ouvre la modale de partage
    Et que je clique sur "Copier le texte"
    Alors le texte résumé contenant le titre du voyage est copié

  @desktop
  Scénario: Page de partage publique accessible sans connexion
    Étant donné que je ne suis pas connecté
    Quand j'accède à /s/<code_court>
    Alors je vois le résumé du voyage partagé
