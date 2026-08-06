<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Import-time completeness, carried by the schema (ADR-049 §3, issue #884).
 *
 * An unnamed accommodation used to be dropped at **read** time, by an identical `continue`
 * in two accommodation sources. Three things were wrong with that: the rows crossed the
 * network to be thrown away, the decision lived in two places, and nothing had ever tried
 * to *complete* the name before giving up. The decision moves to the import: the
 * provisioner resolves what it can and refuses the rest, and this constraint is what makes
 * a gate bug a loud failure instead of a quietly thinned result set.
 *
 * **Per category, because generalising would do damage.** You do not book a place you
 * cannot name — so every bookable category requires one. A water point, a ford or a ferry
 * is actionable from its coordinates alone, and an anonymous bakery is still a bakery, so
 * `water_points`, `fords`, `ferries`, `pois` and `cultural_pois` get no constraint at all.
 *
 * `shelter` is the one exemption, and the measurement in #878 is what arbitrates it: over
 * 8 062 shelters, a constraint on `name` would drop 429 relevant ones and keep 2 516 named
 * bus shelters, because `shelter_type` — not the name — is what separates a mountain
 * refuge from street furniture. `wilderness_hut` gets **no** exemption (20 unnamed of 316,
 * 6,3 %, the level of `guest_house`), nor does `alpine_hut` (2,8 %).
 *
 * **This migration deletes rows, and that needs saying plainly.** The constraint is added
 * `VALID`, so the rows already imported that violate it have to go. They are exactly the
 * rows the read filter has always skipped, so nothing the application ever served is lost
 * — `App\Osm\AccommodationRepository` separately excludes `shelter` from lodging results,
 * so the exempted category is unaffected either way. `down()` cannot restore them; the
 * next `make provision <zone>` re-imports whatever the gate now accepts.
 */
final class Version20260806120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the per-category completeness constraints and the place-enrichment cache';
    }

    public function up(Schema $schema): void
    {
        // The persistent name-resolution cache, sibling of provisioner.wikidata_cache in
        // the same stable schema: never in a promoted schema, so re-opening a zone is
        // cheap and a rejected row is remembered with the resolver version that rejected
        // it. `resolver_version` lives here rather than on the live tables or the registry
        // (ADR-049 §4): the anti-join against live stays purely identity-based, and
        // retroactivity is carried where it costs least.
        $this->addSql('CREATE SCHEMA IF NOT EXISTS provisioner');
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS provisioner.place_enrichment (
                source text NOT NULL,
                source_id text NOT NULL,
                payload jsonb NOT NULL,
                status text NOT NULL,
                resolver_version integer NOT NULL,
                fetched_at timestamptz NOT NULL,
                PRIMARY KEY (source, source_id)
            )
            SQL);
        // The retry scan reads (status, resolver_version) to find what a newer resolver
        // should reconsider; without it that is a full scan of the cache per zone.
        $this->addSql('CREATE INDEX IF NOT EXISTS place_enrichment_status_version_idx ON provisioner.place_enrichment (status, resolver_version)');

        // Rows the read path has always skipped. Deleted so the constraint can be VALID:
        // a NOT VALID constraint would leave them in place and, with the read filters now
        // gone, they would start being served — the exact regression the filters existed
        // to prevent.
        $this->addSql("DELETE FROM osm.accommodations WHERE category <> 'shelter' AND nullif(btrim(name), '') IS NULL");
        $this->addSql("DELETE FROM tourism.accommodations WHERE nullif(btrim(name), '') IS NULL");

        $this->addSql(<<<'SQL'
            ALTER TABLE osm.accommodations
                ADD CONSTRAINT accommodations_named_unless_shelter
                CHECK (category = 'shelter' OR nullif(btrim(name), '') IS NOT NULL)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE tourism.accommodations
                ADD CONSTRAINT accommodations_named
                CHECK (nullif(btrim(name), '') IS NOT NULL)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // The deleted rows are not restorable here; a provisioning run re-imports what the
        // gate accepts.
        $this->addSql('ALTER TABLE tourism.accommodations DROP CONSTRAINT IF EXISTS accommodations_named');
        $this->addSql('ALTER TABLE osm.accommodations DROP CONSTRAINT IF EXISTS accommodations_named_unless_shelter');
        $this->addSql('DROP TABLE IF EXISTS provisioner.place_enrichment');
    }
}
