-- osm2pgsql flex style for the local-first Tier-1 reference index (ADR-040).
--
-- Imports the bikepacking-relevant OSM features into a dedicated staging schema
-- as PostGIS point geometries (SRID 4326), which the importer then swaps onto
-- the live `osm` schema atomically. The API reads these tables via ST_DWithin
-- corridor queries, replacing the runtime Overpass dependency.
--
-- Scope of this style: pois, accommodations, water_points, bike_shops, health_services. The
-- admin_boundaries (coverage polygon) and cycle_routes tables land later.

-- MUST match PostgisImporter::STAGING_SCHEMA: osm2pgsql writes the output tables
-- here, and the importer creates/swaps this exact schema onto the live `osm`.
local SCHEMA = 'osm_staging'
local SRID = 4326

-- Accommodation tourism values the app supports (TripRequest::ALL_ACCOMMODATION_TYPES);
-- `shelter` is amenity=shelter and handled separately below.
local ACCOMMODATION_TOURISM = {
    hotel = true, hostel = true, guest_house = true, motel = true,
    chalet = true, camp_site = true, alpine_hut = true, wilderness_hut = true,
    apartment = true,
}

-- Resupply / point-of-interest categories (food, shops, services, sights).
local POI_AMENITY = {
    restaurant = true, cafe = true, bar = true, pub = true, fast_food = true,
    marketplace = true, pharmacy = true,
}
local POI_SHOP = {
    supermarket = true, convenience = true, bakery = true, butcher = true,
    greengrocer = true, deli = true, general = true, pastry = true, farm = true,
}
local POI_TOURISM = {
    viewpoint = true, attraction = true,
}

local pois = osm2pgsql.define_table({
    name = 'pois',
    schema = SCHEMA,
    ids = { type = 'any', id_column = 'osm_id', type_column = 'osm_type' },
    columns = {
        { column = 'name', type = 'text' },
        { column = 'category', type = 'text', not_null = true },
        { column = 'opening_hours', type = 'text' },
        { column = 'website', type = 'text' },
        { column = 'tags', type = 'jsonb' },
        { column = 'geom', type = 'point', projection = SRID, not_null = true },
    },
    indexes = {
        { column = 'geom', method = 'gist' },
        { column = 'category', method = 'btree' },
    },
})

local accommodations = osm2pgsql.define_table({
    name = 'accommodations',
    schema = SCHEMA,
    ids = { type = 'any', id_column = 'osm_id', type_column = 'osm_type' },
    columns = {
        { column = 'name', type = 'text' },
        { column = 'category', type = 'text', not_null = true },
        { column = 'stars', type = 'int' },
        { column = 'capacity', type = 'int' },
        { column = 'fee', type = 'text' },
        { column = 'website', type = 'text' },
        { column = 'wikidata', type = 'text' },
        { column = 'opening_hours', type = 'text' },
        { column = 'tags', type = 'jsonb' },
        { column = 'geom', type = 'point', projection = SRID, not_null = true },
    },
    indexes = {
        { column = 'geom', method = 'gist' },
        { column = 'category', method = 'btree' },
    },
})

local water_points = osm2pgsql.define_table({
    name = 'water_points',
    schema = SCHEMA,
    ids = { type = 'any', id_column = 'osm_id', type_column = 'osm_type' },
    columns = {
        { column = 'name', type = 'text' },
        { column = 'category', type = 'text', not_null = true },
        { column = 'tags', type = 'jsonb' },
        { column = 'geom', type = 'point', projection = SRID, not_null = true },
    },
    indexes = {
        { column = 'geom', method = 'gist' },
    },
})

local bike_shops = osm2pgsql.define_table({
    name = 'bike_shops',
    schema = SCHEMA,
    ids = { type = 'any', id_column = 'osm_id', type_column = 'osm_type' },
    columns = {
        { column = 'name', type = 'text' },
        { column = 'category', type = 'text', not_null = true },
        { column = 'tags', type = 'jsonb' },
        { column = 'geom', type = 'point', projection = SRID, not_null = true },
    },
    indexes = {
        { column = 'geom', method = 'gist' },
    },
})

local health_services = osm2pgsql.define_table({
    name = 'health_services',
    schema = SCHEMA,
    ids = { type = 'any', id_column = 'osm_id', type_column = 'osm_type' },
    columns = {
        { column = 'name', type = 'text' },
        { column = 'category', type = 'text', not_null = true },
        { column = 'tags', type = 'jsonb' },
        { column = 'geom', type = 'point', projection = SRID, not_null = true },
    },
    indexes = {
        { column = 'geom', method = 'gist' },
    },
})

local function poi_category(tags)
    if tags.amenity and POI_AMENITY[tags.amenity] then return tags.amenity end
    if tags.shop and POI_SHOP[tags.shop] then return tags.shop end
    if tags.tourism and POI_TOURISM[tags.tourism] then return tags.tourism end
    return nil
end

local function accommodation_category(tags)
    if tags.tourism and ACCOMMODATION_TOURISM[tags.tourism] then return tags.tourism end
    if tags.amenity == 'shelter' then return 'shelter' end
    return nil
end

-- Real drinking water (replaces the cemetery proxy).
local function water_category(tags)
    if tags.amenity == 'drinking_water' then return 'drinking_water' end
    if tags.amenity == 'water_point' then return 'water_point' end
    if tags.man_made == 'water_tap' then return 'water_tap' end
    if tags.amenity == 'fountain' and tags.drinking_water == 'yes' then return 'fountain' end
    if tags.natural == 'spring' and tags.drinking_water == 'yes' then return 'spring' end
    return nil
end

-- Bicycle shops and generic outlets advertising repair (service:bicycle:repair=yes);
-- the repair flag is preserved in the raw tags for the read side to distinguish them.
local function bike_shop_category(tags)
    if tags.shop == 'bicycle' then return 'bicycle' end
    if tags['service:bicycle:repair'] == 'yes' then return 'repair_station' end
    return nil
end

-- Health services: pharmacies, hospitals and clinics.
local function health_category(tags)
    if tags.amenity == 'pharmacy' or tags.amenity == 'hospital' or tags.amenity == 'clinic' then
        return tags.amenity
    end
    return nil
end

local function is_relevant(tags)
    return poi_category(tags) ~= nil
        or accommodation_category(tags) ~= nil
        or water_category(tags) ~= nil
        or bike_shop_category(tags) ~= nil
        or health_category(tags) ~= nil
end

-- Safe integer coercion (works on both Lua 5.x and LuaJIT builds of osm2pgsql).
local function to_int(v)
    local n = tonumber(v)
    if n == nil then return nil end
    return math.floor(n)
end

local function insert_features(tags, geom)
    local cat = poi_category(tags)
    if cat then
        pois:insert({
            name = tags.name,
            category = cat,
            opening_hours = tags.opening_hours,
            website = tags.website,
            tags = tags,
            geom = geom,
        })
    end

    local acc = accommodation_category(tags)
    if acc then
        accommodations:insert({
            name = tags.name,
            category = acc,
            stars = to_int(tags.stars),
            capacity = to_int(tags.capacity),
            fee = tags.fee or tags.charge,
            website = tags.website,
            wikidata = tags.wikidata,
            opening_hours = tags.opening_hours,
            tags = tags,
            geom = geom,
        })
    end

    local wcat = water_category(tags)
    if wcat then
        water_points:insert({
            name = tags.name,
            category = wcat,
            tags = tags,
            geom = geom,
        })
    end

    local bcat = bike_shop_category(tags)
    if bcat then
        bike_shops:insert({
            name = tags.name,
            category = bcat,
            tags = tags,
            geom = geom,
        })
    end

    local hcat = health_category(tags)
    if hcat then
        health_services:insert({
            name = tags.name,
            category = hcat,
            tags = tags,
            geom = geom,
        })
    end
end

-- Centroid point of a way (closed -> polygon centroid, otherwise line centroid).
-- Guarded: a degenerate/invalid geometry yields nil and the feature is skipped.
local function way_centroid(object)
    local ok, geom = pcall(function()
        if object.is_closed then
            return object:as_polygon():centroid()
        end
        return object:as_linestring():centroid()
    end)
    if ok then return geom end
    return nil
end

function osm2pgsql.process_node(object)
    -- tags-filter keeps untagged nodes referenced by ways; skip them here.
    if not is_relevant(object.tags) then return end
    insert_features(object.tags, object:as_point())
end

function osm2pgsql.process_way(object)
    if not is_relevant(object.tags) then return end
    local geom = way_centroid(object)
    if geom == nil then return end
    insert_features(object.tags, geom)
end
