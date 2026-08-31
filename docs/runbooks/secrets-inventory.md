# Secrets Inventory

Single source of truth for every secret used by the production stack. Updated as part of any PR that introduces or removes a secret (see PR template checklist).

Centralisation **documentaire** uniquement : aucun SaaS de gestion de secrets (Doppler, Bitwarden Secrets Manager…) n'est utilisé. Le runtime store est **Ansible Vault** (ADR-061) : les valeurs sont chiffrées dans `ansible/`, rendues sur la VM dans le `.env` prod (consommé par `docker compose -p prod`) et le PEM JWT sous `/etc/bike-trip-planner/jwt/`. Le bundle `.env` + PEM est sauvegardé chiffré (`age`) vers B2/OCI par le service de backup (Backup & DR — ADR-062 ; hors scope ici).

Pour la rotation : voir [secrets-rotation.md](secrets-rotation.md).

## Conventions

- **Localisation source** = système qui détient la valeur de référence. Si elle est perdue ailleurs, on la récupère ici.
- **Bitwarden vault** désigne le Bitwarden Password Manager personnel (plan Free), pas Bitwarden Secrets Manager.
- **Backup bundle** = inclus dans le tar chiffré `age` produit par le service `backup` (Sprint 39, #530).

## Runtime secrets (consommés par la stack en prod)

| Nom | Type | Localisation source | Consommateur | Backup bundle | Rotation | Référence |
|---|---|---|---|---|---|---|
| `JWT_PRIVATE_KEY_PATH` (PEM) | PEM RSA | Fichier monté `/etc/bike-trip-planner/jwt/private.pem` (VM) | `php`, `worker` (LexikJWT) | Oui | On-compromise | ADR-023 |
| `JWT_PUBLIC_KEY_PATH` (PEM) | PEM RSA | Fichier monté `/etc/bike-trip-planner/jwt/public.pem` (VM) | `php`, `worker` (LexikJWT) | Oui | On-compromise (avec la clé privée) | ADR-023 |
| `JWT_PASSPHRASE` | Passphrase | Ansible Vault → `.env` | `php`, `worker` | Oui | On-compromise (avec la clé privée) | ADR-023 |
| `MERCURE_JWT_KEY` | Passphrase HS256 | Ansible Vault → `.env` | `php` (publisher + subscriber + Mercure hub) | Oui | On-compromise | `compose.yaml` |
| `REFRESH_TOKEN_ENC_KEY` | Clé de chiffrement des refresh tokens (libsodium) | Ansible Vault → `.env` | `php`, `worker` | Oui | On-compromise (invalide les refresh tokens chiffrés → re-login) | ADR-023 / ADR-052 / SEC-003 |
| `DATABASE_USERNAME` | Identifiant Postgres | Ansible Vault → `.env` | `php`, `worker`, `database` | Oui | Statique | ADR-022 |
| `DATABASE_PASSWORD` | Password Postgres | Ansible Vault → `.env` | `php`, `worker`, `database` | Oui | Bi-annuel + on-compromise | ADR-022 |
| `DATABASE_NAME` | Nom de base | Ansible Vault → `.env` | `php`, `worker`, `database` | Oui | Statique | ADR-022 |
| `MAILER_DSN` | DSN Brevo (`brevo+api://KEY@default`, contient API key) | Ansible Vault → `.env` | `php`, `worker` | Oui | On-compromise | ADR-029 / ADR-061 |
| `ACCESS_REQUEST_HMAC_SECRET` | Secret HMAC-SHA256 | Ansible Vault → `.env` | `php`, `worker` | Oui | On-compromise (invalide les liens d'activation en attente) | ADR-029 |
| `DATATOURISME_API_KEY` | API key | Ansible Vault → `.env` | `worker` (multi-source) | Oui | On-compromise | ADR-026 |
| `OPENAGENDA_API_KEY` | API key Opendatasoft (facultative ; export public sans clé) | Ansible Vault → `.env` | `provisioner` (source événements) | Non | On-compromise | ADR-051 |
| `SENTRY_DSN` | DSN GlitchTip (public côté projet, technique côté ingestion) | Ansible Vault → `.env` | `php`, `worker`, `pwa` (SSR) | Oui | On-compromise (projet GlitchTip recréé) | ADR-031 |
| `NEXT_PUBLIC_SENTRY_DSN` | Idem, exposé au bundle client | GitHub repo secret (build arg CI) | `pwa` (client) | Oui | Idem `SENTRY_DSN` | ADR-031 |
| `AGE_RECIPIENT` | Clé publique `age` | Ansible Vault → `.env` du service `backup` (clé publique committable) | `backup` (chiffrement dumps) | N/A (publique) | On-compromise — clé privée seule sensible | ADR-062 |
| Clé privée `age` correspondante | Clé privée `age` | **Bitwarden vault** (hors VM) | Opérateur lors d'un restore | N/A (jamais en prod) | On-compromise | ADR-062 |
| `B2_ACCOUNT_ID` / `B2_APPLICATION_KEY` | Application key Backblaze | Ansible Vault → `.env` du service `backup` | `backup` (rclone) | Oui | **Annuelle** + on-compromise | ADR-062 |
| `OCI_*` (S3 endpoint creds Object Storage) | Customer Secret Key | Ansible Vault → `.env` du service `backup` | `backup` (rclone) | Oui | Annuelle + on-compromise | ADR-062 |

> Les entrées `AGE_RECIPIENT`, `B2_*`, `OCI_*` et le service `backup` relèvent du workstream Backup & DR (ADR-062). Elles sont listées ici par anticipation ; leur ADR de référence est finalisé dans la PR Backup & DR.

## CI/CD secrets (consommés par GitHub Actions)

| Nom | Type | Localisation source | Consommateur (workflow) | Rotation | Référence |
|---|---|---|---|---|---|
| `SSH_HOST` | Hôte SSH de la VM prod | GitHub repo secret | `deploy.yml` (`deploy-prod` / `deploy-preview`) | On-compromise | ADR-061 |
| `SSH_USER` | Utilisateur SSH de déploiement | GitHub repo secret | `deploy.yml` | On-compromise | ADR-061 |
| `SSH_KEY` | Clé privée SSH de déploiement | GitHub repo secret | `deploy.yml` | On-compromise (paire régénérée + `authorized_keys` re-provisionnée par Ansible) | ADR-061 |
| `SSH_KNOWN_HOSTS` | Clé d'hôte SSH épinglée (facultative ; sinon `ssh-keyscan` TOFU) | GitHub repo secret | `deploy.yml` (`deploy-prod`) | On-changement d'hôte VM | ADR-061 |
| `PROD_HEALTH_URL` | URL prod du smoke-test (`https://www.${DOMAIN}`) | GitHub repo secret | `deploy.yml` (smoke-test) | Statique | ADR-061 |
| `SENTRY_AUTH_TOKEN` | Org token GlitchTip | GitHub repo secret | `deploy.yml` (source-map upload) | On-compromise | ADR-031 |
| `SENTRY_URL` / `SENTRY_ORG` / `SENTRY_PROJECT` | Métadonnées (non sensibles) | GitHub repo secret | `deploy.yml` | Statiques | ADR-031 |
| `NEXT_PUBLIC_SENTRY_DSN` | DSN client | GitHub repo secret | `deploy.yml` (build arg) | Idem runtime | ADR-031 |
| `INCIDENT_DISPATCH_TOKEN` | Fine-grained PAT (issues:write) | GitHub repo secret | `incident-create.yml`, alertes externes | **90 jours** (déjà documenté) | PR #502 |
| `DATABASE_URL` | DSN Postgres (dev/CI) | GitHub repo secret | `ci.yml` | Statique (DSN dev) | — |
| `CLAUDE_CODE_OAUTH_TOKEN` | OAuth token Anthropic | GitHub repo secret | `claude.yml`, `claude-code-review.yml` | Géré par Anthropic | CLAUDE.md |
| `GITHUB_TOKEN` | Token natif GHA | Auto-injecté | Tous workflows | Géré par GitHub (par run) | — |

## Bootstrap (perte totale)

En cas de bootstrap depuis zéro (VM perdue, VM re-provisionnée) :

1. Récupérer la clé privée `age` depuis **Bitwarden vault** : item canonique `bike-trip-planner / age private key` (la rotation conserve toujours ce nom pour la clé courante et renomme l'ancienne en `... legacy YYYYMMDD`, voir [secrets-rotation.md](secrets-rotation.md)).
2. Restaurer le bundle `.env` + PEM depuis B2/OCI via le runbook `disaster-recovery.md` (Backup & DR, ADR-062).
3. Re-provisionner la VM avec **Ansible** : le playbook rend le `.env` prod et le PEM JWT depuis **Ansible Vault** sur la VM (ADR-061), puis `docker compose -p prod … up -d` au premier tag redéployé.
4. Pour les CI secrets : régénérer depuis le provider concerné (Backblaze, Brevo, Anthropic…) ; ces secrets ne sont **pas** dans le backup bundle (ils vivent dans GitHub).

## Hors scope

- Secrets de développement (`.env.local`, `.env.test`) : non sensibles, regénérés par `make start-dev`.
- Secrets applicatifs internes (CSRF, session cookies) : gérés par Symfony, scope local.
