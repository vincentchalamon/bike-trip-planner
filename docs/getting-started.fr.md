# Démarrage rapide

*[English version](getting-started.md)*

Ce guide vous accompagne dans l'installation, la configuration et le lancement de Bike Trip Planner sur votre machine locale.

---

## Prérequis

Assurez-vous que les outils suivants sont installés avant de continuer.

| Outil | Version minimale | Utilisation |
|-------|------------------|-------------|
| Docker | 24+ | Exécute tous les services (PHP, Node) |
| Docker Compose | 2.20+ | Orchestre l'environnement multi-conteneurs |
| Git | 2.40+ | Gestion de version |
| Make | 4+ | Lanceur de tâches (encapsule les commandes Docker Compose) |

> **Note :** Aucune installation locale de PHP ou Node.js n'est nécessaire. Tous les runtimes s'exécutent dans des conteneurs.

Vérifiez votre configuration :

```bash
docker --version
docker compose version
make --version
```

---

## Installation

Clonez le dépôt :

```bash
git clone <repo-url> bike-trip-planner
cd bike-trip-planner
```

Démarrez l'application en mode production :

```bash
make start
```

Cela démarre plusieurs services :

| Service | URL | Description |
|---------|-----|-------------|
| `php` | `https://localhost/docs` | Backend API Platform (FrankenPHP : reverse-proxy Caddy + hub Mercure intégré) |
| `pwa` | `https://localhost` | Frontend Next.js |
| `worker` | Interne uniquement | Worker de messages asynchrones (×5) |
| `redis` | Interne uniquement | Cache et transport Messenger |
| `database` | Interne uniquement | Stockage persistant PostgreSQL 18 |
| `valhalla` | Interne uniquement | Moteur de routage Valhalla |

> **TLS :** Caddy génère un certificat auto-signé pour `localhost`. Acceptez l'avertissement du navigateur au premier chargement, ou installez le certificat dans le magasin de confiance de votre système.

L'application est prête lorsque tous les services sont sains.

> **Comptes & IA :** la connexion est sans mot de passe — vous recevez un magic link par email. Les fonctionnalités IA optionnelles (résumés par étape et global, assistant conversationnel) nécessitent le service `ollama` ; lorsqu'il est indisponible, les résumés IA sont simplement masqués et toutes les alertes restent disponibles.

---

## Vérifier l'installation

Ouvrez `https://localhost` dans votre navigateur. Vous devriez voir la page d'accueil de Bike Trip Planner.

Pour vérifier que l'API répond :

```bash
curl -k https://localhost/docs.json | head -20
```

Vous devriez recevoir un document JSON OpenAPI.

---

## Tâches courantes

```bash
make start         # Démarrer tous les conteneurs (mode iso-prod)
make start-dev     # Démarrer en mode développement (hot reload ; tier IA désactivé par défaut)
make start-recette # Iso-prod + Mailcatcher + Ollama (IA activée) pour la recette
make stop          # Arrêter tous les conteneurs (conserve les données)
make clean         # Arrêter tous les conteneurs et effacer toutes les données (à utiliser avec précaution)
```

Consultez `make help` pour la liste complète des cibles disponibles.

> **Un seul projet, aucun ordre.** En local, chaque cible `make start*` démarre l'app et le tier IA Ollama dans un **seul** projet Compose : le réseau partagé `bike-trip-planner-llm` est créé une fois et tu n'as jamais à démarrer l'un avant l'autre. En production, le tier IA est une ressource *séparée* avec son propre modèle de démarrage — voir [deployment.md](deployment.md#llm-tier-ollama-and-the-shared-network).

---

## Dépannage

### Le port 443/80 est déjà utilisé

Un autre processus utilise le port HTTPS par défaut. Arrêtez-le ou surchargez le port :

```bash
# Trouver le processus en conflit
sudo lsof -i :443

# Ou définir HTTP_PORT / HTTPS_PORT dans votre .env
```

### Erreurs de certificat auto-signé dans le navigateur

Acceptez l'avertissement du certificat une seule fois.

### `make start` échoue avec des erreurs de permissions

Assurez-vous que Docker a accès au répertoire du projet. Sous Linux, vous devrez peut-être ajouter votre utilisateur au groupe `docker` :

```bash
sudo usermod -aG docker $USER
newgrp docker
```
