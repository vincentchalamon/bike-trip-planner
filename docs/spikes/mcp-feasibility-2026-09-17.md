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

**L'option de format est INERTE.** La première rédaction concluait « possible par
opération » sur la foi de la construction du conteneur. Un appel réel a démenti.

Le plan voulait passer l'enveloppe en JSON simple, le `@context`/`@id` de JSON-LD étant
du bruit dans la fenêtre de contexte d'un agent. **Les deux voies ont été essayées, et
aucune ne fonctionne :**

| Tentative | Résultat |
|---|---|
| `api_platform.mcp.format = 'json'` seul | **Refusé** : « The MCP format "json" is not configured in api_platform.formats. » |
| `json` enregistré dans `api_platform.formats` + global `mcp.format = 'json'` | Accepté, paramètre vérifié à `json` dans le conteneur, cache vidé — **sortie toujours JSON-LD** |
| `new McpTool(..., outputFormats: ['json' => ['application/json']])` | Accepté à la construction — **sortie toujours JSON-LD** |

Réponse réelle d'un appel d'outil, `content[0].text` **et** `structuredContent` :

```json
{"result":{"content":[{"type":"text","text":"{\"@context\":\"/contexts/TripDetail\",\"@type\":\"TripDetail\",…"}],
 "isError":false,
 "structuredContent":{"@context":"/contexts/TripDetail","@type":"TripDetail",…}}}
```

**Conclusion : l'enveloppe `@context`/`@type` est aujourd'hui inévitable** sur une sortie
d'outil MCP en API Platform 4.3.19. Le bruit reste modeste par réponse, mais il n'est pas
évitable, et l'option de configuration qui prétend le régler ne fait rien.

**Ce qui reste vrai de la première rédaction** : déclarer un format sur un `McpTool`
**n'impacte pas** les opérations HTTP — les exports OpenAPI avec et sans le bloc `mcp:`
sont octet pour octet identiques (431 992 octets, `cmp` silencieux). Le bucket MCP est
bien étanche ; c'est simplement que rien n'y consomme le format.

### (d) Comment teste-t-on fonctionnellement un outil MCP ?

**`debug:mcp` fonctionne parfaitement.** Une première rédaction de ce verdict concluait à
un angle mort d'outillage : c'était faux, et la vraie explication est bien meilleure.

`debug:mcp` rapportait « No MCP capabilities are registered ». Ce n'est ni un cache
périmé (`cache:clear` ne change rien) ni un défaut de câblage — la décoration est
correcte, `mcp.server.btp.registry` résout bien vers
`ApiPlatform\Mcp\Capability\Registry\SecureRegistry`, et `DebugCommand::listElements()`
appelle bien `$registry->getTools()` dessus.

**C'était le filtre de sécurité qui faisait son travail.** En CLI aucun utilisateur n'est
authentifié, donc l'expression `is_granted('ROLE_USER')` de l'outil était fausse et
`SecureRegistry` le masquait du listing — exactement
`testToolDeniedBySecurityIsOmittedFromGetTools`. Retirer l'expression fait apparaître
l'outil immédiatement :

```
Tools (1)
  Name       Handler                      Description
  get_trip   api_platform.mcp.handler()   Read one bikepacking trip: ...
```

**C'est donc une preuve positive**, et pas une déconvenue : le filtrage de sécurité au
listing fonctionne de bout en bout pour un outil déclaré via `mcp:` sur une `ApiResource`,
ce qui n'était jusque-là inféré que de tests unitaires.

Conséquence pratique à connaître : **`debug:mcp` ne montre que les outils visibles par
l'utilisateur courant**, et en CLI c'est l'anonyme. Un outil absent de la sortie n'est pas
forcément mal enregistré — il peut simplement être refusé. Le retour reste utilisable, à
condition de lire la liste comme une vue filtrée.

La question de fond — un test fonctionnel PHPUnit d'un appel d'outil — **reste ouverte** :
il faudra émettre du JSON-RPC sur `POST /mcp`, ou tester les opérations sous-jacentes.

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
faux** — mais il existe une forme portable, et elle est conçue pour ça.

### La forme portable : `object`, et le report à l'appel est délibéré

`ApiPlatform\Mcp\Security\ExpressionAccessChecker::isGranted()` passe
`['request' => $this->requestStack?->getCurrentRequest()]` — d'où le `null` en CLI. Mais
son `catch (SyntaxError)` porte ce commentaire, qui répond directement à la question :

> The expression reads variables that only exist once the element is called (object,
> previous_object, uri variables). Listing cannot decide, so the element stays visible and
> the expression is enforced on tools/call and resources/read, as AccessCheckerProvider
> already defers the pre_read stage in that case.

Autrement dit **API Platform a conçu exprès le report à l'appel** : une expression
référençant `object` est indécidable au listing, lève un `SyntaxError`, celui-ci est
attrapé, l'élément **reste visible**, et l'expression est appliquée sur `tools/call`.

**Vérifié** : `is_granted('TRIP_VIEW', object)` puis `is_granted('TRIP_VIEW', object.id)`
laissent tous deux l'outil listé, là où la forme `request.attributes.get('id')` faisait
planter la commande.

**`object.id` et non `object`** : `TripVoter::supports()` (l. 48-52) n'accepte qu'un
`TripRequest` ou une **chaîne**, pas ce DTO. Passer `object.id` évite donc de toucher au
voter.

### Pourquoi le crash n'est pas attrapé — un vrai défaut amont

Le `catch` ne couvre que `SyntaxError`, c'est-à-dire une variable **indéfinie**. Or
`request` est **défini mais null** : `request.attributes` est donc une erreur d'accès de
propriété à l'exécution (`GetAttrNode`), pas une erreur de syntaxe — elle échappe au
`catch` et fait tomber la commande.

C'est défendable comme **rapport à la core-team** : quand `getCurrentRequest()` rend
`null`, il vaudrait mieux ne pas injecter la variable `request` du tout (ce qui
produirait un `SyntaxError`, donc la dégradation gracieuse déjà prévue) plutôt que de
l'injecter à `null` et laisser exploser toute expression qui la déréférence.

### Le refactor, et sa portée exacte

`is_granted('TRIP_VIEW', object.id)` fonctionne **pour l'opération HTTP comme pour
l'outil**. Le refactor proposé est donc cohérent et sans régression de contrat.

**Portée mesurée : 19 expressions `security:` référençant `request.`**, réparties sur
7 fichiers — `Trip.php`, `Stage.php`, `TripDetail.php`, `TripRoute.php`,
`MercureToken.php`, `AccommodationScan.php` et `Entity/TripShare.php`.

**Nuance de sécurité à ne pas perdre.** `request.attributes.get('id')` est évalué **avant**
le chargement, `object.id` **après**. Avec `object`, l'objet d'autrui est donc lu en base
avant d'être refusé. Sans conséquence d'autorisation (le refus a bien lieu, et ADR-038
masque en 404), mais c'est un changement d'ordre à acter — et une raison de conserver
`security` (post-read) plutôt que de chercher à tout basculer en `pre_read`.

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
| « Vérifier si `api-platform/mcp` fournit le resource server » | **Fourni par `mcp/sdk`**, et **atteignable** en décorant `mcp.server.<name>.middleware_factory`. Phase 3A rétrécit pour de bon. |
| « Ne pas écrire l'AS à la main, socle `league/oauth2-server` » | **Confirmé par l'ADR du SDK lui-même**, qui le recommande nommément. |
| « Un outil réutilise le `security:` existant inchangé » | **Faux.** Recette en deux temps : **5 expressions → `object.id`**, **14 → sur la variable d'URI** (`Link(security: ...)`). |
| « `security:` est appliqué à l'appel » | **PROUVÉ** par test fonctionnel : propriétaire OK, autre utilisateur `Access Denied.`, anonyme 401. |
| « Le refus d'ownership ressort en *not found* (ADR-038) » | **FAUX — faille.** `Access Denied.` vs `... not found.` sont distinguables : énumération d'UUID rouverte sur le chemin MCP. |
| « Comment tester un outil MCP » | **RÉSOLU** : `ApiTestCase` + JSON-RPC sur la route, sans client MCP. En-têtes miroir obligatoires. |
| « Aucun conflit de dépendances » | Exact, mais **8 paquets transitifs** ajoutés. |
| « `format: json` pour éviter le bruit JSON-LD » | **Impossible — l'option est inerte.** Global et par-opération testés, sortie toujours JSON-LD. Le bucket MCP reste étanche côté HTTP (diff OpenAPI identique). |
| « `debug:mcp` comme première boucle de retour » | **Utilisable** — l'apparente absence d'outils était le filtre de sécurité en contexte anonyme. |
| « Le mode worker FrankenPHP est traité par `McpRegistryPass` » | **Confirmé par conception**, non vérifié sous charge. |
| Dépendances expérimentales | **Aucun conflit** sur PHP 8.5 / Symfony 8.1 / API Platform 4.3. |

## Deuxième passe — ce que la relecture critique a prouvé, et corrigé

La première rédaction inférait beaucoup depuis la lecture de code. Un test fonctionnel
réel (`api/tests/Functional/McpToolCallTest.php`, 4 tests verts) a tranché.

### ✅ PROUVÉ : `security:` est appliqué à l'appel, pas seulement au listing

C'est l'affirmation centrale du plan, et elle n'était jusque-là qu'une déduction. Deux
utilisateurs authentifiés, un voyage appartenant au premier :

| Appelant | Réponse |
|---|---|
| Propriétaire | Le voyage complet, titre inclus |
| Autre utilisateur | `{"jsonrpc":"2.0","id":1,"error":{"code":-32603,"message":"Access Denied."}}` |
| Anonyme | **401** — le firewall `api` (`pattern: ^/`) couvre `/mcp` sans une ligne de config |

Le test positif assert le titre, donc le test de refus **ne peut pas passer à vide**.

### ✅ RÉSOLU : comment tester fonctionnellement un outil MCP

**Aucun client MCP n'est nécessaire.** Le transport est une route Symfony ordinaire :
`ApiTestCase` y poste du JSON-RPC comme sur n'importe quel endpoint, avec Foundry pour la
base. C'est la réponse à la question (d), laissée ouverte en première passe.

**L'enveloppe exige des en-têtes miroir**, découverts un à un via des erreurs `-32020`
(HeaderMismatch) : `MCP-Protocol-Version`, puis `Mcp-Method`, puis `Mcp-Name`. Le corps
JSON-RPC ne suffit pas — la révision 2026-07-28 duplique version, méthode et nom d'élément
en en-têtes, ce qui permet de router ou cacher un appel en périphérie sans parser le corps.

### 🔴 FAILLE : ADR-038 ne tient pas sur le chemin MCP

Le plan affirmait qu'un refus d'ownership ressortirait en « not found », préservant
ADR-038. **C'est faux, vérifié :**

```
trip d'autrui   : Access Denied.
trip inexistant : Trip "01936f6e-0000-7000-8000-0000000009ff" not found.
```

Deux réponses **distinguables** : le chemin MCP fuite l'existence d'un voyage, soit
exactement l'énumération d'UUID qu'ADR-038 ferme côté HTTP.
`HideForbiddenAsNotFoundListener` est un listener d'exception du kernel HTTP ; le SDK
attrape l'exception et la convertit en erreur JSON-RPC sans qu'il n'intervienne.

**À traiter en Phase 3**, et ce n'est pas optionnel : le masquage doit être reproduit sur
le chemin MCP, ou ADR-038 doit acter qu'il ne couvre pas ce transport.

### ⚠️ CORRIGÉ : `object.id` n'est PAS la recette universelle

Répartition réelle des 19 expressions :

| Forme | Nombre |
|---|---|
| `is_granted('TRIP_EDIT', request.attributes.get('tripId'))` | **12** |
| `is_granted('TRIP_VIEW', request.attributes.get('id'))` | 5 |
| `is_granted('TRIP_VIEW', request.attributes.get('tripId'))` | 2 |

**14 sur 19 (74 %) portent sur `tripId`, un identifiant *parent***, pas sur l'id de
l'objet. `object.id` ne couvre que les 5 autres. Et `AccessCheckerProvider` ne fournit que
`object`, `previous_object` et `request` — **pas de `uriVariables`** — donc on ne peut pas
écrire `uriVariables['tripId']`.

**La bonne réponse est la sécurité par variable d'URI**, portée par
`SecurityParameterProvider` (présent dans la chaîne MCP sous
`api_platform.mcp.state_provider.security_parameter`) : chaque uri variable peut porter sa
propre expression, évaluée avec sa valeur liée **sous son propre nom**. `Link` accepte
`security` et `securityObjectName`, et `Stage.php` déclare déjà ses `uriVariables`.

```php
uriVariables: [
    'tripId' => new Link(fromClass: Stage::class, security: "is_granted('TRIP_EDIT', tripId)"),
    'index' => new Link(toProperty: 'dayNumber', fromClass: Stage::class),
],
```

Recette finale : **5 expressions → `object.id`** ; **14 → sur la variable d'URI**.

> **Piège à signaler à la revue de sécurité** : `SecurityParameterProvider` fait
> `continue` si `$targetResource` est introuvable (`getFromClass() ?? getToClass()`). Un
> `Link` mal configuré **saute donc le contrôle silencieusement**.

### ⚠️ CORRIGÉ : `uriVariables` doit être déclaré explicitement sur l'outil

Sans lui, l'argument de l'outil n'atteint jamais le provider : l'appel s'exécute et meurt
sur `Trip "" not found.` — un échec silencieux côté mapping, pas une erreur de
configuration visible.

### ⚠️ CORRIGÉ : « aucun conflit » minimisait le coût

Aucun conflit de *versions*, mais **8 paquets transitifs** ajoutés : `opis/json-schema`,
`opis/string`, `opis/uri`, `php-http/discovery`, `psr/http-client`,
`psr/http-server-handler`, `psr/http-server-middleware`, `psr/simple-cache`. C'est une
expansion réelle de surface de dépendances, à passer au `security-check` du projet.

### ✅ RÉSOLU : le resource server OAuth du SDK est atteignable via le bundle

La première rédaction laissait ce point en suspens, et il conditionnait tout
l'allègement de la Phase 3A. Il est levé : `McpBundle.php:399` enregistre
**`mcp.server.<name>.middleware_factory`** comme service de conteneur (vérifié :
`mcp.server.btp.middleware_factory` existe), `McpController` l'injecte, et son `create()`
alimente `new StreamableHttpTransport(...)` — dont le constructeur **accepte une pile de
middlewares custom** (`?iterable $middleware`, `null` installant les défauts).

Brancher `AuthorizationMiddleware` et `ProtectedResourceMetadataMiddleware` du SDK est
donc **une décoration Symfony standard de ce service**, sans fork ni patch.

### Ce qui reste non prouvé

- **FrankenPHP en mode worker sous charge concurrente.** Traité par conception
  (`McpRegistryPass`), jamais exercé.
- **FrankenPHP en mode worker sous charge concurrente** — seul point réellement non
  couvert. Le risque est documenté et traité par `McpRegistryPass`, et le chargement
  paresseux du registre a été vérifié en processus frais ; ce qui reste à exercer est
  le cas multi-requêtes d'un runtime persistant. Cela relève du test de charge déjà
  prévu en Phase 3, pas d'un spike.

---

## Balayage des dépendances à `Request` (demandé après la première rédaction)

Puisque `request` n'existe pas au listing MCP, qu'est-ce d'autre qui en dépend ?

- **19 expressions `security:` référençant `request.`**, sur 7 fichiers — c'est le
  périmètre du refactor vers `object.id`.
- **6 services injectent `RequestStack`.** Quatre sont hors périmètre MCP par conception
  (`AuthSessionProvider`, `AuthRequestLinkProcessor`, `AccessRequestCreateProcessor`,
  `RequestEmailChangeProcessor` — tout `/auth/*` et l'accès anticipé sont exclus des
  outils).
- Les deux qui comptent pour la Phase 3 écriture, **`TripCreateProcessor:69` et
  `TripUpdateProcessor:64`**, ne s'en servent que pour
  `getCurrentRequest()?->getPreferredLanguage(['en','fr']) ?? 'en'`, afin de stocker la
  locale du voyage. **Ce n'est pas un crash mais une dégradation silencieuse** : un appel
  d'outil arrive bien par `POST /mcp`, donc `getCurrentRequest()` n'est pas null, mais un
  agent n'envoie en général pas d'`Accept-Language` — tout voyage créé via MCP serait donc
  en `en`.

Aucune autre dépendance à `Request` n'a été trouvée dans les providers et mappers.

### La locale : deux usages à séparer

Le pipeline tourne en worker, sans requête : la locale doit donc être capturée à la
création. **Au moins 8 handlers** la relisent (`AnalyzeTerrain`, `ScanAccommodations`,
`CheckCulturalPois`, `FetchWeather`, `ResolveStageLabels`, `CheckHealthServices`,
`CheckRailwayStations`, `CheckBorderCrossing`) via
`$this->tripStateManager->getLocale($tripId) ?? 'en'`. Mais ils n'en font pas la même chose.

| Usage | Où | Sort-il du périmètre `Request` ? |
|---|---|---|
| **Rendu** — messages d'alerte et libellés d'action, traduits puis **persistés comme chaînes** (`RestDayNudgeAnalyzer:97`, `EbikeRangeAnalyzer:66` : `$translator->trans('alert.…', [], 'alerts', $locale)`) | Analyzers | **Oui — à supprimer**, voir ci-dessous |
| **Acquisition** — langue passée à un tiers : géocodage inverse (`ResolveStageLabels`), descriptions DataTourisme (`CheckCulturalPois`, `ScanAccommodations`) | Handlers | **Non** — c'est un paramètre de requête sortante, il doit rester au write time |

**Correctif immédiat, gratuit, à faire dans tous les cas** : `User` porte **déjà** une
locale (`User.php:118`, exposée par `AccountMeProvider`, déjà utilisée par
`AuthRequestLinkProcessor` et `RequestEmailChangeProcessor`). `TripCreateProcessor` et
`TripUpdateProcessor` doivent lire `$user->getLocale()` au lieu de l'en-tête HTTP. Cela
**retire `RequestStack` des deux processors**, fonctionne identiquement en HTTP et en MCP,
et est plus correct de toute façon : une préférence enregistrée vaut mieux qu'un en-tête
navigateur.

**Correctif de fond, aligné sur le principe directeur du plan** — *persister les faits,
dériver les verdicts à la lecture*. Un message traduit **est un rendu, pas un fait** :
persister `code` + paramètres structurés, rendre au read. Le projet a déjà l'identité qu'il
faut — chaque alerte porte un `AlertCode` stable, et le frontend s'y accroche déjà pour la
dédup et le dismiss.

Pour un agent, c'est **strictement meilleur qu'une traduction** : servir `SUNSET_RISK` +
`{arrivalTime, sunsetTime}` lui permet de formuler dans la langue réelle de sa
conversation, que le serveur ne peut pas connaître. Une chaîne pré-traduite en `fr`
rendue à un agent qui répond en anglais est un défaut, pas une commodité.

**Conséquence pour le lot B du plan** : « persister le payload publié verbatim » doit se
lire *verbatim moins le rendu* — on garde `code`, `type`, coordonnées et paramètres, on
cesse de figer `message` et `action.label`.

**Recommandation** : poursuivre. Le transport, la découverte, la sécurité à deux étages et
le resource server sont acquis ou fournis. Le coût réel de la Phase 3 se concentre sur
deux postes que le spike a isolés — le ré-encodage des expressions d'autorisation, et
l'authorization server — et non sur le protocole.
