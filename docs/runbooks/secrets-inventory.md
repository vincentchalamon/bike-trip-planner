# Secrets Inventory

Single source of truth for every secret used by the production stack. Updated as part of any PR that introduces or removes a secret (see PR template checklist).

Centralisation **documentaire** uniquement : aucun SaaS de gestion de secrets (Bitwarden Secrets Manager, Doppler, Vault…) n'est utilisé. Coolify reste le runtime store ; le bundle envs + PEM est sauvegardé chiffré (`age`) vers B2 par le job de backup (Sprint 39 Backup & DR — ADR-038, #527 ; hors scope ici).

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
| `JWT_PASSPHRASE` | Passphrase | Coolify env | `php`, `worker` | Oui | On-compromise (avec la clé privée) | ADR-023 |
| `MERCURE_JWT_KEY` | Passphrase HS256 | Coolify env | `php` (publisher + subscriber + Mercure hub) | Oui | On-compromise | `compose.yaml` |
| `DATABASE_USERNAME` | Identifiant Postgres | Coolify env | `php`, `worker`, `database` | Oui | Statique | ADR-022 |
| `DATABASE_PASSWORD` | Password Postgres | Coolify env | `php`, `worker`, `database` | Oui | Bi-annuel + on-compromise | ADR-022 |
| `DATABASE_NAME` | Nom de base | Coolify env | `php`, `worker`, `database` | Oui | Statique | ADR-022 |
| `MAILER_DSN` | DSN Resend (contient API key) | Coolify env | `php`, `worker` | Oui | On-compromise | ADR-029 |
| `DATATOURISME_API_KEY` | API key | Coolify env | `worker` (multi-source) | Oui | On-compromise | ADR-026 |
| `SENTRY_DSN` | DSN GlitchTip (public côté projet, technique côté ingestion) | Coolify env | `php`, `worker`, `pwa` (SSR) | Oui | On-compromise (projet GlitchTip recréé) | ADR-031 |
| `NEXT_PUBLIC_SENTRY_DSN` | Idem, exposé au bundle client | Coolify env (build arg) | `pwa` (client) | Oui | Idem `SENTRY_DSN` | ADR-031 |
| `AGE_RECIPIENT` | Clé publique `age` | Coolify env du service `backup` (clé publique committable) | `backup` (chiffrement dumps) | N/A (publique) | On-compromise — clé privée seule sensible | ADR-038 (#527) |
| Clé privée `age` correspondante | Clé privée `age` | **Bitwarden vault** (hors VM) | Opérateur lors d'un restore | N/A (jamais en prod) | On-compromise | ADR-038 (#527) |
| `B2_ACCOUNT_ID` / `B2_APPLICATION_KEY` | Application key Backblaze | Coolify env du service `backup` | `backup` (rclone) | Oui | **Annuelle** + on-compromise | ADR-038 (#527) |
| `OCI_*` (S3 endpoint creds Object Storage) | Customer Secret Key | Coolify env du service `backup` | `backup` (rclone) | Oui | Annuelle + on-compromise | ADR-038 (#527) |

> Les entrées `AGE_RECIPIENT`, `B2_*`, `OCI_*` et le service `backup` sont introduites par le Sprint 39 (Backup & DR, #528-#530). Elles sont listées ici par anticipation ; leur ADR de référence (Backup & DR, ADR-038 — #527) reste à créer.

## CI/CD secrets (consommés par GitHub Actions)

| Nom | Type | Localisation source | Consommateur (workflow) | Rotation | Référence |
|---|---|---|---|---|---|
| `COOLIFY_WEBHOOK_URL` | URL avec token intégré | GitHub repo secret | `deploy.yml` | On-compromise (régénération côté Coolify) | PR #501 |
| `COOLIFY_DEPLOY_SECRET` | Bearer secret | GitHub repo secret | `deploy.yml` | On-compromise | PR #501 |
| `SENTRY_AUTH_TOKEN` | Org token GlitchTip | GitHub repo secret | `deploy.yml` (source-map upload) | On-compromise | ADR-031 |
| `SENTRY_URL` / `SENTRY_ORG` / `SENTRY_PROJECT` | Métadonnées (non sensibles) | GitHub repo secret | `deploy.yml` | Statiques | ADR-031 |
| `NEXT_PUBLIC_SENTRY_DSN` | DSN client | GitHub repo secret | `deploy.yml` (build arg) | Idem runtime | ADR-031 |
| `INCIDENT_DISPATCH_TOKEN` | Fine-grained PAT (issues:write) | GitHub repo secret | `incident-create.yml`, alertes externes | **90 jours** (déjà documenté) | PR #502 |
| `DATABASE_URL` | DSN Postgres (dev/import) | GitHub repo secret | `import-markets.yml` | Statique (DSN dev) | — |
| `CLAUDE_CODE_OAUTH_TOKEN` | OAuth token Anthropic | GitHub repo secret | `claude.yml`, `claude-code-review.yml` | Géré par Anthropic | CLAUDE.md |
| `GITHUB_TOKEN` | Token natif GHA | Auto-injecté | Tous workflows | Géré par GitHub (par run) | — |

## Bootstrap (perte totale)

En cas de bootstrap depuis zéro (VM perdue, Coolify réinstallé) :

1. Récupérer la clé privée `age` depuis **Bitwarden vault** : item canonique `bike-trip-planner / age private key` (la rotation conserve toujours ce nom pour la clé courante et renomme l'ancienne en `... legacy YYYYMMDD`, voir [secrets-rotation.md](secrets-rotation.md)).
2. Restaurer le bundle envs depuis B2 via le runbook `disaster-recovery.md` (à créer en Sprint 39, #532).
3. Réimporter les envs dans Coolify (UI ou `coolify` CLI).
4. Pour les CI secrets : régénérer depuis le provider concerné (Backblaze, Resend, Anthropic…) ; ces secrets ne sont **pas** dans le backup bundle (ils vivent dans GitHub).

## Hors scope

- Secrets de développement (`.env.local`, `.env.test`) : non sensibles, regénérés par `make start-dev`.
- Secrets applicatifs internes (CSRF, session cookies) : gérés par Symfony, scope local.
