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
| `php` | `https://localhost/docs` | Backend API Platform |
| `pwa` | `https://localhost` | Frontend Next.js |
| `worker` | Interne uniquement | Worker de messages asynchrones (×5) |
| `mercure` | Interne uniquement | Microservice de push serveur |
| `redis` | Interne uniquement | Cache et transport Messenger |
| `database` | Interne uniquement | Stockage persistant PostgreSQL 18 |
| `caddy` | Interne uniquement | Serveur web et reverse proxy |
| `overpass` | Interne uniquement | API OpenStreetMap Overpass |
| `valhalla` | Interne uniquement | Moteur de routage Valhalla |
| `mailcatcher` | `http://localhost:1080` | Capture d'emails (développement uniquement) |

> **TLS :** Caddy génère un certificat auto-signé pour `localhost`. Acceptez l'avertissement du navigateur au premier chargement, ou installez le certificat dans le magasin de confiance de votre système.

L'application est prête lorsque tous les services sont sains.

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
make start     # Démarrer tous les conteneurs
make stop      # Arrêter tous les conteneurs (conserve les données)
make clean     # Arrêter tous les conteneurs et effacer toutes les données (à utiliser avec précaution)
```

Consultez `make help` pour la liste complète des cibles disponibles.

---

## Dépannage

### Le port 443/80 est déjà utilisé

Un autre processus utilise le port HTTPS par défaut. Arrêtez-le ou surchargez le port :

```bash
# Trouver le processus en conflit
sudo lsof -i :443

# Ou configurer un port différent dans compose.override.yaml
```

### Erreurs de certificat auto-signé dans le navigateur

Acceptez l'avertissement du certificat une seule fois.

### `make start` échoue avec des erreurs de permissions

Assurez-vous que Docker a accès au répertoire du projet. Sous Linux, vous devrez peut-être ajouter votre utilisateur au groupe `docker` :

```bash
sudo usermod -aG docker $USER
newgrp docker
```
