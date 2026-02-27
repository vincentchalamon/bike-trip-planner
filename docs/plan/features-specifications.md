# FEATURES SPECIFICATIONS

Bike Trip Planner is an intelligent bikepacking trip generator designed to simplify logistical and safety planning. The application transforms a route intent (Komoot URL/GPX) into a detailed roadbook, anticipating the cyclist's needs (water, fatigue, safety, resupply) without requiring a database (local-first approach).

## LOT 1

### Ingestion Engine: The "Magic Link"

The interface must present a single smart input field capable of discriminating the source type.

#### Impact on the Application

Filling this field triggers a chain of promises:

1. **Backend:** Extraction of raw data (GPX/JSON) via the PHP engine.
2. **Frontend:** Instant hydration of the global store (Zustand) and update of all UI components (title, dates, stages).

#### Detailed Scenario

- **Action:** The user pastes `https://www.komoot.com/fr-fr/tour/12345`.
- **Reaction:** The application displays a discreet loader. Within 1.5s, the title "Sortie avec les copains" appears, the dates default to the upcoming weekend, and 3 stage cards are generated with distances and elevation gain already calculated.

#### Business Rules (BR)

- **BR-01:** Any invalid or password-protected Komoot URL must return an explicit error notification without blocking the interface.
- **BR-02:** If the source contains waypoints, they are automatically categorized by the backend (e.g., "Monument", "Viewpoint").

#### US #1: Smart Ingestion via "Magic Link"

**As a** cyclist, **I want** to paste a Komoot URL into a single field so the application automatically pre-fills my entire trip structure.

##### Acceptance Criteria (AC)

1. **Validation:** The field must validate that the URL comes from the `komoot.com` domain.
2. **Backend Parsing:** The PHP service must extract:
    - The tour title.
    - The total distance (in **km**).
    - The cumulative elevation gain (in **m**).
    - The full trace (array of `[lat, lng]` coordinates).
3. **Hydration:** The frontend store must be updated without page reload.

##### Tests

- **Functional (PHPUnit):** `KomootParserTest` must validate that a test URL returns a `TripDTO` object with a distance of **125 km** and an elevation gain of **1200 m**.
- **E2E (Playwright):**
  - Enter the URL in the input field.
  - Verify that the `SummaryCard` component displays "125 km" and "1200 m D+".
  - Verify that an error is displayed if the URL is of type `google.com`.

---

### The "Pacing Engine" (Progression Algorithm)

It defines the temporal structure of the trip by managing cumulative fatigue.

#### Impact on the Application

This engine dynamically modifies the suggested stage lengths. If the user changes the trip duration (e.g., from 3 to 4 days), all stages are recalculated in real time.

#### Mathematical Fatigue Formula

The target distance for day $`n`$ ($`Dn`$) is defined by:

$`Dn = (Dbase x P^(n-1)) - (D+n / 50)`$

- $`Dbase`$: Theoretical average distance (Total Distance / Number of Days).
- $`P`$: Fatigue factor (0.9, i.e., -10% per day).
- $`D+n`$: Elevation gain for stage $`n`$ in meters.

#### Detailed Scenario

- **Context:** 3-day trip, 240 km total, flat terrain.
- **Calculation:** D1 = 80 km. D2 = 72 km. D3 = 64 km (Total 216 km). The remainder (24 km) is distributed evenly or added to D1 based on user preference.
- **Adjustment:** If D2 includes a 1000 m D+ climb, its distance is reduced by 20 km (1000/50 x 1), and those kilometers are shifted to D1.

#### Business Rules (BR)

- **BR-03:** The minimum distance for a "full" stage is set at 30 km.
- **BR-04:** The algorithm must always prioritize arriving at a regional train station (TER) (see below) if one is located within +/-5 km of the theoretical endpoint.

#### US #2: Stage Generation (Pacing Engine)

**As a** planner, **I want** my route to be split into realistic stages that account for accumulated fatigue over the days.

##### Acceptance Criteria (AC)

1. **Formula application:** For a trip of $`n`$ days, each stage $`i`$ must have a target distance $`Di`$ calculated as:
   $`Di = (Dbase x 0.9^(i-1)) - (D+i / 50)`$
2. **Remainder:** If the sum of $`Di`$ is less than the total distance, the difference is added evenly to the first two stages.
3. **Reactivity:** If the user manually changes the number of days, the engine instantly recalculates the breakdown.

##### Tests

- **Functional (PHPUnit):** `PacingServiceTest` simulating a 3-day trip of **200 km** with no elevation. Verify that D1 > D2 > D3.
- **E2E (Playwright):**
  - Load a trip.
  - Change the number of days from 2 to 4 via a `Select`.
  - Verify that the number of `StageCard` cards changes from 2 to 4.

---

### Survival & Safety Intelligence (OSM Scanner)

This module performs spatial queries along the route to identify critical points.

#### Impact on the Application

Each stage displays a "Alerts & Services" section grouping interactive badges.

#### Scenarios by Feature

- **Regional Train Stations (The Backbone):** The application detects a station 2 km from the route in the middle of D2. It displays: *"Fallback station detected (TER - Gare de l'Arbresle)"*. A link redirects to SNCF schedules.
- **Hydration (Cemeteries):** On a 40 km stretch without a village, the app detects a cemetery 200 m from the route. It adds a suggested waypoint named *"Potential water point (Cemetery)"*.
- **Hazards & Surface:** The user selected "Road Bike". The route follows 3 km of forest trail (`surface=unpaved`). An orange alert appears: *"Warning: Unpaved section (3 km). Risk of puncture/discomfort"*.
- **Bridges & Complications:** Detection of a ferry crossing the Seine. Alert: *"Ferry crossing. Check departure times to avoid a 1-hour wait"*.
- **National Cycling Network:** The route runs alongside the Véloscénie (V40). The app suggests: *"You are 800 m from the V40. Would you like to detour to enjoy a greenway?"*.

#### Business Rules (BR)

- **BR-05:** A "Cemetery" is only displayed as a water point if it is located within 500 m of the route.
- **BR-06:** The "Bike Shop Watch" (`shop=bicycle`) only activates if `NumberOfDays > 5`. It identifies bike shops within a 5 km radius of each stage endpoint.

#### US #3: Survival Scanner (Water Points and Stations)

**As a** safety-conscious cyclist, **I want** to see water points (cemeteries) and regional train stations on my route to ensure my safety.

##### Acceptance Criteria (AC)

1. **OSM Scanning:** The backend must query the Overpass API to identify nodes with `amenity=grave_yard` or `railway=station` within a **500 m** buffer for water and **10 km** for stations around each stage endpoint.
2. **Display:** Results must appear as a clickable list within each stage.
3. **Regional Stations:** Only stations of type `usage=main` or `branch` (TER) must be retained.

##### Tests

- **Functional (PHPUnit):** `OsmScannerTest` with a mocked Overpass response containing a station and a cemetery. Verify that the service returns 2 POIs.
- **E2E (Playwright):**
  - Open the detail view of Stage 1.
  - Verify the presence of a "Water" icon and a "Train" icon.
  - Click on the station and verify that an external link to SNCF Connect opens in a new tab.

#### US #4: Danger and Terrain Alerts

**As a** cyclist, **I want** to be alerted if my route follows dangerous roads or paths unsuitable for my bike.

##### Acceptance Criteria (AC)

1. **Terrain Analysis:** If `profile = road_bike`, trigger an alert if `surface` differs from `asphalt` or `paved` for more than **500 m**.
2. **Traffic Analysis:** Trigger a "Traffic Danger" alert on segments tagged `highway=primary` or `secondary` without a `cycleway` tag.
3. **Elevation Threshold:** If a stage has D+ > 1200 m, display an "Intense Effort" alert.

##### Tests

- **Functional (PHPUnit):** `SafetyAnalyzerTest` receiving a national road segment without a cycle lane. Must return an `Alert` of type `CRITICAL`.
- **E2E (Playwright):**
  - Select the "Road Bike" profile.
  - Verify that an orange banner "Warning: Unpaved terrain" appears on the relevant stages.

---

### Contextual Logistics: "The Nudge"

The application analyzes "gaps" in the planning to suggest content.

#### Impact on the Application

If the user has not set a lunch stop (type "Point of Interest" or "Accommodation"), the interface "pushes" specific suggestions.

#### Detailed Scenario

- **Context:** Stage 3, no stop planned between 11 AM and 2 PM. The day is a Tuesday.
- **Reaction:** The app displays a banner below the stage: *"No lunch planned? The [Town Name] Market takes place this Tuesday morning on your route. There's also a shaded picnic area at 12:30 PM."*

#### Business Rules (BR)

- **BR-07 (Weather/Wind):** If the wind direction opposes the travel vector for more than 60% of the stage with a speed > 25 km/h, display the alert: *"Dominant headwind: Physical effort increased by 15%."*
- **BR-08 (Public Holidays):** Uses the `Yasumi` library. If the date is May 1st or December 25th, a critical alert is displayed: *"Shops closed: Plan for full food self-sufficiency."*

#### US #5: Resupply Nudge (Markets and Picnic Areas)

**As a** traveler, **I want** the application to suggest lunch options if I haven't planned any.

##### Acceptance Criteria (AC)

1. **Activation Condition:** The scan activates only if no POI of category `Lunch` or `Market` is manually present in the stage.
2. **Markets:** Query OSM for `amenity=marketplace`. Check if the stage day matches the market's opening day (if the info is available as a tag; otherwise display "Check market day").
3. **Picnic Areas:** Identify `amenity=picnic_site` with `shelter=yes` or `natural=tree` (shade).

##### Tests

- **Functional (PHPUnit):** `NudgeServiceTest` verifying that if a "Lunch POI" is added, the automatic suggestions disappear from the JSON response.
- **E2E (Playwright):**
  - Verify the "Lunch Suggestions" section on an empty stage.
  - Manually add a restaurant.
  - Verify that the suggestions section disappears.

---

### Exports: Digital Roadbook

The culmination of the process is a summary document.

#### Impact on the Application

The export generates a PDF file optimized for smartphone reading (portrait format, clickable links).

#### Document Contents

1. **Global Summary:** Title, dates, total distance, total elevation gain.
2. **Stage Sheets:**
    - Expected weather (icon + temperature).
    - Clickable link to the Komoot/GPX route.
    - List of water points (Cemeteries) with distance from the start.
    - Regional train station (TER) coordinates for the stage.
3. **Logistics:** Name of the selected accommodation, link to its website, indicative price.

#### Business Rules (BR)

- **BR-09:** The PDF export must not contain any QR codes (digital usage preferred). All links must be underlined and in standard blue color.
- **BR-10:** The exported JSON format must be "versioned" to ensure forward compatibility with future app updates.

#### US #6: PDF Roadbook Export

**As a** cyclist, **I want** to export my trip as a PDF so I can view it on my phone without a connection.

##### Acceptance Criteria (AC)

1. **Format:** The PDF must be in vertical A4 format, readable on mobile.
2. **Links:** All links (Komoot, Stations, Hotels) must be clickable (underlined blue URLs).
3. **Content:** Must include the weather summary, safety alerts, and the list of water points per stage.

##### Tests

- **Functional (PHPUnit):** `PdfGeneratorTest` verifying that the generated file starts with the PDF header (`%PDF-`).
- **E2E (Playwright):**
  - Click the "Export as PDF" button.
  - Verify that the download triggers and that the filename contains the trip title.

---

## APPENDICES

### Appendix A: Lot 2 (Visual Experience)

- Full-screen interactive map (MapLibre GL JS).
- Dynamic elevation profile with synchronized cursor.
- "Split-view" mode (Map on left / Form on right).
- Advanced filters for accommodations (Secure garages, e-bike charging).

### Appendix B: Lot 3 (AI Assistant)

- "Magic Prompt" field: *"Plan me a 2-day tour around Mont Ventoux"*.
- Natural language transformation into structured JSON object.
- Contextual tourist suggestions (e.g., *"Take the opportunity to taste the strawberries of Carpentras at this time of year"*).
