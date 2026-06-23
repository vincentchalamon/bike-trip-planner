# Legal & Licensing

Project-level notes on licensing, third-party data attribution, and the GDPR posture, for
developers and operators. **This is not legal advice, and not the user-facing legal text.** The
authoritative, user-facing notices are served by the app and translated (FR/EN):

- **Privacy policy** — `/privacy` (source: `pwa/src/app/privacy/`, content in `pwa/messages/*.json`)
- **Legal notice** — `/legal` (source: `pwa/src/app/legal/`)

## Software licence

Bike Trip Planner is licensed under the **GNU Affero General Public License v3.0** (AGPL-3.0) —
see [LICENSE](../LICENSE). The AGPL network clause means that running a modified version as a
public network service obliges you to offer that version's source to its users. Contributions
are accepted under the same licence.

Third-party trademarks and content (Komoot, Strava, RideWithGPS, Garmin, Wahoo) remain the
property of their respective owners.

## Third-party data attribution

The app combines several open datasets; each keeps its own licence and attribution requirement.

| Source | Licence | Obligation |
|---|---|---|
| OpenStreetMap (Overpass, Valhalla tiles) | [ODbL 1.0](https://opendatacommons.org/licenses/odbl/) | Display "© OpenStreetMap contributors" (rendered on the map) |
| DataTourisme | [Licence Ouverte 2.0](https://www.etalab.gouv.fr/licence-ouverte-open-licence) | Credit the source; commercial use and modification allowed |
| Wikidata | [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/) | Public domain — no attribution required |
| Open-Meteo | [CC-BY 4.0](https://creativecommons.org/licenses/by/4.0/) | Credit Open-Meteo |

How each source is used and cached: [External data sources](../README.md#external-data-sources).

## GDPR posture (summary)

Authoritative text lives on the in-app `/privacy` page. Key points, as implemented:

- **Controller / contact:** the project publisher (Vincent Chalamon); `contact@bike-trip-planner.app`.
- **Legal bases:** email processing for magic-link sign-in and account management
  (Art. 6(1)(b) GDPR); anonymous audience measurement on legitimate interest (Art. 6(1)(f)).
- **Data stored:** account email; trip configuration (title, dates, rider profile, stages,
  selected accommodation). Raw imported GPS points are cached in Redis for at most 24 h, then
  deleted automatically.
- **Right to erasure** — `DELETE /users/me`: irreversibly anonymises the email, purges all trips
  (cascading to stages, chat history, shares and per-trip preferences), and revokes refresh
  tokens. Erasure is immediate; there is no purge cron. See [ADR-035](adr/adr-035-rgpd-account-erasure.md).
- **Right to portability** — `GET /users/me/export`: a JSON archive of the profile, trips and
  preferences.
- **Processors:** EU cloud hosting; a transactional email provider (magic-link emails). Open-data
  sources (OSM, weather) receive no identifying personal data.
- **Analytics:** self-hosted **Plausible** (EU) — cookieless, no fingerprinting, IP and
  User-Agent anonymised, no cross-site tracking. The script is loaded on environment
  configuration alone; no consent banner is required (legitimate interest, #572). See
  [ADR-034](adr/adr-034-usage-analytics-plausible.md).
- **Error tracking:** self-hosted **GlitchTip** (Sentry-compatible). See [ADR-031](adr/adr-031-error-tracking-strategy.md).

> The contact address (`contact@bike-trip-planner.app`) and the "host details on request" line
> are placeholders in the current build; replace them with real, monitored values before a
> public production launch.
