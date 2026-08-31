-- Public (PG-app) baseline schema for Bike Trip Planner.
--
-- Split out of the former single baseline_schema.sql by the PG split (ADR-060):
-- this is the per-stack application database — trips, stages, auth — owned and
-- migrated on boot by the API (MIGRATIONS_ON_BOOT). It has NO PostGIS dependency
-- (stage geometry is stored as jsonb) and no osm/tourism/provisioner schema; those
-- live on the shared read-only PG-référence (reference_schema.sql).
--
-- Executed verbatim by Version20260807130000. Do NOT hand-edit for schema drift;
-- add a follow-up migration instead.

--
-- Name: access_request; Type: TABLE; Schema: public
--

CREATE TABLE public.access_request (
    id uuid NOT NULL,
    email character varying(180) NOT NULL,
    ip character varying(45) NOT NULL,
    status character varying(32) NOT NULL,
    verified_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    created_at timestamp(0) without time zone NOT NULL
);

COMMENT ON COLUMN public.access_request.id IS '(DC2Type:uuid)';
COMMENT ON COLUMN public.access_request.verified_at IS '(DC2Type:datetime_immutable)';
COMMENT ON COLUMN public.access_request.created_at IS '(DC2Type:datetime_immutable)';

--
-- Name: email_change_token; Type: TABLE; Schema: public
--

CREATE TABLE public.email_change_token (
    id uuid NOT NULL,
    token character varying(128) NOT NULL,
    new_email character varying(180) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    consumed_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    created_at timestamp(0) without time zone NOT NULL,
    user_id uuid NOT NULL
);

COMMENT ON COLUMN public.email_change_token.expires_at IS '(DC2Type:datetime_immutable)';
COMMENT ON COLUMN public.email_change_token.consumed_at IS '(DC2Type:datetime_immutable)';
COMMENT ON COLUMN public.email_change_token.created_at IS '(DC2Type:datetime_immutable)';

--
-- Name: magic_link; Type: TABLE; Schema: public
--

CREATE TABLE public.magic_link (
    id uuid NOT NULL,
    token character varying(128) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    consumed_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    created_at timestamp(0) without time zone NOT NULL,
    user_id uuid NOT NULL
);

COMMENT ON COLUMN public.magic_link.expires_at IS '(DC2Type:datetime_immutable)';
COMMENT ON COLUMN public.magic_link.consumed_at IS '(DC2Type:datetime_immutable)';
COMMENT ON COLUMN public.magic_link.created_at IS '(DC2Type:datetime_immutable)';

--
-- Name: refresh_token; Type: TABLE; Schema: public
--

CREATE TABLE public.refresh_token (
    id uuid NOT NULL,
    token text NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    user_id uuid NOT NULL,
    replaced_by_token character varying(64) DEFAULT NULL::character varying,
    token_digest character varying(64) NOT NULL
);

COMMENT ON COLUMN public.refresh_token.expires_at IS '(DC2Type:datetime_immutable)';
COMMENT ON COLUMN public.refresh_token.created_at IS '(DC2Type:datetime_immutable)';

--
-- Name: stage; Type: TABLE; Schema: public
--

CREATE TABLE public.stage (
    id uuid NOT NULL,
    "position" integer NOT NULL,
    day_number integer NOT NULL,
    distance double precision NOT NULL,
    elevation double precision NOT NULL,
    elevation_loss double precision NOT NULL,
    start_lat double precision NOT NULL,
    start_lon double precision NOT NULL,
    start_ele double precision NOT NULL,
    end_lat double precision NOT NULL,
    end_lon double precision NOT NULL,
    end_ele double precision NOT NULL,
    geometry jsonb NOT NULL,
    label character varying(255) DEFAULT NULL::character varying,
    is_rest_day boolean NOT NULL,
    weather jsonb,
    alerts jsonb NOT NULL,
    pois jsonb NOT NULL,
    accommodations jsonb NOT NULL,
    selected_accommodation jsonb,
    trip_id uuid NOT NULL,
    on_cycle_network double precision DEFAULT 0 NOT NULL,
    start_label character varying(255) DEFAULT NULL::character varying,
    end_label character varying(255) DEFAULT NULL::character varying
);

--
-- Name: trip; Type: TABLE; Schema: public
--

CREATE TABLE public.trip (
    id uuid NOT NULL,
    source_url character varying(2048) DEFAULT NULL::character varying,
    title character varying(255) DEFAULT NULL::character varying,
    start_date date,
    end_date date,
    fatigue_factor double precision NOT NULL,
    elevation_penalty double precision NOT NULL,
    ebike_mode boolean NOT NULL,
    departure_hour integer NOT NULL,
    max_distance_per_day double precision NOT NULL,
    average_speed double precision NOT NULL,
    enabled_accommodation_types text[] NOT NULL,
    source_type character varying(50) DEFAULT NULL::character varying,
    locale character varying(5) NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    updated_at timestamp(0) without time zone NOT NULL,
    user_id uuid,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    out_of_zone boolean DEFAULT false NOT NULL
);

--
-- Name: trip_share; Type: TABLE; Schema: public
--

CREATE TABLE public.trip_share (
    id uuid NOT NULL,
    trip_id uuid NOT NULL,
    token character varying(64) NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    deleted_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone,
    short_code character varying(8) DEFAULT ''::character varying NOT NULL
);

COMMENT ON COLUMN public.trip_share.created_at IS '(DC2Type:datetime_immutable)';

--
-- Name: user; Type: TABLE; Schema: public
--

CREATE TABLE public."user" (
    id uuid NOT NULL,
    email character varying(180) NOT NULL,
    roles json NOT NULL,
    created_at timestamp(0) without time zone NOT NULL,
    locale character varying(5) DEFAULT 'fr'::character varying NOT NULL,
    deleted_at timestamp(0) without time zone DEFAULT NULL::timestamp without time zone
);

COMMENT ON COLUMN public."user".created_at IS '(DC2Type:datetime_immutable)';
COMMENT ON COLUMN public."user".deleted_at IS '(DC2Type:datetime_immutable)';

--
-- Primary keys
--

ALTER TABLE ONLY public.access_request ADD CONSTRAINT access_request_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.email_change_token ADD CONSTRAINT email_change_token_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.magic_link ADD CONSTRAINT magic_link_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.refresh_token ADD CONSTRAINT refresh_token_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.stage ADD CONSTRAINT stage_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.trip ADD CONSTRAINT trip_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.trip_share ADD CONSTRAINT trip_share_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public."user" ADD CONSTRAINT user_pkey PRIMARY KEY (id);

--
-- Indexes
--

CREATE INDEX idx_access_request_status ON public.access_request USING btree (status);
CREATE INDEX idx_email_change_token_user_expires ON public.email_change_token USING btree (user_id, expires_at);
CREATE INDEX idx_magic_link_user_expires ON public.magic_link USING btree (user_id, expires_at);
CREATE INDEX idx_refresh_token_user ON public.refresh_token USING btree (user_id);
CREATE INDEX idx_stage_trip_position ON public.stage USING btree (trip_id, "position");
CREATE INDEX idx_trip_share_trip ON public.trip_share USING btree (trip_id);
CREATE INDEX idx_trip_user ON public.trip USING btree (user_id);
CREATE UNIQUE INDEX uniq_access_request_email ON public.access_request USING btree (email);
CREATE UNIQUE INDEX uniq_email_change_token_token ON public.email_change_token USING btree (token);
CREATE UNIQUE INDEX uniq_magic_link_token ON public.magic_link USING btree (token);
CREATE UNIQUE INDEX uniq_refresh_token_digest ON public.refresh_token USING btree (token_digest);
CREATE UNIQUE INDEX uniq_trip_share_active ON public.trip_share USING btree (trip_id) WHERE (deleted_at IS NULL);
CREATE UNIQUE INDEX uniq_trip_share_short_code ON public.trip_share USING btree (short_code);
CREATE UNIQUE INDEX uniq_trip_share_token ON public.trip_share USING btree (token);
CREATE UNIQUE INDEX uniq_user_email ON public."user" USING btree (email);

--
-- Foreign keys
--

ALTER TABLE ONLY public.stage ADD CONSTRAINT fk_c27c9369a5bc2e0e FOREIGN KEY (trip_id) REFERENCES public.trip(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.email_change_token ADD CONSTRAINT fk_email_change_token_user FOREIGN KEY (user_id) REFERENCES public."user"(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.magic_link ADD CONSTRAINT fk_magic_link_user FOREIGN KEY (user_id) REFERENCES public."user"(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.refresh_token ADD CONSTRAINT fk_refresh_token_user FOREIGN KEY (user_id) REFERENCES public."user"(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.trip_share ADD CONSTRAINT fk_trip_share_trip FOREIGN KEY (trip_id) REFERENCES public.trip(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.trip ADD CONSTRAINT fk_trip_user FOREIGN KEY (user_id) REFERENCES public."user"(id) ON DELETE CASCADE;
