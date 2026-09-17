# Spike — Faisabilité d'un serveur MCP dans l'API (17/09/2026)

> **Verdict écrit d'une investigation timeboxée, sur branche `spike/mcp-feasibility` jamais mergée.**
> Destiné à devenir la section « Contexte » d'ADR-068. Les constats ci-dessous sont
> tous vérifiés par exécution, pas déduits de la documentation.

## Verdict global

**Faisable, et nettement moins coûteux que le plan ne le supposait sur le transport —
nettement plus coûteux sur un point que le plan n'avait pas vu (les expressions de
sécurité).** Aucun verrou bloquant n'a été rencontré.

Ce qui a été monté en une session : les paquets installés, le serveur configuré, un
outil `get_trip` déclaré sur une ressource existante en réutilisant son provider, et la
route `/mcp` servie derrière le firewall JWT existant — **sans une ligne de code
d'authentification**.

---

## Versions installées, sans conflit

| Paquet | Version |
|---|---|
| `api-platform/mcp` | **v4.3.19** |
| `mcp/sdk` | **v0.8.1** |
| `symfony/mcp-bundle` | **v0.13.0** |

Installés sur PHP 8.5 / Symfony 8.1 / API Platform 4.3.18 **sans aucun conflit de
contraintes**. `api-platform/mcp` dépend de `mcp/sdk ^0.8` et de `symfony/object-mapper`
(déjà présent), **pas** de `symfony/mcp-bundle` : le bundle reste néanmoins nécessaire,
`ApiPlatformExtension` conditionnant l'activation à `class_exists(McpBundle::class)`.

Dépendance supplémentaire découverte : **`psr/simple-cache`** est requis dès qu'on
choisit le store de session `cache` — celui qu'impose le mode worker FrankenPHP. Sans
lui, le conteneur casse sur `Attempted to load interface "CacheInterface" from namespace
"Psr\SimpleCache"`.

---

## Réponses aux cinq questions du spike

### (a) `api-platform/mcp` fournit-il le resource server OAuth ?

**Non — mais `mcp/sdk` oui, et complètement.** Le SDK embarque
`ProtectedResourceMetadataMiddleware` (RFC 9728), `AuthorizationMiddleware`,
`JwtTokenValidator`, `JwksProvider`, `OidcDiscovery`, `AuthorizationTokenValidatorInterface`,
`ClientRegistrationMiddleware` et `OAuthProxyMiddleware`.

Le SDK porte même un ADR sur le sujet — `adr/0001-oauth-authorization-server-out-of-scope.md` —
dont la conclusion recoupe mot pour mot l'analyse du plan :

> The MCP server is an OAuth 2.1 Resource Server that MAY delegate to an upstream
> authorization server. It will NOT issue tokens or act as an Identity Provider.

Et sa section « what to do instead » recommande explicitement :

> **Run `league/oauth2-server` in your own application**, behind the SDK's existing proxy
> and validator seams.

**Conséquence pour la Phase 3A** : la « couche mince » que le plan prévoyait d'écrire
(metadata RFC 9728, `WWW-Authenticate`, validation de token) **est déjà livrée**. Reste
l'AS lui-même. Et `OAuthProxyMiddleware` — une couture que le plan ignorait — sait
déléguer `/authorize` et `/token` à un AS amont, ce qui réduit encore le câblage.

**Réserve immédiate** : **`symfony/mcp-bundle` n'expose PAS ce middleware OAuth.** Son
`Http/MiddlewareFactory` ne gère que la protection DNS-rebinding. Brancher le resource
server du SDK via le bundle demande donc de décorer soi-même la pile de middlewares —
ou, plus idiomatique ici, de s'appuyer sur le firewall Symfony, que le projet a déjà.

### (b) Le mode worker FrankenPHP tient-il ?

**Traité par conception, non infirmé.** `McpRegistryPass` documente explicitement le cas
et décore le registre de chaque serveur pour charger les éléments API Platform *au
premier read*, « to heal a persistent runtime (e.g. FrankenPHP worker mode) where the SDK
builds the registry once and may capture an empty state ».

Corollaire opérationnel confirmé : le store de session doit être `cache`, pas `file` ni
`memory` — la config du bundle le dit elle-même pour PHP-FPM (« the publisher and the
stream are different workers »). D'où la dépendance `psr/simple-cache` ci-dessus.

**Non vérifié sous charge réelle** : le spike n'a pas fait tourner N agents concurrents
contre un FrankenPHP en mode worker. À garder comme test de charge en Phase 3.

### (c) La taille des réponses est-elle tenable ?

**Question rouverte par une contrainte non anticipée.** Le plan prévoyait de passer
`api_platform.mcp.format` à `json`, le `@context`/`@id` de JSON-LD étant du bruit pur
dans la fenêtre de contexte d'un agent. **C'est refusé** :

```
The MCP format "json" is not configured in api_platform.formats.
```

Le format MCP doit être l'un des formats globalement enregistrés, et
`api/config/packages/api_platform.php` ne déclare que `jsonld`. Servir le MCP en JSON
simple impose donc d'ajouter `'json' => ['application/json']` à `api_platform.formats` —
ce qui **donne aussi ce format à toutes les opérations REST**, et fait donc dériver
l'OpenAPI et `core/schema.d.ts`.

Trois issues, à trancher en Phase 3 :
1. accepter l'enveloppe JSON-LD dans le contexte de l'agent ;
2. enregistrer `json` globalement et assumer la dérive de contrat (peu coûteuse tant que
   rien n'est déployé) ;
3. chercher un format scopé à l'opération — `McpTool` étend `HttpOperation` et porte donc
   `outputFormats`, piste non explorée par le spike.

### (d) Comment teste-t-on fonctionnellement un outil MCP ?

**Non résolu, et un angle mort d'outillage a été trouvé.**

`bin/console debug:mcp` existe et tourne, mais **il ne voit pas les outils déclarés via
API Platform** : il rapporte « No MCP capabilities are registered » alors que le câblage
est complet. Vérifié dans le conteneur — `api_platform.mcp.secure_registry.btp`,
`api_platform.mcp.loader`, `api_platform.mcp.security.expression_access_checker`,
`api_platform.mcp.state.tool_provider`, `api_platform.mcp.state_processor` existent tous.
La cause est le chargement paresseux « au premier read » décrit en (b) : `debug:mcp` lit
la vue native du bundle, qui ne connaît que les services portant un attribut `#[McpTool]`.

**Donc `debug:mcp` n'est pas une boucle de retour utilisable** pour des outils déclarés en
`mcp:` sur une `ApiResource`, contrairement à ce que le plan supposait. Le retour passera
par l'endpoint HTTP lui-même (MCP Inspector, ou un test fonctionnel émettant du JSON-RPC
sur `POST /mcp`).

### (e) `league/oauth2-server` est-il compatible Symfony 8 ?

**Non tranché — le spike s'est arrêté avant**, les découvertes de (a) ayant changé la
question. Puisque le SDK fournit le resource server et un proxy de délégation, l'arbitrage
n'est plus « league ou à la main » mais « quel AS derrière le proxy ». À reprendre avec
cette formulation.

---

## La trouvaille la plus importante, que le plan n'avait pas vue

### Les expressions `security:` du projet ne sont pas portables vers un outil MCP

Déclarer l'outil avec l'expression exacte de l'opération HTTP équivalente :

```php
security: "is_granted('TRIP_VIEW', request.attributes.get('id'))"
```

fait échouer le conteneur :

```
Unable to get property "attributes" of non-object "request".
```

**Un outil MCP est évalué sans requête HTTP**, donc sans variable `request` dans le
contexte d'expression.

Double enseignement :

1. **`security:` EST bien évalué sur un outil MCP** — l'expression s'exécute, c'est ce
   qui la fait casser. Le plan avait raison sur le fond : un outil est une opération
   API Platform et passe par la même chaîne d'access checkers.
2. **Mais aucune expression du projet n'est réutilisable telle quelle.** `Trip.php`,
   `Stage.php`, `TripDetail.php`, `TripRoute.php`, `MercureToken.php` et
   `AccommodationScan.php` écrivent toutes leur autorisation objet sous la forme
   `request.attributes.get('id')` ou `request.attributes.get('tripId')`.

Le plan affirmait qu'« un outil réutilise le `security:` existant inchangé ». **C'est
faux.** Chaque outil devra exprimer son autorisation autrement — sur `object` après
chargement, ou sur l'entrée dénormalisée via `securityPostDenormalize` — et ce
ré-encodage est du travail réel, à chiffrer dans la Phase 3.

### Sémantique de sécurité à deux étages, précisée

`SecureRegistry` (`api-platform/mcp`) filtre le **listing** : un outil refusé disparaît de
`tools/list`. Mais son test `testGetToolStillReturnsReferenceForToolDeniedBySecurity`
montre que **`getTool()` rend quand même la référence** quand la sécurité refuse.

L'application réelle se fait donc à l'appel, par la chaîne de providers décorée dans
`Bundle/Resources/config/mcp/security.php` — le même `AccessCheckerProvider` et le même
`api_platform.security.resource_access_checker` que les opérations HTTP, aux quatre étages
(`pre_read`, read, `post_denormalize`, security parameter).

**Les deux étages sont distincts et tous deux nécessaires.** Un test fonctionnel par outil
reste obligatoire en Phase 3 : le filtrage de listing ne prouve pas l'application à
l'appel.

---

## Autres frictions rencontrées, toutes réelles

- **Aucune route n'est créée par la recipe.** `symfony/mcp-bundle` fournit un
  `Routing\RouteLoader` répondant à `supports($r, 'mcp')`, mais sa recipe auto-générée
  n'ajoute **pas** l'import de routes : le seul fichier créé sous `config/` a été
  `http_discovery.yaml`. Sans un `config/routes/mcp.php` écrit à la main, le transport
  HTTP est configuré et **aucune route `/mcp` n'existe** — `debug:router` ne montre rien
  et le serveur est silencieusement injoignable. Une fois l'import ajouté :
  `_mcp_endpoint_btp   GET|POST|DELETE|OPTIONS   /mcp`.
- **Le piège config-transformer est réel mais déplacé.** La recipe n'a pas généré de
  `mcp.yaml`, mais elle a bien écrit `config/packages/http_discovery.yaml` (via
  `php-http/discovery`). La convention « tout en PHP » du projet demande de le convertir.
- **Protection DNS-rebinding active par défaut.** `http.allowed_hosts` non défini
  restreint le serveur à `localhost` : exposer un serveur public impose de lister les
  hôtes, ou `false` pour désactiver. À ne pas découvrir en production.

---

## Ce que ça change pour le plan

| Point du plan | Après spike |
|---|---|
| « Vérifier si `api-platform/mcp` fournit le resource server » | **Fourni par `mcp/sdk`**, avec en prime un proxy de délégation. Phase 3A rétrécit. |
| « Ne pas écrire l'AS à la main, socle `league/oauth2-server` » | **Confirmé par l'ADR du SDK lui-même**, qui le recommande nommément. |
| « Un outil réutilise le `security:` existant inchangé » | **Faux.** Aucune expression du projet n'est portable. Travail à chiffrer. |
| « `format: json` pour éviter le bruit JSON-LD » | **Impossible sans enregistrer `json` globalement**, ce qui fait dériver le contrat REST. |
| « `debug:mcp` comme première boucle de retour » | **Inutilisable** pour les outils déclarés en `mcp:`. |
| « Le mode worker FrankenPHP est traité par `McpRegistryPass` » | **Confirmé par conception**, non vérifié sous charge. |
| Dépendances expérimentales | **Aucun conflit** sur PHP 8.5 / Symfony 8.1 / API Platform 4.3. |

**Recommandation** : poursuivre. Le transport, la découverte, la sécurité à deux étages et
le resource server sont acquis ou fournis. Le coût réel de la Phase 3 se concentre sur
deux postes que le spike a isolés — le ré-encodage des expressions d'autorisation, et
l'authorization server — et non sur le protocole.
