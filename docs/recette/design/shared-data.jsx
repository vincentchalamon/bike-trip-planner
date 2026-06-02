// Shared trip data for all 3 design variants.
// Same route as the README screenshot: "L'Odyssée des Eaux Royales", Lille -> Tournai.

const TRIP = {
  title: "L'Odyssée des Eaux Royales",
  source: "komoot.com/tour/2847193",
  totalKm: 143,
  totalUp: 1430,
  totalDown: 1380,
  budgetMin: 48,
  budgetMax: 80,
  dateRange: "14 — 17 mai",
  level: "Intermédiaire",
  days: 4,
  avgSpeed: 15,
  departure: "08:00",
};

const STAGES = [
  {
    day: 1,
    date: "Jeu. 14 mai",
    from: "Lille",
    fromCoord: "50.638°N, 3.050°E",
    to: "Roubaix",
    toCoord: "50.742°N, 3.602°E",
    km: 38.2,
    up: 187,
    down: 142,
    dep: "08:00",
    arr: "11:45",
    diff: "Facile",
    diffScore: 0.25,
    sunrise: "06:12",
    sunset: "21:28",
    weather: { t: 17, icon: "sun", wind: 12, windDir: "SW" },
    surface: { paved: 82, gravel: 18 },
    alerts: [
      { sev: "nudge",   icon: "info",     title: "Pause café possible à Lambersart", body: "Arrêt café/pâtisserie recommandé au km 14.2" },
      { sev: "nudge",   icon: "sparkles", title: "POI culturel — Villa Cavrois",     body: "Monument historique · ouverture 10h–18h · 9 €" },
    ],
    lodging: { name: "Le Gîte des Trois Écluses", type: "Gîte", price: "€62 / nuit", dist: "0.4 km", rating: 4.6 },
  },
  {
    day: 2,
    date: "Ven. 15 mai",
    from: "Roubaix",
    fromCoord: "50.742°N, 3.602°E",
    to: "Tournai",
    toCoord: "50.608°N, 3.389°E",
    km: 42.5,
    up: 412,
    down: 378,
    dep: "08:00",
    arr: "12:40",
    diff: "Moyen",
    diffScore: 0.55,
    sunrise: "06:10",
    sunset: "21:30",
    weather: { t: 19, icon: "partly", wind: 22, windDir: "W" },
    surface: { paved: 64, gravel: 36 },
    alerts: [
      { sev: "warning",  icon: "wind",    title: "Vent de face modéré",          body: "Headwind 22 km/h (O) sur 60% du tronçon" },
      { sev: "warning",  icon: "steep",   title: "Pente soutenue km 28–31",      body: "Gradient moyen 9,2% sur 2,8 km" },
      { sev: "nudge",    icon: "cross",   title: "Traversée de frontière",       body: "France → Belgique · pensez à votre CNI" },
    ],
    lodging: { name: "Auberge de la Grand'Place", type: "Auberge", price: "€75 / nuit", dist: "0.2 km", rating: 4.8 },
  },
  {
    day: 3,
    date: "Sam. 16 mai",
    from: "Tournai",
    fromCoord: "50.608°N, 3.389°E",
    to: "Oudenaarde",
    toCoord: "50.845°N, 3.605°E",
    km: 34.1,
    up: 486,
    down: 512,
    dep: "08:00",
    arr: "11:50",
    diff: "Moyen",
    diffScore: 0.62,
    sunrise: "06:08",
    sunset: "21:32",
    weather: { t: 21, icon: "sun", wind: 8, windDir: "S" },
    surface: { paved: 58, gravel: 38, pave: 4 },
    alerts: [
      { sev: "critical", icon: "traffic", title: "Route primaire N60 km 18–19",  body: "1,3 km sans piste cyclable — détour +2,8 km suggéré" },
      { sev: "warning",  icon: "cobble",  title: "Secteur pavé — Koppenberg",    body: "700 m de pavés irréguliers · km 24,5" },
      { sev: "nudge",    icon: "water",   title: "Point d'eau · km 12,8",        body: "Fontaine publique · qualité potable vérifiée" },
    ],
    lodging: { name: "Chambres d'hôtes Au Fil de l'Escaut", type: "Chambre d'hôtes", price: "€68 / nuit", dist: "0.6 km", rating: 4.9 },
  },
  {
    day: 4,
    date: "Dim. 17 mai",
    from: "Oudenaarde",
    fromCoord: "50.845°N, 3.605°E",
    to: "Gand",
    toCoord: "51.054°N, 3.717°E",
    km: 28.2,
    up: 198,
    down: 204,
    dep: "09:00",
    arr: "12:15",
    diff: "Facile",
    diffScore: 0.28,
    sunrise: "06:06",
    sunset: "21:34",
    weather: { t: 22, icon: "sun", wind: 6, windDir: "SE" },
    surface: { paved: 92, gravel: 8 },
    alerts: [
      { sev: "nudge",   icon: "calendar", title: "Jour férié / dimanche",        body: "Commerces possiblement fermés après 13h" },
      { sev: "nudge",   icon: "train",    title: "Gare SNCB à 1,2 km de l'arrivée", body: "Retour direct Gand → Lille (1h45)" },
    ],
    lodging: null,
  },
];

// A hand-drawn-ish route polyline for the map mockups (lat/lon coords normalised 0..1).
// Points roughly approximate the Lille → Tournai → Oudenaarde → Gand corridor.
const ROUTE = [
  [0.10, 0.72], [0.14, 0.70], [0.18, 0.66], [0.22, 0.63], [0.26, 0.60], // day 1
  [0.30, 0.58], [0.33, 0.56], [0.36, 0.58], [0.40, 0.62], [0.44, 0.64], // day 2
  [0.48, 0.60], [0.52, 0.54], [0.56, 0.48], [0.60, 0.44], [0.64, 0.40], // day 3
  [0.70, 0.34], [0.76, 0.28], [0.82, 0.22], [0.88, 0.18], [0.94, 0.14], // day 4
];

// Split into day segments for color-coded rendering.
const ROUTE_DAYS = [
  ROUTE.slice(0, 5),
  ROUTE.slice(4, 10),
  ROUTE.slice(9, 15),
  ROUTE.slice(14, 20),
];

Object.assign(window, { TRIP, STAGES, ROUTE, ROUTE_DAYS });
