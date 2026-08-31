-- Reference (PG-référence) baseline schema for Bike Trip Planner.
--
-- Split out of the former single baseline_schema.sql by the PG split (ADR-060):
-- the shared, read-only reference index (osm / tourism) plus the provisioner's own
-- bookkeeping schema. The application only ever READS these (App\Osm\*,
-- App\Tourism\*, App\InRide\InRidePoiRepository) through the `reference`
-- connection; only the provisioner writes them.
--
-- Two executors apply this one file, so every statement is IDEMPOTENT
-- (IF NOT EXISTS, inline PRIMARY KEY/CHECK):
--   1. the API test/CI harness, through the reference Doctrine migration
--      (Version20260807131000, config/migrations_reference.php), against the single
--      test database that also backs the `default` connection;
--   2. the provisioner container entrypoint (docker-entrypoint.sh), which runs it
--      via psql before the first import so the osm/tourism live tables exist.
--
-- PostGIS is REQUIRED here (public.geometry columns + ST_* queries); the PG-app
-- database needs no PostGIS. Provision the shared reference DB from a
-- postgis/postgis image (Ansible, later PR).

CREATE SCHEMA IF NOT EXISTS osm;
CREATE SCHEMA IF NOT EXISTS provisioner;
CREATE SCHEMA IF NOT EXISTS tourism;

CREATE EXTENSION IF NOT EXISTS postgis WITH SCHEMA public;

--
-- Schema: osm
--

CREATE TABLE IF NOT EXISTS osm.accommodations (
    osm_type character(1) NOT NULL,
    osm_id bigint NOT NULL,
    name text,
    category text NOT NULL,
    stars integer,
    capacity integer,
    fee text,
    website text,
    wikidata text,
    opening_hours text,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    description text,
    image_url text,
    wikipedia_url text,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT accommodations_pkey PRIMARY KEY (osm_type, osm_id),
    CONSTRAINT accommodations_named_unless_shelter CHECK (((category = 'shelter'::text) OR (NULLIF(btrim(name), ''::text) IS NOT NULL)))
);

CREATE TABLE IF NOT EXISTS osm.admin_boundaries (
    osm_id bigint NOT NULL,
    name text,
    admin_level integer,
    tags jsonb,
    geom public.geometry(MultiPolygon,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT admin_boundaries_pkey PRIMARY KEY (osm_id)
);

CREATE TABLE IF NOT EXISTS osm.bike_shops (
    osm_type character(1) NOT NULL,
    osm_id bigint NOT NULL,
    name text,
    category text NOT NULL,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT bike_shops_pkey PRIMARY KEY (osm_type, osm_id)
);

CREATE TABLE IF NOT EXISTS osm.charging_stations (
    osm_type character(1) NOT NULL,
    osm_id bigint NOT NULL,
    name text,
    category text NOT NULL,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT charging_stations_pkey PRIMARY KEY (osm_type, osm_id)
);

CREATE TABLE IF NOT EXISTS osm.coverage (
    geom public.geometry(MultiPolygon,4326)
);

CREATE TABLE IF NOT EXISTS osm.cultural_pois (
    osm_type character(1) NOT NULL,
    osm_id bigint NOT NULL,
    name text,
    category text NOT NULL,
    wikidata text,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    description text,
    opening_hours text,
    website text,
    image_url text,
    wikipedia_url text,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT cultural_pois_pkey PRIMARY KEY (osm_type, osm_id)
);

CREATE TABLE IF NOT EXISTS osm.cycle_routes (
    osm_id bigint NOT NULL,
    name text,
    network text,
    ref text,
    tags jsonb,
    geom public.geometry(MultiLineString,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT cycle_routes_pkey PRIMARY KEY (osm_id)
);

CREATE TABLE IF NOT EXISTS osm.ferries (
    osm_type character(1) NOT NULL,
    osm_id bigint NOT NULL,
    name text,
    tags jsonb,
    geom public.geometry(Geometry,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT ferries_pkey PRIMARY KEY (osm_type, osm_id)
);

CREATE TABLE IF NOT EXISTS osm.fords (
    osm_type character(1) NOT NULL,
    osm_id bigint NOT NULL,
    name text,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT fords_pkey PRIMARY KEY (osm_type, osm_id)
);

CREATE TABLE IF NOT EXISTS osm.health_services (
    osm_type character(1) NOT NULL,
    osm_id bigint NOT NULL,
    name text,
    category text NOT NULL,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT health_services_pkey PRIMARY KEY (osm_type, osm_id)
);

CREATE TABLE IF NOT EXISTS osm.metadata (
    refreshed_at timestamp with time zone,
    feature_counts jsonb,
    completeness jsonb,
    rejections jsonb
);

CREATE TABLE IF NOT EXISTS osm.pois (
    osm_type character(1) NOT NULL,
    osm_id bigint NOT NULL,
    name text,
    category text NOT NULL,
    opening_hours text,
    website text,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT pois_pkey PRIMARY KEY (osm_type, osm_id)
);

CREATE TABLE IF NOT EXISTS osm.railway_stations (
    osm_type character(1) NOT NULL,
    osm_id bigint NOT NULL,
    name text,
    category text NOT NULL,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT railway_stations_pkey PRIMARY KEY (osm_type, osm_id)
);

CREATE TABLE IF NOT EXISTS osm.routing_perimeter (
    slug text NOT NULL,
    observed_at timestamp with time zone NOT NULL,
    CONSTRAINT routing_perimeter_pkey PRIMARY KEY (slug)
);

CREATE TABLE IF NOT EXISTS osm.water_points (
    osm_type character(1) NOT NULL,
    osm_id bigint NOT NULL,
    name text,
    category text NOT NULL,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT water_points_pkey PRIMARY KEY (osm_type, osm_id)
);

CREATE TABLE IF NOT EXISTS osm.ways (
    osm_id bigint NOT NULL,
    tags jsonb,
    geom public.geometry(LineString,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT ways_pkey PRIMARY KEY (osm_id)
);

CREATE TABLE IF NOT EXISTS osm.zones (
    slug text NOT NULL,
    name text NOT NULL,
    country text NOT NULL,
    opened_at timestamp with time zone NOT NULL,
    refreshed_at timestamp with time zone NOT NULL,
    pipeline_version integer NOT NULL,
    feature_counts jsonb DEFAULT '{}'::jsonb NOT NULL,
    new_entries jsonb DEFAULT '{}'::jsonb NOT NULL,
    geom public.geometry(MultiPolygon,4326),
    CONSTRAINT zones_pkey PRIMARY KEY (slug)
);

--
-- Schema: provisioner
--

CREATE TABLE IF NOT EXISTS provisioner.place_enrichment (
    source text NOT NULL,
    source_id text NOT NULL,
    payload jsonb NOT NULL,
    status text NOT NULL,
    resolver_version integer NOT NULL,
    fetched_at timestamp with time zone NOT NULL,
    CONSTRAINT place_enrichment_pkey PRIMARY KEY (source, source_id)
);

CREATE TABLE IF NOT EXISTS provisioner.wikidata_cache (
    qid text NOT NULL,
    payload jsonb NOT NULL,
    fetched_at timestamp with time zone NOT NULL,
    CONSTRAINT wikidata_cache_pkey PRIMARY KEY (qid)
);

--
-- Schema: tourism
--

CREATE TABLE IF NOT EXISTS tourism.accommodations (
    id text NOT NULL,
    name text,
    category text NOT NULL,
    capacity integer,
    price numeric(10,2),
    description text,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    opening_hours text,
    website text,
    phone text,
    image_url text,
    wikipedia_url text,
    wikidata text,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT tourism_accommodations_pkey PRIMARY KEY (id),
    CONSTRAINT accommodations_named CHECK ((NULLIF(btrim(name), ''::text) IS NOT NULL))
);

CREATE TABLE IF NOT EXISTS tourism.cultural_pois (
    id text NOT NULL,
    name text,
    category text NOT NULL,
    opening_hours text,
    description text,
    wikidata text,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    website text,
    image_url text,
    wikipedia_url text,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT tourism_cultural_pois_pkey PRIMARY KEY (id)
);

-- The `source` column (multi-source events, ADR-051) is folded inline here: it was
-- a separate follow-up migration on the single database, unnecessary greenfield.
CREATE TABLE IF NOT EXISTS tourism.events (
    id text NOT NULL,
    name text,
    category text NOT NULL,
    start_date date,
    end_date date,
    url text,
    description text,
    price_min numeric(10,2),
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    zone text,
    last_seen_at timestamp with time zone,
    source text NOT NULL DEFAULT 'datatourisme',
    CONSTRAINT events_pkey PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS tourism.food_pois (
    id text NOT NULL,
    name text,
    category text NOT NULL,
    opening_hours text,
    description text,
    wikidata text,
    tags jsonb,
    geom public.geometry(Point,4326) NOT NULL,
    website text,
    image_url text,
    wikipedia_url text,
    zone text,
    last_seen_at timestamp with time zone,
    CONSTRAINT tourism_food_pois_pkey PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS tourism.metadata (
    refreshed_at timestamp with time zone,
    feature_counts jsonb,
    completeness jsonb,
    rejections jsonb
);

--
-- Indexes
--

CREATE INDEX IF NOT EXISTS accommodations_category_idx ON osm.accommodations USING btree (category);
CREATE INDEX IF NOT EXISTS accommodations_geom_idx ON osm.accommodations USING gist (geom);
CREATE INDEX IF NOT EXISTS admin_boundaries_geom_idx ON osm.admin_boundaries USING gist (geom);
CREATE INDEX IF NOT EXISTS bike_shops_geom_idx ON osm.bike_shops USING gist (geom);
CREATE INDEX IF NOT EXISTS charging_stations_geom_idx ON osm.charging_stations USING gist (geom);
CREATE INDEX IF NOT EXISTS coverage_geom_idx ON osm.coverage USING gist (geom);
CREATE INDEX IF NOT EXISTS cultural_pois_geom_idx ON osm.cultural_pois USING gist (geom);
CREATE INDEX IF NOT EXISTS cycle_routes_geom_idx ON osm.cycle_routes USING gist (geom);
CREATE INDEX IF NOT EXISTS ferries_geom_idx ON osm.ferries USING gist (geom);
CREATE INDEX IF NOT EXISTS fords_geom_idx ON osm.fords USING gist (geom);
CREATE INDEX IF NOT EXISTS health_services_geom_idx ON osm.health_services USING gist (geom);
CREATE INDEX IF NOT EXISTS pois_category_idx ON osm.pois USING btree (category);
CREATE INDEX IF NOT EXISTS pois_geom_idx ON osm.pois USING gist (geom);
CREATE INDEX IF NOT EXISTS railway_stations_geom_idx ON osm.railway_stations USING gist (geom);
CREATE INDEX IF NOT EXISTS water_points_geom_idx ON osm.water_points USING gist (geom);
CREATE INDEX IF NOT EXISTS ways_geom_idx ON osm.ways USING gist (geom);
CREATE INDEX IF NOT EXISTS zones_geom_idx ON osm.zones USING gist (geom);
CREATE INDEX IF NOT EXISTS place_enrichment_status_version_idx ON provisioner.place_enrichment USING btree (status, resolver_version);
CREATE INDEX IF NOT EXISTS wikidata_cache_fetched_at_idx ON provisioner.wikidata_cache USING btree (fetched_at);
CREATE INDEX IF NOT EXISTS accommodations_geom_idx ON tourism.accommodations USING gist (geom);
CREATE INDEX IF NOT EXISTS cultural_pois_geom_idx ON tourism.cultural_pois USING gist (geom);
CREATE INDEX IF NOT EXISTS events_dates_idx ON tourism.events USING btree (start_date, end_date);
CREATE INDEX IF NOT EXISTS events_geom_idx ON tourism.events USING gist (geom);
CREATE INDEX IF NOT EXISTS food_pois_geom_idx ON tourism.food_pois USING gist (geom);
