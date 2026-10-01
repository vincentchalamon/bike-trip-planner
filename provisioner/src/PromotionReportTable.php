<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * Per-run, per-source promotion detail every promotion writes ({@see ZonePromotion},
 * {@see EventsPromotion}) and {@see PromotionReport} reads back: how many rows the staging
 * schema offered and how many were new.
 *
 * Lives in the stable `provisioner` schema (the one the Wikidata cache already uses) rather
 * than in a swapped schema, and is created by the provisioner itself so a database whose
 * API migrations have not run still provisions.
 */
final class PromotionReportTable
{
    public const string NAME = 'provisioner.promotion_report';

    /**
     * Safe to run on every pass.
     */
    public static function ddl(): string
    {
        return \sprintf(
            'CREATE SCHEMA IF NOT EXISTS provisioner; CREATE TABLE IF NOT EXISTS %s (source text NOT NULL, zone text NOT NULL, table_name text NOT NULL, candidates bigint NOT NULL, inserted bigint NOT NULL, promoted_at timestamptz NOT NULL, PRIMARY KEY (source, zone, table_name));',
            self::NAME,
        );
    }
}
