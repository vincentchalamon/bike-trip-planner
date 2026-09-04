# Alert engine

The backend runs a pipeline of analyzers on each stage. Three severity levels are used:

| Level | Badge | Description |
|-------|-------|-------------|
| `critical` | ![critical](https://img.shields.io/badge/-critical-d32f2f) | Blocking issue requiring immediate attention |
| `warning` | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Significant issue to watch |
| `nudge` | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Informational suggestion |

Every alert carries a stable `code` (`App\Enum\AlertCode`) identifying the rule
variant that raised it, independent of the message wording. The frontend keys
dismissals and deduplication on that code, so rephrasing a message never
resurfaces a dismissed alert. **One row below per code** — `AlertDocumentationTest`
fails if a code is emitted without a row, or if a row documents a code no longer
emitted.

Rules are executed in priority order (lower = higher priority):

| Rule | Code | Priority | Severity | Trigger |
|------|------|----------|----------|---------|
| **Continuity** | `continuity_gap_critical` | 5 | ![critical](https://img.shields.io/badge/-critical-d32f2f) | Gap > 500 m between consecutive stages |
| **Continuity** | `continuity_gap_warning` | 5 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Gap 100-500 m between stages |
| **Elevation** | `elevation_gain` | 10 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Elevation gain > 1 200 m on a stage |
| **Steep gradient** | `steep_gradient` | 20 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Sustained >= 8 % gradient over >= 500 m |
| **Surface** | `surface_rough` | 20 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Rough surface section >= 500 m: unpaved (gravel, dirt, mud, sand...) or rough paved (sett, cobblestone, paving stones); composite values like `gravel;dirt` count, and `tracktype=grade3..5` / `smoothness=bad..impassable` are used as a fallback when `surface` is absent |
| **Traffic** | `traffic_main_road` | 20 | ![critical](https://img.shields.io/badge/-critical-d32f2f) | Primary/trunk road without cycle infrastructure >= 500 m |
| **Traffic** | `traffic_secondary_road_fast` | 20 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Secondary road, no cycleway, `maxspeed` tagged and > 50 km/h |
| **Traffic** | `traffic_secondary_road_slow` | 20 | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Secondary road, no cycleway, `maxspeed` <= 50 km/h or absent/unreadable |
| **E-bike range** | `ebike_range_exceeded` | 20 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Day distance > effective range (80 km - elevation / 25); action navigates to the nearest charging station within 2 km, else suggests shortening the stage |
| **Sunset** | `sunset_arrival_after_twilight` | 20 | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Estimated arrival time exceeds civil twilight end at stage end point (times shown in the stage's local timezone) |
| **Calendar** | `calendar_public_holiday` | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Stage falls on a public holiday of a country the route crosses, for any year the trip spans (France as fallback when no boundary resolves). Named and unnamed holidays share this code |
| **Calendar** | `calendar_sunday` | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Stage falls on a Sunday (businesses may be closed) |
| **Wind** | `wind_headwind` | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Headwind >= 25 km/h on >= 60 % of stages with weather data |
| **Wind** | `wind_gusts_strong` | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Wind gusts >= 50 km/h during the riding window on at least one stage |
| **Comfort** | `comfort_poor_conditions` | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Poor comfort index (< 40/100) on at least one stage |
| **Heat** | `heat_extreme` | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Feels-like temperature >= 32 °C during the riding window on at least one stage |
| **Cold** | `cold_extreme` | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Feels-like temperature <= 2 °C during the riding window on at least one stage |
| **Rain** | `rain_heavy` | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Total precipitation >= 10 mm over the riding window on at least one stage |
| **Bike shops** | `bike_shop_none_nearby` | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | No repair resource within 2 km of stage midpoint (trips > 5 stages) |
| **Resupply** | `resupply_none_on_stage` | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Stage >= 40 km with no food/resupply POI along the route |
| **Resupply** | `resupply_closed_at_passage` | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | All resupply POIs on the stage are known to be closed at estimated passage time (a POI whose OpenStreetMap `opening_hours` is missing or unparsable is treated as unknown and suppresses the warning) |
| **Accommodation** | `accommodation_seasonal_closure` | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | All detected accommodations on the stage are likely closed due to seasonality |
| **Water points** | `water_point_gap` | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Stretch > 30 km without a detected drinking water source |
| **Rest day** | `rest_day_suggested` | 100 | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Every N consecutive cycling days without a rest day (default: every 3 days), except on the trip's last day or when the following day is already a rest day |
| **Cultural POI** | `cultural_poi_suggestion` | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Museum, monument, castle, church, viewpoint, or attraction within 500 m of route — enriched with opening hours, price and description when sourced from DataTourisme. Named and unnamed POIs share this code |
| **Railway station** | `railway_station_none_nearby` | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | No train station within 10 km of a stage endpoint (emergency evacuation) |
| **Health services** | `health_service_none_nearby` | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | No pharmacy, hospital, or clinic within 15 km of a stage |
| **Border crossing** | `border_crossing` | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Route crosses an international border (country change detected via the local PostGIS admin-boundary index) |
| **Ferry** | `ferry_crossing` | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Stage takes a ferry crossing (route runs within 100 m of an `osm.ferries` line; check schedule/booking) |
| **Ford** | `ford_crossing_dry` | -- | ![nudge](https://img.shields.io/badge/-nudge-0288d1) | Stage crosses a ford (`osm.fords` within 25 m of the route), dry weather |
| **Ford** | `ford_crossing_wet` | -- | ![warning](https://img.shields.io/badge/-warning-ed6c02) | Stage crosses a ford and rain is forecast (precipitation probability >= 50 %): possibly impassable in high water |

**Terrain rules** (Continuity, Elevation, Steep gradient, Surface, Traffic, E-bike range, Sunset, Rest day) implement `StageAnalyzerInterface` and are auto-discovered via `#[AutoconfigureTag('app.stage_analyzer')]`. Rules with `--` priority (Calendar, Wind + Comfort, Bike shops, Resupply, Accommodation, Water points, Cultural POI, Railway station, Health services, Border crossing, Ferry, Ford) are separate async Symfony Message handlers; Comfort is co-located with Wind inside `AnalyzeWindHandler`. Ford runs after the weather computation (its severity depends on the per-stage forecast).

**Rest days** are skipped by every rule that describes riding (Elevation, Steep gradient, Surface, Traffic, E-bike range, Sunset, Bike shops, Water points, Resupply). Three rules run on rest days on purpose: Continuity (a rest day duplicates the previous stage's end point, so its check is the real gap between the two ridden stages around it), Health services (evaluated at the stage midpoint, i.e. where the rider stays all day) and Calendar (a holiday closes shops whether or not you pedal).
