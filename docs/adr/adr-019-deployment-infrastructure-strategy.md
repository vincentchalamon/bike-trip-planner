# ADR-019: Deployment Infrastructure Strategy

- **Status:** Proposed
- **Date:** 2026-03-04
- **Depends on:** ADR-016 (performance optimization), ADR-017 (Valhalla + Overpass), ADR-018 (Garmin export)

## Context and Problem Statement

Le projet est aujourd'hui exclusivement développé en local via Docker Compose. Plusieurs fonctionnalités planifiées nécessitent un déploiement en production :

- **OAuth 2.0 PKCE (ADR-018 Phase 2)** : callback URL publique HTTPS obligatoire pour l'intégration Garmin Connect
- **Persistance BDD** : stockage pérenne des tokens OAuth et potentiellement des trips
- **Accessibilité externe** : permettre l'utilisation du planificateur depuis n'importe quel appareil
- **Partage d'itinéraire** : permettre à un utilisateur de partager son trip via un lien (lecture seule) avec d'autres personnes (co-riders, proches)
- **Authentification** : l'exposition publique de l'application impose une couche d'authentification pour protéger l'accès aux données utilisateur, prévenir les abus (compute, stockage) et isoler les trips entre utilisateurs
- **Application mobile** : une URL publique ouvre la possibilité d'une PWA ou d'une application mobile native consommant l'API existante

### Infrastructure requise (stack complet)

| Service | Rôle | RAM estimée |
|---------|------|-------------|
| Caddy | Reverse proxy, TLS | ~50 MB |
| PHP (API Platform) | Backend stateless | ~500 MB |
| Next.js | Frontend SSR | ~600 MB |
| Redis | Cache + message queue | ~100 MB |
| Mercure | SSE (temps réel) | ~50 MB |
| Workers (×5) | Traitement async | ~1.5 GB |
| PostgreSQL | Persistance (planifié) | ~200 MB |
| Valhalla (ADR-017) | Routing engine | ~1-2 GB |
| Overpass (ADR-017) | POI discovery | ~2-3 GB |
| **Ollama (ADR-027)** | **Inférence LLaMA 8B + 3B** | **~6-8 GB** |
| **Total** | | **~12-17 GB RAM** |

Stockage disque : ~6 GB (PBF Geofabrik + tiles Valhalla + base Overpass) + ~10 GB (modèles Ollama llama3.1:8b + llama3.2:3b).

### Contraintes

- **Budget** : gratuit ou quasi-gratuit (projet personnel)
- **Nom de domaine** : sous-domaine fourni par le fournisseur acceptable (ex : `*.fly.dev`)
- **HTTPS** : obligatoire (OAuth callback, sécurité, authentification)
- **Localisation** : France ou Europe privilégiée (latence, RGPD)
- **Architecture** : Docker Compose existant, idéalement réutilisable tel quel
- **Authentification** : obligatoire dès la mise en production (cf. section dédiée ci-dessous)

### Authentification obligatoire

Le déploiement en production transforme le projet d'un outil local mono-utilisateur en une application exposée sur Internet. Sans authentification, l'application est ouverte à tous sans aucune contrainte, ce qui expose à :

- **Abus de ressources computationnelles** : n'importe qui peut lancer des calculs coûteux (Valhalla routing, Overpass queries, weather APIs, workers async) → épuisement CPU/RAM sur une infrastructure à ressources limitées
- **Explosion du stockage** : création illimitée de trips en BDD → saturation du disque PostgreSQL
- **Attaques** : brute-force sur les endpoints de calcul, scraping, DDoS applicatif via des requêtes légitimes mais massives
- **Absence d'isolation des données** : pas de séparation entre utilisateurs, pas de notion de propriété sur un trip

L'authentification est un **prérequis de sécurité obligatoire avant toute mise en production**, indépendamment de toute autre fonctionnalité. Elle est également nécessaire pour :

- Le stockage des tokens OAuth Garmin (ADR-018 Phase 2), par nature liés à un utilisateur
- La persistance des trips en BDD, qui nécessite une relation `user → trip`
- Le rate limiting par utilisateur (et non uniquement par IP)

Les détails d'implémentation (stratégie d'authentification, provider, sessions) feront l'objet d'un ADR dédié.

### Partage d'itinéraire

Fonctionnalité distincte rendue possible par le déploiement public. Une fois l'authentification et la persistance BDD en place, le partage devient possible via un lien de type `/<trip-id>/share?token=<random>` (lecture seule, sans authentification requise pour le destinataire). Cela permet de partager un roadbook avec des co-riders ou des proches sans leur imposer de créer un compte. Les détails d'implémentation (token signé, expiration, permissions) feront l'objet d'un ADR dédié.

### Application mobile

Le déploiement public rend l'API backend accessible depuis n'importe quel client, ouvrant la voie à une application mobile. Deux approches possibles :

- **PWA (Progressive Web App)** : Next.js supporte nativement le mode PWA (Service Worker, manifest, installation sur l'écran d'accueil). Effort minimal — le frontend existant devient installable sur mobile tel quel. Fonctionne hors-ligne pour la consultation des roadbooks déjà chargés.
- **Application native** (React Native, Flutter) : consomme directement l'API Platform (OpenAPI) et les événements Mercure SSE. Effort significatif mais permet l'accès aux fonctionnalités natives du téléphone (GPS temps réel, notifications push, mode hors-ligne avancé).

L'architecture découplée (API stateless + frontend séparé) facilite l'ajout d'un client mobile sans modification du backend. Les détails feront l'objet d'un ADR dédié le cas échéant.

## Considered Options

### Option A : Oracle Cloud Always Free + Coolify + FreeDNS

VM ARM Ampere A1 sur le free tier permanent d'Oracle Cloud Infrastructure, avec Coolify (PaaS open-source auto-hébergé) pour la gestion des conteneurs, et FreeDNS pour le nom de domaine gratuit.

#### Oracle Cloud Always Free — Ressources détaillées

Le free tier OCI est permanent (pas un essai limité dans le temps). Les ressources "Always Free" restent disponibles indéfiniment après expiration des crédits d'essai ($300/30 jours).

| Ressource | Limite Always Free |
|-----------|-------------------|
| **Compute ARM (Ampere A1)** | 4 OCPUs + 24 GB RAM (`VM.Standard.A1.Flex`). Répartissable librement (1×4/24 ou 2×2/12, etc.) |
| **Compute AMD (Micro)** | 2 VMs `VM.Standard.E2.1.Micro` (1/8 OCPU, 1 GB RAM chacune) |
| **Block volume** | 200 GB combinés (boot + data) |
| **Object Storage** | 20 GB |
| **Bande passante sortante** | 10 TB/mois |
| **IP publique réservée** | 1 (permanente) |
| **Load Balancer** | 1 flexible (10 Mbps) |
| **Backups** | 5 snapshots (boot + block) |

Pour notre projet : **1 seule VM ARM de 4 OCPUs / 24 GB RAM** avec un boot volume de 150 GB (OS + Docker + Valhalla tiles + Overpass DB + PostgreSQL + marge).

**Régions Europe** : Amsterdam, Frankfurt, Madrid, Milan, Marseille, Paris, Stockholm, Zurich.

**Reclaim policy** : Oracle peut réclamer les instances inactives si, sur 7 jours, les 3 critères sont réunis simultanément : CPU < 20% (p95), réseau < 20%, mémoire < 20%. Avec 5 workers async + Valhalla + Overpass en mémoire, ce seuil ne devrait pas être atteint.

**"Out of capacity"** : les instances ARM sont très demandées dans les régions populaires (Frankfurt, Amsterdam). Des [scripts de retry automatique](https://github.com/hitrov/oci-arm-host-capacity) réessaient jusqu'à libération de capacité. Privilégier les régions moins saturées (Marseille, Madrid, Milan).

#### Coolify — PaaS auto-hébergé

[Coolify](https://coolify.io/) est un PaaS open-source (alternative gratuite à Heroku/Vercel) qui s'installe sur n'importe quel serveur via SSH et fournit une UI web pour gérer les déploiements.

| Capacité | Détail |
|----------|--------|
| Déploiement Docker Compose | Le `compose.yaml` existant est la source de vérité. Coolify le déploie tel quel avec volumes persistants |
| Reverse proxy | Traefik configuré automatiquement (routing, load balancing) |
| HTTPS automatique | Certificats Let's Encrypt générés et renouvelés sans intervention. Support wildcard via DNS challenge |
| Git integration | Webhook GitHub/GitLab → chaque push déclenche build + deploy |
| Monitoring | Terminal web, logs temps réel, alertes (Discord, Telegram, email) |
| Rollback | Retour à une version précédente en 1 clic |
| Zero vendor lock-in | Conteneurs et configs restent sur le serveur si on quitte Coolify |

Overhead : ~500 MB de RAM (Traefik + API + dashboard), négligeable sur 24 GB.

#### FreeDNS — Nom de domaine gratuit

[FreeDNS](https://freedns.afraid.org) (freedns.afraid.org) est un service DNS gratuit fournissant des sous-domaines sur des domaines publics partagés. Pas besoin d'acheter un nom de domaine.

| Capacité | Détail |
|----------|--------|
| Sous-domaine gratuit | Choix parmi des milliers de domaines partagés (ex : `biketrip.mooo.com`, `biketrip.us.to`) |
| Configuration | Enregistrement A pointant vers l'IP publique Oracle Cloud |
| Dynamic DNS | Mise à jour automatique de l'IP via cron job (`curl`) |
| Compatible Let's Encrypt | Les sous-domaines FreeDNS sont validés sans problème pour les certificats SSL |
| Compatible OAuth callback | URL HTTPS publique stable, utilisable pour le callback Garmin Connect |

**Limites** : domaines partagés parfois fantaisistes ; pas de contrôle sur le domaine parent. Alternative : un domaine `.fr` coûte ~6€/an (OVH, Gandi) avec contrôle total.

#### Synthèse Option A

| Critère | Évaluation |
|---------|------------|
| Ressources | 4 OCPUs ARM, **24 GB RAM**, 200 GB stockage — gratuit à vie |
| Régions Europe | Amsterdam, Frankfurt, Madrid, Milan, Marseille, Paris, Stockholm, Zurich |
| Sous-domaine | FreeDNS gratuit (ex : `biketrip.mooo.com`) ou domaine propre (~6€/an) |
| Stack complet | **Oui** — seule option capable de faire tourner Valhalla + Overpass + tout le stack |
| HTTPS | Let's Encrypt automatique via Coolify/Traefik |
| Déploiement | Coolify (UI web, Git webhooks, rollback) |
| Coût total | **0€** (ou ~6€/an avec domaine propre) |
| Limites | Architecture ARM (`linux/arm64`). Disponibilité variable selon la région. Reclaim policy sur instances inactives |

### Option B : Fly.io (pay-as-you-go)

VMs Firecracker avec crédit mensuel (~$5), déploiement via CLI `flyctl`.

| Critère | Évaluation |
|---------|------------|
| Ressources | ~$5/mois de crédit, VMs de 256 MB à 2 GB RAM |
| Régions Europe | Amsterdam, Paris, Stockholm, London, Frankfurt, Warsaw |
| Sous-domaine | `<app>.fly.dev` |
| Stack complet | **Non** — RAM insuffisante pour Valhalla + Overpass |
| Stack de base | Oui (PHP + Next.js + Redis + Mercure + workers) dans la limite du crédit |
| PostgreSQL | Fly Postgres inclus (gratuit jusqu'à 1 GB) |
| Limites | Carte bancaire requise. Plus de free plan officiel depuis octobre 2024. Le crédit de $5 ne couvre pas le stack complet |

### Option C : Render (free tier)

PaaS managé avec instances gratuites qui spin-down après inactivité.

| Critère | Évaluation |
|---------|------------|
| Ressources | 750h/mois d'instances Starter (512 MB RAM), 100 GB bandwidth |
| Régions Europe | Frankfurt (payant uniquement) — free tier restreint aux US (Oregon) |
| Sous-domaine | `<app>.onrender.com` |
| Stack complet | **Non** |
| Stack de base | **Non** — spin-down après 15 min d'inactivité (cold start 10-30s), incompatible avec Mercure SSE et workers permanents |
| PostgreSQL | 1 GB gratuit, supprimé après 90 jours |
| Redis | 25 MB éphémère |
| Limites | 512 MB/service max. Free tier uniquement en US. Spin-down tue les connexions SSE |

### Option D : Koyeb (free tier)

PaaS avec data center à Paris, free tier permanent mais très limité.

| Critère | Évaluation |
|---------|------------|
| Ressources | 1 service, 512 MB RAM, 0.1 vCPU |
| Régions Europe | **Paris**, Frankfurt |
| Sous-domaine | `<app>.koyeb.app` |
| Stack complet | **Non** |
| Stack de base | **Non** — 1 seul service autorisé sur le free tier, ne peut héberger qu'un seul conteneur |
| PostgreSQL | 1 base gratuite incluse |
| Limites | Trop contraint pour un projet multi-services. Utile uniquement pour un microservice isolé |

### Option E : Railway ($5/mois)

PaaS managé avec intégration native PostgreSQL/Redis. Pas de free tier permanent (trial 30 jours avec $5 de crédit).

| Critère | Évaluation |
|---------|------------|
| Ressources | Hobby plan $5/mois avec $5 de crédit inclus, 8 GB RAM max |
| Régions Europe | Europe-West |
| Sous-domaine | `<app>.up.railway.app` |
| Stack complet | **Partiel** — 8 GB RAM et 5 services max insuffisants pour Valhalla + Overpass |
| Stack de base | **Oui** — PHP + Next.js + Redis + PostgreSQL + Mercure + workers (si regroupés) |
| Limites | Pas gratuit ($5/mois). Limite de 5 services par projet, le stack complet en requiert 8+ |

### Option rejetée : Strava comme passerelle de déploiement vers Garmin

Non pertinent pour l'infrastructure, mais documenté ici par complétude : l'API Strava v3 est read-only pour les routes (aucun endpoint `POST /routes`). L'envoi d'itinéraires planifiés via Strava est techniquement impossible. Cf. ADR-018.

## Decision Outcome

**Retenu : Option A (Oracle Cloud Always Free + Coolify + FreeDNS) comme cible de production.**

C'est la seule infrastructure gratuite offrant suffisamment de ressources (24 GB RAM, 200 GB stockage) pour le stack complet incluant Valhalla et Overpass.

**Option de repli : Option B (Fly.io) pour un déploiement intermédiaire** sans Valhalla/Overpass, avec l'API Overpass publique en fallback (latence plus élevée, cf. ADR-017).

### Architecture d'infrastructure cible

```text
                        ┌─────────────────────────────────────┐
                        │           FreeDNS / Domaine          │
                        │     biketrip.mooo.com (A record)     │
                        └──────────────┬──────────────────────┘
                                       │
                        ┌──────────────▼──────────────────────┐
                        │    Oracle Cloud Always Free (ARM)    │
                        │  4 OCPUs · 24 GB RAM · 200 GB disk   │
                        │  IP publique réservée · 10 TB/mois   │
                        │  Région : Marseille / Paris / Madrid  │
                        └──────────────┬──────────────────────┘
                                       │
                        ┌──────────────▼──────────────────────┐
                        │        Coolify (PaaS auto-hébergé)   │
                        │  UI web · Git webhooks · Rollback    │
                        │  ~500 MB RAM                         │
                        └──────────────┬──────────────────────┘
                                       │
                        ┌──────────────▼──────────────────────┐
                        │     Traefik (reverse proxy + TLS)    │
                        │  Let's Encrypt auto · Port 443/80    │
                        └───┬──────┬──────┬──────┬────────────┘
                            │      │      │      │
              ┌─────────────▼┐ ┌───▼────┐ ┌▼─────▼──────────┐
              │   Next.js    │ │  PHP   │ │    Mercure       │
              │  (frontend)  │ │ (API)  │ │    (SSE)         │
              │  Port 3000   │ │  8000  │ │   Port 3001      │
              │  ~600 MB     │ │ ~500MB │ │   ~50 MB         │
              └──────────────┘ └───┬────┘ └──────────────────┘
                                   │
                    ┌──────────────┼──────────────┐
                    │              │              │
              ┌─────▼────┐  ┌─────▼────┐  ┌──────▼─────┐
              │  Redis   │  │PostgreSQL│  │  Workers   │
              │ (cache + │  │  (BDD)   │  │   (×5)     │
              │  queue)  │  │  ~200 MB │  │  ~1.5 GB   │
              │  ~100 MB │  └──────────┘  └──────┬─────┘
              └──────────┘                       │
                                   ┌─────────────┼─────────────┐
                                   │             │             │
                            ┌──────▼───┐  ┌──────▼───┐  ┌─────▼──────┐
                            │ Valhalla │  │ Overpass │  │  APIs ext.  │
                            │ (routing)│  │  (POI)   │  │ OpenMeteo   │
                            │  ~1.5 GB │  │  ~2.5 GB │  │ Komoot etc. │
                            │ Port 8002│  │ Port 8003│  └────────────┘
                            └──────────┘  └──────────┘

                        ┌─────────────────────────────────────┐
                        │         Estimation RAM totale         │
                        │                                       │
                        │  Coolify + Traefik        ~550 MB    │
                        │  Next.js                  ~600 MB    │
                        │  PHP (API)                ~500 MB    │
                        │  Mercure                   ~50 MB    │
                        │  Redis                    ~100 MB    │
                        │  PostgreSQL               ~200 MB    │
                        │  Workers (×5)            ~1500 MB    │
                        │  Valhalla                ~1500 MB    │
                        │  Overpass                ~2500 MB    │
                        │  Ollama (LLaMA 8B+3B)    ~7000 MB    │
                        │  ─────────────────────────────────   │
                        │  Total                  ~14.5 GB     │
                        │  Disponible              24.0 GB     │
                        │  Marge                    ~9.5 GB    │
                        └─────────────────────────────────────┘
```

### Stratégie de déploiement progressive

1. **Phase immédiate** : continuer en développement local (Docker Compose)
2. **Phase pré-production** : implémenter l'authentification (prérequis obligatoire avant toute exposition publique) et la persistance BDD (PostgreSQL + Doctrine)
3. **Phase intermédiaire** : déployer le stack de base sur Fly.io ou Railway pour valider le flow OAuth Garmin (ADR-018 Phase 2) et le partage d'itinéraire
4. **Phase cible** : migrer vers Oracle Cloud + Coolify pour le stack complet avec Valhalla + Overpass

## Consequences

### Positive

- **Coût zéro** : le free tier Oracle Cloud est permanent et suffisant pour un projet personnel
- **Stack complet** : 24 GB RAM permet de faire tourner tous les services, y compris les plus gourmands (Valhalla, Overpass)
- **Souveraineté** : auto-hébergé, données sous contrôle
- **Partage d'itinéraire** : l'exposition publique permet le partage de roadbooks via un simple lien (lecture seule)
- **Application mobile** : l'architecture découplée (API stateless + frontend séparé) permet d'ajouter un client mobile (PWA ou natif) sans modification du backend

### Negative

- **Architecture ARM** : certaines images Docker (notamment Valhalla, Overpass) peuvent nécessiter un rebuild pour `linux/arm64`. Les images officielles supportent généralement ARM, mais c'est un risque de compatibilité
- **Maintenance opérationnelle** : pas de PaaS managé — mises à jour, backups, monitoring sont à charge
- **Fiabilité Oracle** : Oracle peut réclamer les instances inactives. Le free tier peut évoluer sans préavis
- **Pas de sous-domaine natif** : nécessite un domaine externe (achat ou FreeDNS)
- **Authentification obligatoire** : le déploiement public impose d'implémenter une couche d'authentification complète (inscription, connexion, sessions, isolation des données) avant la mise en ligne — effort significatif non encore planifié
- **Ollama = dépendance dure** : l'inférence LLaMA est non-skippable (cf. ADR-027 et issue #375 arbitrage v2 « IA toujours active »). Ollama doit être opérationnel avec les deux modèles (`llama3.1:8b`, `llama3.2:3b`) chargés avant que l'application soit considérée disponible. La marge RAM restante (~9.5 GB) reste confortable sur une VM 24 GB. Le healthcheck Coolify doit inclure un ping Ollama (`GET /api/health`) en plus des healthchecks applicatifs existants.

### Neutral

- Coolify simplifie l'opérationnel (UI web, déploiement Git, SSL automatique) mais ajoute une couche logicielle à maintenir

## Sources

- [Oracle Cloud Free Tier](https://www.oracle.com/cloud/free/)
- [Oracle Cloud Always Free Resources](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm)
- [Coolify — Self-Hosted PaaS](https://coolify.io/self-hosted)
- [Coolify + Oracle Cloud Setup](https://coolify.io/docs/knowledge-base/server/oracle-cloud)
- [Fly.io Pricing](https://fly.io/pricing/)
- [Render Free Tier](https://render.com/docs/free)
- [Koyeb Pricing](https://www.koyeb.com/pricing)
- [Railway Pricing](https://docs.railway.com/pricing/plans)
- [Strava API v3 Reference](https://developers.strava.com/docs/reference/) — pas d'endpoint de création de route
- [FreeDNS — afraid.org](https://freedns.afraid.org)
- [Coolify Docker Compose Deployment](https://coolify.io/docs/knowledge-base/docker/compose)
- [Coolify Traefik SSL / Let's Encrypt](https://coolify.io/docs/knowledge-base/proxy/traefik/overview)
- [Script de retry OCI ARM "Out of Capacity"](https://github.com/hitrov/oci-arm-host-capacity)
