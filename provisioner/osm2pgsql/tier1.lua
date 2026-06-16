-- osm2pgsql flex style for the local-first Tier-1 reference index (ADR-040).
--
-- Imports the bikepacking-relevant OSM features into a dedicated staging schema
-- as PostGIS point geometries (SRID 4326), which the importer then swaps onto
-- the live `osm` schema atomically. The API reads these tables via ST_DWithin
-- corridor queries, replacing the runtime Overpass dependency.
--
-- Scope of this style: pois, accommodations, water_points, bike_shops, health_services, railway_stations, charging_stations, cultural_pois, ways, admin_boundaries, cycle_routes, ferries.

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
    marketplace = true, pharmacy = true, fuel = true,
}
local POI_SHOP = {
    supermarket = true, convenience = true, bakery = true, butcher = true,
    greengrocer = true, deli = true, general = true, pastry = true, farm = true,
}
local POI_TOURISM = {
    viewpoint = true, attraction = true,
}

-- Cultural points of interest (museums, monuments, historic sites) for the
-- cultural-POI suggestion alert.
local CULTURAL_TOURISM = {
    museum = true, attraction = true, viewpoint = true,
}
local CULTURAL_HISTORIC = {
    castle = true, monument = true, memorial = true, ruins = true,
    archaeological_site = true, church = true, cathedral = true, abbey = true, fort = true,
}

-- Road/path highway values analysed for surface + traffic (stored as linestrings).
local WAY_HIGHWAY = {
    primary = true, secondary = true, tertiary = true, unclassified = true,
    residential = true, living_street = true, service = true, track = true,
    path = true, cycleway = true, footway = true, bridleway = true,
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

local railway_stations = osm2pgsql.define_table({
    name = 'railway_stations',
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

local charging_stations = osm2pgsql.define_table({
    name = 'charging_stations',
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

local cultural_pois = osm2pgsql.define_table({
    name = 'cultural_pois',
    schema = SCHEMA,
    ids = { type = 'any', id_column = 'osm_id', type_column = 'osm_type' },
    columns = {
        { column = 'name', type = 'text' },
        { column = 'category', type = 'text', not_null = true },
        { column = 'wikidata', type = 'text' },
        { column = 'tags', type = 'jsonb' },
        { column = 'geom', type = 'point', projection = SRID, not_null = true },
    },
    indexes = {
        { column = 'geom', method = 'gist' },
    },
})

local ways = osm2pgsql.define_table({
    name = 'ways',
    schema = SCHEMA,
    ids = { type = 'way', id_column = 'osm_id' },
    columns = {
        { column = 'tags', type = 'jsonb' },
        { column = 'geom', type = 'linestring', projection = SRID, not_null = true },
    },
    indexes = {
        { column = 'geom', method = 'gist' },
    },
})

-- Ferry crossings (ways tagged route=ferry), stored as linestrings. The API
-- flags stages whose route runs along one (the ferry-crossing alert).
local ferries = osm2pgsql.define_table({
    name = 'ferries',
    schema = SCHEMA,
    ids = { type = 'way', id_column = 'osm_id' },
    columns = {
        { column = 'name', type = 'text' },
        { column = 'tags', type = 'jsonb' },
        { column = 'geom', type = 'linestring', projection = SRID, not_null = true },
    },
    indexes = {
        { column = 'geom', method = 'gist' },
    },
})

-- Country boundaries (admin_level=2), stored as multipolygons. The API resolves
-- the country at a point via ST_Covers, replacing the runtime Overpass is_in
-- query; their union also forms the coverage polygon.
local admin_boundaries = osm2pgsql.define_table({
    name = 'admin_boundaries',
    schema = SCHEMA,
    ids = { type = 'relation', id_column = 'osm_id' },
    columns = {
        { column = 'name', type = 'text' },
        { column = 'admin_level', type = 'int' },
        { column = 'tags', type = 'jsonb' },
        { column = 'geom', type = 'multipolygon', projection = SRID, not_null = true },
    },
    indexes = {
        { column = 'geom', method = 'gist' },
    },
})

-- Signed cycle routes (relations type=route, route=bicycle: EuroVelo, national
-- (ncn) / regional (rcn) / local (lcn) networks, voies vertes), stored as
-- multilinestrings. The API measures how much of a stage follows one (the
-- "on cycle network" indicator).
local cycle_routes = osm2pgsql.define_table({
    name = 'cycle_routes',
    schema = SCHEMA,
    ids = { type = 'relation', id_column = 'osm_id' },
    columns = {
        { column = 'name', type = 'text' },
        { column = 'network', type = 'text' },
        { column = 'ref', type = 'text' },
        { column = 'tags', type = 'jsonb' },
        { column = 'geom', type = 'multilinestring', projection = SRID, not_null = true },
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

-- Bicycle shops, generic outlets advertising repair (service:bicycle:repair=yes)
-- and public self-service repair stations (amenity=bicycle_repair_station: pump +
-- tools); the repair flag is preserved in the raw tags for the read side.
local function bike_shop_category(tags)
    if tags.shop == 'bicycle' then return 'bicycle' end
    if tags['service:bicycle:repair'] == 'yes' then return 'repair_station' end
    if tags.amenity == 'bicycle_repair_station' then return 'repair_station' end
    return nil
end

-- Health services: pharmacies, hospitals and clinics.
local function health_category(tags)
    if tags.amenity == 'pharmacy' or tags.amenity == 'hospital' or tags.amenity == 'clinic' then
        return tags.amenity
    end
    return nil
end

-- Mainline railway stations (excludes heritage/tourist railways via usage).
local function railway_category(tags)
    if tags.railway == 'station' and tags.usage ~= 'tourism' then
        return 'station'
    end
    return nil
end

-- E-bike charging stations (amenity=charging_station).
local function charging_category(tags)
    if tags.amenity == 'charging_station' then return 'charging_station' end
    return nil
end

-- Cultural POIs: the stored category is the resolved type (tourism or historic
-- value), matching OsmCulturalPoiSource's expectations.
local function cultural_category(tags)
    if tags.tourism and CULTURAL_TOURISM[tags.tourism] then return tags.tourism end
    if tags.historic and CULTURAL_HISTORIC[tags.historic] then return tags.historic end
    return nil
end

-- True for the highway ways imported into the ways table (linestrings).
local function way_highway(tags)
    return tags.highway ~= nil and WAY_HIGHWAY[tags.highway] == true
end

-- True for country-level administrative boundary relations.
local function is_country_boundary(tags)
    return tags.boundary == 'administrative' and tags.admin_level == '2'
end

-- True for signed cycle route relations (EuroVelo / national / regional / local).
local function is_cycle_route(tags)
    return tags.type == 'route' and tags.route == 'bicycle'
end

-- True for ferry crossing ways (route=ferry).
local function is_ferry(tags)
    return tags.route == 'ferry'
end

local function is_relevant(tags)
    return poi_category(tags) ~= nil
        or accommodation_category(tags) ~= nil
        or water_category(tags) ~= nil
        or bike_shop_category(tags) ~= nil
        or health_category(tags) ~= nil
        or railway_category(tags) ~= nil
        or charging_category(tags) ~= nil
        or cultural_category(tags) ~= nil
        or way_highway(tags)
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

    local rcat = railway_category(tags)
    if rcat then
        railway_stations:insert({
            name = tags.name,
            category = rcat,
            tags = tags,
            geom = geom,
        })
    end

    local ccat = charging_category(tags)
    if ccat then
        charging_stations:insert({
            name = tags.name,
            category = ccat,
            tags = tags,
            geom = geom,
        })
    end

    local cultcat = cultural_category(tags)
    if cultcat then
        cultural_pois:insert({
            name = tags.name,
            category = cultcat,
            wikidata = tags.wikidata,
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
    -- Ferry crossings are not highways/POIs (is_relevant skips them); handle first.
    if is_ferry(object.tags) then
        local ok, line = pcall(function() return object:as_linestring() end)
        if ok and line ~= nil then
            ferries:insert({ name = object.tags.name, tags = object.tags, geom = line })
        end

        return
    end

    if not is_relevant(object.tags) then return end

    -- The ways table keeps the full linestring for surface/traffic analysis.
    -- Highway ways are never a mapped POI category, so skip the centroid +
    -- insert_features work (a no-op for them) on country-sized extracts.
    if way_highway(object.tags) then
        local ok, line = pcall(function() return object:as_linestring() end)
        if ok and line ~= nil then
            ways:insert({ tags = object.tags, geom = line })
        end
        return
    end

    -- Point features (POI/accommodation/...) use the way centroid.
    local geom = way_centroid(object)
    if geom == nil then return end
    insert_features(object.tags, geom)
end

function osm2pgsql.process_relation(object)
    if is_country_boundary(object.tags) then
        -- as_multipolygon() builds the area from the boundary's member ways; a
        -- broken/incomplete relation yields nil and is skipped.
        local ok, geom = pcall(function() return object:as_multipolygon() end)
        if not ok or geom == nil then return end

        admin_boundaries:insert({
            name = object.tags.name,
            admin_level = to_int(object.tags.admin_level),
            tags = object.tags,
            geom = geom,
        })
        return
    end

    if is_cycle_route(object.tags) then
        -- as_multilinestring() stitches the route's member ways; a broken
        -- relation yields nil and is skipped.
        local ok, geom = pcall(function() return object:as_multilinestring() end)
        if not ok or geom == nil then return end

        cycle_routes:insert({
            name = object.tags.name,
            network = object.tags.network,
            ref = object.tags.ref,
            tags = object.tags,
            geom = geom,
        })
    end
end
