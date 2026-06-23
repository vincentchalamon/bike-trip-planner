# Légal & licences

Notes de niveau projet sur les licences, l'attribution des données tierces et la posture RGPD, à
destination des développeurs et opérateurs. **Ceci n'est pas un avis juridique, ni le texte légal
destiné aux utilisateurs.** Les mentions autoritaires destinées aux utilisateurs sont servies par
l'application et traduites (FR/EN) :

- **Politique de confidentialité** — `/privacy` (source : `pwa/src/app/privacy/`, contenu dans `pwa/messages/*.json`)
- **Mentions légales** — `/legal` (source : `pwa/src/app/legal/`)

## Licence logicielle

Bike Trip Planner est sous licence **GNU Affero General Public License v3.0** (AGPL-3.0) — voir
[LICENSE](../LICENSE). La clause réseau de l'AGPL impose, si vous exploitez une version modifiée
comme service réseau public, d'en proposer le code source à ses utilisateurs. Les contributions
sont acceptées sous la même licence.

Les marques et contenus tiers (Komoot, Strava, RideWithGPS, Garmin, Wahoo) restent la propriété
de leurs détenteurs respectifs.

## Attribution des données tierces

L'application combine plusieurs jeux de données ouverts ; chacun conserve sa licence et son
obligation d'attribution.

| Source | Licence | Obligation |
|---|---|---|
| OpenStreetMap (Overpass, tuiles Valhalla) | [ODbL 1.0](https://opendatacommons.org/licenses/odbl/) | Afficher « © les contributeurs OpenStreetMap » (rendu sur la carte) |
| DataTourisme | [Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence) | Créditer la source ; usage commercial et modification autorisés |
| Wikidata | [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/) | Domaine public — aucune attribution requise |
| Open-Meteo | [CC-BY 4.0](https://creativecommons.org/licenses/by/4.0/) | Créditer Open-Meteo |

Usage et cache de chaque source : [Sources de données externes](../README.fr.md#sources-de-donn%C3%A9es-externes).

## Posture RGPD (résumé)

Le texte autoritaire est la page `/privacy` de l'application. Points clés, tels qu'implémentés :

- **Responsable / contact :** l'éditeur du projet (Vincent Chalamon) ; `contact@bike-trip-planner.app`.
- **Bases légales :** traitement de l'email pour la connexion par magic link et la gestion du
  compte (art. 6(1)(b) RGPD) ; mesure d'audience anonyme sur la base de l'intérêt légitime
  (art. 6(1)(f)).
- **Données stockées :** email du compte ; configuration des voyages (titre, dates, profil
  cycliste, étapes, hébergement sélectionné). Les points GPS bruts importés sont mis en cache
  dans Redis pour 24 h maximum, puis supprimés automatiquement.
- **Droit à l'effacement** — `DELETE /users/me` : anonymise irréversiblement l'email, purge tous
  les voyages (cascade vers étapes, historique de chat, partages et préférences par voyage) et
  révoque les refresh tokens. Effacement immédiat ; pas de cron de purge. Voir
  [ADR-035](adr/adr-035-rgpd-account-erasure.md).
- **Droit à la portabilité** — `GET /users/me/export` : archive JSON du profil, des voyages et
  des préférences.
- **Sous-traitants :** hébergement cloud UE ; un fournisseur d'emails transactionnels (emails de
  connexion). Les sources open-data (OSM, météo) ne reçoivent aucune donnée personnelle
  identifiante.
- **Analytics :** **Plausible** auto-hébergé (UE) — sans cookie, sans fingerprinting, IP et
  User-Agent anonymisés, pas de suivi inter-sites. Le script est chargé selon la seule
  configuration d'environnement ; aucune bannière de consentement n'est requise (intérêt
  légitime, #572). Voir [ADR-034](adr/adr-034-usage-analytics-plausible.md).
- **Suivi des erreurs :** **GlitchTip** auto-hébergé (compatible Sentry). Voir [ADR-031](adr/adr-031-error-tracking-strategy.md).

> L'adresse de contact (`contact@bike-trip-planner.app`) et la mention « détails de l'hébergeur
> sur demande » sont des valeurs provisoires dans le build actuel ; remplacez-les par des valeurs
> réelles et surveillées avant un lancement en production publique.
