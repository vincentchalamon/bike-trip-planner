# language: fr
Fonctionnalité: Authentification et sécurité
  En tant qu'utilisateur,
  je veux m'authentifier de manière sécurisée,
  afin d'accéder à mes voyages personnels.

  @desktop @critique
  Scénario: Page de connexion affiche le formulaire email
    Étant donné que je ne suis pas connecté
    Quand je navigue vers la page de connexion
    Alors je vois un champ email
    Et je vois le bouton "Recevoir un lien de connexion"

  @desktop @critique
  Scénario: Confirmation affichée après soumission du formulaire
    Étant donné que je ne suis pas connecté
    Quand je navigue vers la page de connexion
    Et que je saisis "test@example.com" dans le champ email
    Et que je clique sur "Recevoir un lien de connexion"
    Alors je vois le message de confirmation d'envoi

  @desktop @critique
  Scénario: Utilisateur non connecté voit la page d'accueil publique
    Étant donné que je ne suis pas connecté
    Quand je navigue vers la page d'accueil
    Alors je vois le formulaire d'accès anticipé

  @desktop @critique
  Scénario: Redirection vers l'accueil après vérification du token
    Étant donné que je ne suis pas connecté
    Quand je navigue vers /auth/verify/token-valide
    Alors je suis redirigé vers la page d'accueil

  @desktop
  Scénario: Déconnexion
    Étant donné que je suis connecté
    Quand je clique sur le bouton de déconnexion
    Alors je suis redirigé vers la page de connexion

  @desktop
  Scénario: Token JWT expiré — redirection vers la connexion
    Étant donné que ma session a expiré
    Quand je tente d'accéder à mes voyages
    Alors je suis redirigé vers /login

  @desktop
  Scénario: Pas de traces de pile visibles en cas d'erreur
    Quand une erreur serveur se produit
    Alors aucune trace de pile PHP n'est affichée à l'utilisateur

  @desktop
  Scénario: Headers de sécurité présents sur les réponses
    Quand je charge la page d'accueil
    Alors les headers CSP, HSTS et X-Frame-Options sont présents

  @desktop
  Scénario: URLs HTTPS uniquement
    Alors toutes les ressources chargées utilisent HTTPS

  @desktop @connecte
  Scénario: Isolation des voyages entre utilisateurs
    Étant donné que je suis connecté en tant qu'utilisateur A
    Quand je tente d'accéder au voyage de l'utilisateur B
    Alors j'obtiens une erreur 403 ou une page non trouvée
