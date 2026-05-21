<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the in-ride payload columns to `trip_chat_message` (#465, sprint 32).
 *
 * The base table created in #458 only stored the conversational turn. The
 * in-ride pipeline (PR #474) also persists the rider's GPS position and the
 * structured POI suggestions so {@see \App\State\TripChatHistoryProvider} can
 * rehydrate the PoiCard rendering on a page reload.
 */
final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add geo + pois columns to trip_chat_message for in-ride persistence (#465)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE trip_chat_message
                ADD geo_lat DOUBLE PRECISION DEFAULT NULL,
                ADD geo_lon DOUBLE PRECISION DEFAULT NULL,
                ADD pois JSONB DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE trip_chat_message
                DROP geo_lat,
                DROP geo_lon,
                DROP pois
            SQL);
    }
}
