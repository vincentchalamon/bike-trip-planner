<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Purges `shelter`, `motel` and `rental` from the persisted
 * `trip.enabled_accommodation_types` arrays (issue #927).
 *
 * The three types left TripRequest::ALL_ACCOMMODATION_TYPES, which the
 * `Assert\Choice` on that property validates against: a trip persisted with one
 * of them would fail validation on its next update. A trip left with an empty
 * list would fail the `Assert\Count(min: 1)` just the same, so those fall back to
 * the whole vocabulary rather than to nothing.
 */
final class Version20260805120000 extends AbstractMigration
{
    /**
     * Kept as a literal rather than read from TripRequest: a migration is a
     * historical record and must not change meaning when the constant does.
     */
    private const string REMAINING_TYPES = '{camp_site,hostel,alpine_hut,chalet,guest_house,hotel,wilderness_hut}';

    public function getDescription(): string
    {
        return 'Remove the shelter, motel and rental types from the persisted accommodation filters';
    }

    public function up(Schema $schema): void
    {
        // array_remove rather than `unnest ... EXCEPT`: the set operation would
        // reorder the array and collapse it to distinct values as a side effect.
        $this->addSql(<<<'SQL'
            UPDATE trip
            SET enabled_accommodation_types = array_remove(
                array_remove(array_remove(enabled_accommodation_types, 'shelter'), 'motel'),
                'rental'
            )
            WHERE enabled_accommodation_types && ARRAY['shelter', 'motel', 'rental']
            SQL);

        $this->addSql(\sprintf(
            "UPDATE trip SET enabled_accommodation_types = '%s' WHERE cardinality(enabled_accommodation_types) = 0",
            self::REMAINING_TYPES,
        ));
    }

    public function down(Schema $schema): void
    {
        // The removed values cannot be restored: which trips had opted into
        // `shelter`, `motel` or `rental` is not recorded anywhere else.
        $this->throwIrreversibleMigrationException();
    }
}
