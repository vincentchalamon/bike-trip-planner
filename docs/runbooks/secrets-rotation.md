# Secrets Rotation

Politique de rotation des secrets de production. Volontairement courte : à l'échelle du projet (un environnement, un opérateur, ~15 secrets), la rotation calendaire systématique coûte plus qu'elle ne rapporte. Le mode par défaut est **on-compromise**, sauf cas listés ci-dessous.

L'inventaire complet des secrets est dans [secrets-inventory.md](secrets-inventory.md).

## Symptômes (déclencheurs de rotation)

- Suspicion de fuite (commit accidentel, log exposé, screenshot partagé, poste compromis)
- Alerte GitHub Secret Scanning (notification automatique GitHub ou `gh api repos/vincentchalamon/bike-trip-planner/secret-scanning/alerts`)
- Incident `severity:high` impliquant le service ou l'opérateur détenant la clé
- Échéance calendaire (cf. matrice ci-dessous)

## Matrice de rotation

| Secret | Cadence par défaut | Justification |
|---|---|---|
| `JWT_*` (PEM + passphrase) | On-compromise | Auth passwordless, surface limitée. Rotation invalide toutes les sessions (15 min access / 7 j refresh — acceptable). |
| `MERCURE_JWT_KEY` | On-compromise | Reconnexion SSE forcée des clients. |
| `AGE_RECIPIENT` (et clé privée associée) | On-compromise | Re-chiffrer la rétention GFS est coûteux. Clé privée hors-ligne dans Bitwarden, exposition quasi nulle. |
| `B2_APPLICATION_KEY` | **Annuelle** + on-compromise | Standard cloud. Coût rotation faible. |
| `OCI_*` (Object Storage) | **Annuelle** + on-compromise | Idem. |
| `DATABASE_PASSWORD` | **Bi-annuelle** + on-compromise | Compromise rare en pratique (réseau Docker isolé), mais hygiène utile. |
| `MAILER_DSN` (Resend) | On-compromise | Pas de risque structurel à scheduler. |
| `DATATOURISME_API_KEY` | On-compromise | Idem. |
| `SENTRY_DSN` / `SENTRY_AUTH_TOKEN` | On-compromise | Projet GlitchTip recréable. |
| `COOLIFY_WEBHOOK_URL`, `COOLIFY_DEPLOY_SECRET` | On-compromise | Webhook unique, exposition uniquement au job GHA `deploy.yml`. |
| `INCIDENT_DISPATCH_TOKEN` | **90 jours** | Déjà appliqué (fine-grained PAT, contraintes GitHub). |
| `CLAUDE_CODE_OAUTH_TOKEN` | Géré par Anthropic | Hors scope. |

## Procédure générique (on-compromise)

Pour tout secret sauf cas spécifiques (cf. section suivante) :

1. **Révoquer immédiatement** côté provider (Backblaze, Resend, Anthropic, GitHub PAT…). Couper l'accès en premier, regénérer ensuite.
2. **Générer une nouvelle valeur** côté provider, scope minimal (ex. B2 : limiter au bucket `btp-backups`).
3. **Mettre à jour** la localisation source listée dans [secrets-inventory.md](secrets-inventory.md) :
   - Coolify env → UI ou `coolify env set`
   - GitHub secret → `gh secret set <NAME>` ou UI repo settings
4. **Redéployer** si runtime secret : tag `vX.Y.Z+1-rotation` ou re-trigger `deploy.yml` manuellement.
5. **Vérifier** : `curl https://<host>/api/healthz` + `curl https://<host>/api/health` verts ; pour CI secret, déclencher le workflow concerné.
6. **Tracer** dans un commentaire de l'issue d'incident liée (`incident-template.md`) : qui, quand, quel secret, raison.

## Procédures spécifiques

### `age` recipient

La rotation **ne re-chiffre pas** l'historique des backups : trop coûteux, et l'ancienne clé privée reste valide pour décrypter les anciens dumps tant qu'on la conserve.

1. Sur un poste de confiance, hors-ligne si possible :

   ```bash
   age-keygen -o age-key-$(date +%Y%m%d).txt
   ```

2. Dans **Bitwarden vault** : **renommer l'item courant** `bike-trip-planner / age private key` en `bike-trip-planner / age private key legacy YYYYMMDD` (date de la dernière utilisation comme clé courante). **Créer un nouvel item** `bike-trip-planner / age private key` (nom canonique conservé) contenant la nouvelle clé privée. Le bootstrap DR cherche toujours le nom canonique ; les items `legacy *` ne servent qu'à restaurer les dumps antérieurs et doivent être conservés indéfiniment.
3. Mettre à jour `AGE_RECIPIENT` dans Coolify (env du service `backup`) avec la nouvelle clé publique.
4. Mettre à jour le repo si la clé publique y est référencée (`compose.yaml` par défaut env, ADR-038 — #527).
5. Forcer un backup : `make backup-now`. Confirmer via `rclone ls b2:btp-backups | tail -1` que le dernier dump est bien plus récent que la rotation.
6. **Ne pas supprimer** les anciens dumps avant leur expiration GFS naturelle.

### LexikJWT (`JWT_*`)

Invalide toutes les sessions en cours (refresh tokens DB inclus, car la vérification de signature échoue).

1. Sur la VM, dans le container `php` éphémère :

   ```bash
   docker compose exec php bin/console lexik:jwt:generate-keypair --overwrite
   ```

   Cela écrit les PEM dans `/app/config/jwt/` (espace de travail du container). Les chemins runtime (`/etc/bike-trip-planner/jwt/*.pem`) sont **montés depuis l'hôte** ; extraire les fichiers générés :

   ```bash
   docker cp php:/app/config/jwt/private.pem /etc/bike-trip-planner/jwt/private.pem
   docker cp php:/app/config/jwt/public.pem  /etc/bike-trip-planner/jwt/public.pem
   chmod 600 /etc/bike-trip-planner/jwt/private.pem
   ```

2. Mettre à jour `JWT_PASSPHRASE` dans Coolify (utiliser la passphrase saisie lors de la regen).
3. Redéployer la stack pour que `php` et `worker` rechargent les secrets Docker.
4. Communiquer aux testeurs : "reconnexion magic link nécessaire".
5. Vérifier : `curl -X POST /api/auth/magic-link` → 202, login flow complet OK.

### B2 application key

1. Backblaze B2 console → Application Keys → **Add a New Application Key**, scope `btp-backups` only, capabilities `listFiles`, `readFiles`, `writeFiles`, `deleteFiles`.
2. Récupérer `keyID` + `applicationKey` (affiché une seule fois).
3. Mettre à jour `B2_ACCOUNT_ID` (= keyID) et `B2_APPLICATION_KEY` dans Coolify env du service `backup`.
4. Restart `backup` service : `docker compose restart backup`.
5. Valider : `make backup-now` → succès, `rclone ls b2:btp-backups` liste le nouveau dump.
6. **Supprimer l'ancienne clé** dans la console Backblaze (rétention pendant 7 j non requise, vu que la nouvelle a fonctionné).

### Database password

Downtime ~30 s acceptable. Faire en heure creuse.

1. Sur la VM :

   ```bash
   docker compose exec database psql -U "$DATABASE_USERNAME" -d "$DATABASE_NAME" -c \
     "ALTER USER \"$DATABASE_USERNAME\" WITH PASSWORD '<NEW_PASSWORD>';"
   ```

2. Mettre à jour `DATABASE_PASSWORD` dans Coolify env.
3. Redéployer (Coolify) — `php`, `worker`, `backup` reprennent la nouvelle valeur via le DSN.
4. Vérifier : `curl /api/health` → `deps.postgres.status: "healthy"`.

## Post-action

- Mettre à jour la colonne "Rotation" de [secrets-inventory.md](secrets-inventory.md) si la cadence change ou si un secret est ajouté/retiré.
- Si la rotation faisait suite à un incident : compléter le post-mortem (`incident-template.md`) en référençant cette procédure.
- Pour les rotations calendaires : créer une note de rappel (calendrier perso ou issue GitHub `chore(security): rotate B2 key — due YYYY-MM`) lors de la rotation précédente.

## Hors scope

- Rotation programmatique (cron, scheduler) — non justifié à cette échelle.
- Migration vers un secret manager (Bitwarden Secrets Manager, Doppler, Vault, Infisical) — décision documentée dans [secrets-inventory.md](secrets-inventory.md). À reconsidérer si > 3 environnements ou > 1 opérateur.
