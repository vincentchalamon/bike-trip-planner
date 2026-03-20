# Demarrage rapide

*[English version](getting-started.md)*

Ce guide vous accompagne dans l'installation, la configuration et le lancement de Bike Trip Planner sur votre machine locale.

---

## Prerequis

Assurez-vous que les outils suivants sont installes avant de continuer.

| Outil | Version minimale | Utilisation |
|-------|------------------|-------------|
| Docker | 24+ | Execute tous les services (PHP, Node) |
| Docker Compose | 2.20+ | Orchestre l'environnement multi-conteneurs |
| Git | 2.40+ | Gestion de version |
| Make | 4+ | Lanceur de taches (encapsule les commandes Docker Compose) |

> **Note :** Aucune installation locale de PHP ou Node.js n'est necessaire. Tous les runtimes s'executent dans des conteneurs.

Verifiez votre configuration :

```bash
docker --version
docker compose version
make --version
```

---

## Installation

Clonez le depot :

```bash
git clone <repo-url> bike-trip-planner
cd bike-trip-planner
```

Demarrez l'application en mode production :

```bash
make start
```

Cela demarre plusieurs services :

| Service | URL | Description |
|---------|-----|-------------|
| `php` | `https://localhost/docs` | Backend API Platform |
| `pwa` | `https://localhost` | Frontend Next.js |
| `worker` | Interne uniquement | Worker de messages asynchrones |
| `mercure` | Interne uniquement | Microservice de push serveur |
| `redis` | Interne uniquement | Microservice de cache |
| `caddy` | Interne uniquement | Microservice serveur web |

> **TLS :** Caddy genere un certificat auto-signe pour `localhost`. Acceptez l'avertissement du navigateur au premier chargement, ou installez le certificat dans le magasin de confiance de votre systeme.

L'application est prete lorsque tous les services sont sains.

---

## Verifier l'installation

Ouvrez `https://localhost` dans votre navigateur. Vous devriez voir la page d'accueil de Bike Trip Planner.

Pour verifier que l'API repond :

```bash
curl -k https://localhost/docs.json | head -20
```

Vous devriez recevoir un document JSON OpenAPI.

---

## Taches courantes

```bash
make start     # Demarrer tous les conteneurs
make stop      # Arreter tous les conteneurs (conserve les donnees)
make clean     # Arreter tous les conteneurs et effacer toutes les donnees (a utiliser avec precaution)
```

Consultez `make help` pour la liste complete des cibles disponibles.

---

## Depannage

### Le port 443/80 est deja utilise

Un autre processus utilise le port HTTPS par defaut. Arretez-le ou surchargez le port :

```bash
# Trouver le processus en conflit
sudo lsof -i :443

# Ou configurer un port different dans compose.override.yaml
```

### Erreurs de certificat auto-signe dans le navigateur

Acceptez l'avertissement du certificat une seule fois.

### `make start` echoue avec des erreurs de permissions

Assurez-vous que Docker a acces au repertoire du projet. Sous Linux, vous devrez peut-etre ajouter votre utilisateur au groupe `docker` :

```bash
sudo usermod -aG docker $USER
newgrp docker
```
