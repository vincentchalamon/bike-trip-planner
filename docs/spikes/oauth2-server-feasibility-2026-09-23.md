# Spike — `league/oauth2-server` comme authorization server MCP (23/09/2026)

> **Verdict écrit d'une investigation timeboxée, sur branche
> `spike/oauth2-server-feasibility` jamais mergée.** Destiné à devenir la section
> « Contexte » de l'ADR d'autorisation MCP. Les constats ci-dessous sont vérifiés par
> exécution, pas déduits de la documentation — et là où ils ne le sont pas, c'est écrit.

## Verdict global

**Retenu.** La réserve qui bloquait la phase 3A — « compatibilité Symfony 8 non confirmée,
point de défaillance unique » — **est levée par la mesure** : le bundle résout et démarre sur
Symfony 8.1 / PHP 8.5 / API Platform 5.0, en version **stable**, pas sur une branche de
développement. Le repli « périmètre réduit » prévu au cas où la bibliothèque ne passerait pas
n'a pas lieu d'être invoqué.

Ce qui reste à écrire est **plus petit que le plan ne le supposait sur trois points, et
inchangé sur deux**. Aucun verrou rencontré.

## Ce que la mesure corrige dans le plan de phase 3

| Le plan dit | La mesure dit |
|---|---|
| « `league/oauth2-server` déclare `~8.5.0` au niveau bibliothèque » | Confusion de numéros : `8.5` était une **version de la bibliothèque**, pas une contrainte PHP. La **9.4.1** est courante et déclare `php ~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0` — PHP 8.5 explicitement supporté |
| « compatibilité Symfony 8 non confirmée (doc : 6.4+ ; branche active 2.x) » | **Confirmée, sur stable.** `league/oauth2-server-bundle` **v1.2.2** déclare `symfony/framework-bundle: ^6.4\|^7.4\|^8.0`, idem `security-bundle` et `psr-http-message-bridge`. La 2.x-dev existe mais n'est pas nécessaire |
| « un pont **PSR-7** à établir dans une application Symfony 8 » | **Rien à écrire** : `symfony/psr-http-message-bridge` est une dépendance déclarée du bundle, et `nyholm/psr7` arrive avec lui |
| « les CIMD doivent être greffés sur un `ClientRepositoryInterface` qui suppose des clients **stockés** » | Exact, et le coût est visible : le bundle fournit des entités Doctrine `Client`/`AccessToken`/`RefreshToken`/`AuthorizationCode` et sept commandes de gestion de clients. Un `ClientRepositoryInterface` custom résolvant une URL reste à écrire |
| « un **second système de jetons** à côté de Lexik + `RefreshToken` » | Exact, et **les tables ne se marchent pas dessus** : le driver de mapping préfixe tout en `oauth2_` (`Persistence/Mapping/Driver.php:28`). Deux cycles de vie, oui ; une collision de schéma, non |
| « le binding d'audience RFC 8707 n'est pas natif » | **Confirmé** : aucune occurrence de `resource` au sens RFC 8707 ni dans la bibliothèque ni dans le bundle. Reste sur mesure, comme prévu |

## Installation : dix paquets, aucun conflit

`composer require league/oauth2-server-bundle` sur `main` à `2bfd35ee` :

| Paquet | Version |
|---|---|
| `league/oauth2-server-bundle` | **v1.2.2** (stable) |
| `league/oauth2-server` | **9.4.1** |
| `league/event` | 3.0.3 |
| `league/uri` / `league/uri-interfaces` | 7.8.1 |
| `lcobucci/jwt`, `defuse/php-encryption` | v2.4.0 |
| `nyholm/psr7` | 1.8.2 |
| `psr/http-server-handler`, `psr/http-server-middleware` | 1.0.2 |

Aucune surcharge de plateforme, aucune alerte de sécurité, aucun conflit de contrainte.
**Le noyau démarre** : `cache:warmup -e dev` passe, et `debug:router` liste les trois routes
du bundle. Résoudre n'est pas fonctionner ; les deux ont été vérifiés.

## Ce que le bundle donne, et ce qu'il ne donne pas

**Donné, à ne pas réécrire :**

- **PKCE S256 obligatoire pour les clients publics par défaut** —
  `AuthCodeGrant.php:54`, `requireCodeChallengeForPublicClients = true`. Le bundle expose
  l'interrupteur inverse (`LeagueOAuth2ServerExtension.php:301`) : ne jamais l'actionner.
- **Rotation de refresh** — `RefreshTokenGrant.php:81-82`, `revokeRefreshTokens` natif.
- **Le code d'autorisation, l'échange de token, le device code**, et les trois routes qui
  vont avec.
- **Les entités de persistance et sept commandes** de gestion de clients, dont
  `ClearExpiredTokensCommand` et `GenerateKeyPairCommand`.
- **Le point d'accroche du consentement** : `AuthorizationRequestResolveEvent`. Le bundle ne
  fournit pas d'écran, il fournit l'événement — ce qui est exactement ce dont le plan a
  besoin, la décision venant d'une page PWA déjà authentifiée par le BFF.

**Absent, donc à écrire :**

- **Les métadonnées RFC 9728 et RFC 8414.** Aucune occurrence de `.well-known`,
  `oauth-authorization-server` ni `oauth-protected-resource` dans le bundle, la bibliothèque,
  **ni dans `api-platform/`**. À noter : `api-platform/mcp` n'est pas installé sur `main` — il
  ne l'a jamais été que sur la branche du spike de l'étape 1 — donc « si `api-platform/mcp` ne
  les fournit pas » reste à reconfirmer au moment de l'installer.
- **Le binding d'audience RFC 8707.** Confirmé absent des deux paquets. C'est l'exigence de
  sécurité centrale de MCP et elle est intégralement sur mesure.
- **Les Client ID Metadata Documents**, sur un `ClientRepositoryInterface` custom. Le fetch
  d'une URL de client est un vecteur SSRF, à passer par la garde d'ADR-011.
- **L'écran de consentement** lui-même.
- **La révocation par utilisateur.** Le bundle purge par expiration, pas par compte. L'exigence
  « compte supprimé donc agents révoqués » est bien à implémenter une seconde fois, comme le
  plan le craignait.

## Deux pièges de montage, mesurés

1. **Les routes sont montées à la racine** — `/authorize`, `/token`, `/device-code`
   (`config/routes.php` du bundle), pas sous `/oauth/`. L'import doit poser un préfixe, sinon
   le plan de firewall de la phase 3A (`^/oauth/` en `PUBLIC_ACCESS` avant le catch-all) ne
   couvre rien. Et le firewall `api` ayant `pattern: ^/`, ces routes tombent dedans par défaut :
   l'ordre de déclaration des firewalls est bien le point sensible que le plan annonçait.
2. **La recette écrit du YAML** (`config/packages/league_oauth2_server.yaml`,
   `config/routes/league_oauth2_server.yaml`) alors que le dépôt n'a que du PHP et que la QA
   vérifie « No YAML configs found ». À convertir au montage.

## Réponse à la question que le spike devait trancher

> *Que reste-t-il réellement à écrire, et l'impédance vaut-elle la machine à états gagnée ?*

**Oui.** Ce qui reste à écrire est une couche mince et bien délimitée — deux documents de
métadonnées, un `ClientRepositoryInterface` résolvant une URL, le binding d'audience, un écran
de consentement, une révocation par compte. Ce qui est gagné est la machine à états OAuth
complète avec PKCE obligatoire et rotation de refresh, plus la persistance et l'outillage de
clients, sur une dépendance stable et compatible.

L'impédance annoncée s'est révélée plus faible que crainte sur trois de ses cinq points : le
pont PSR-7 n'existe pas comme travail, la compatibilité est confirmée, et le second système de
jetons ne collisionne pas avec le schéma existant. Les deux points restants — RFC 8707 et la
double révocation — auraient existé avec n'importe quelle implémentation, y compris écrite à
la main.

## Ce que ce spike n'a **pas** vérifié

À ne pas présenter comme acquis :

- **Aucun flux exécuté de bout en bout.** Le bundle démarre et route ; aucun `/authorize` →
  `/token` n'a été joué, aucun jeton émis, aucun scope appliqué.
- **Le comportement sous le worker FrankenPHP** n'a pas été exercé, alors que c'est
  précisément là que le registre MCP avait demandé un traitement particulier.
- **La cohabitation des deux firewalls** (session PWA Lexik et `mcp`) n'a pas été montée ; le
  plan la décrit, le spike ne l'a pas mise à l'épreuve.
- **Aucune mesure de performance ni de charge.**

Ces quatre points appartiennent à la conception de 3A, pas à la question de faisabilité que ce
spike avait à trancher.
