<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * Builds the SQL expression recording per-table completeness ratios in the
 * provisioning metadata (issue #877).
 *
 * The importers only ever counted rows, never their quality, so no data-quality
 * decision was verifiable over time. This produces, per table, the share of rows
 * carrying a name, an exploitable link and opening hours — plus, where asked, the
 * same breakdown per `category`, which is what arbitrates excluding unnamed
 * entries category by category.
 *
 * Emitted as a single `jsonb_build_object(...)` expression, so the metrics ride in
 * the same `CREATE TABLE ... AS SELECT` as the row counts and go live with the
 * atomic schema swap. Each measured table costs one extra sequential scan (two
 * when a per-category breakdown is asked), which is why callers leave out the
 * tables that carry nothing to measure — `osm.ways` above all, by far the largest.
 *
 * Presence is `nullif(btrim(col), '') IS NOT NULL`: an empty or blank string is a
 * missing value, not a value. Metric columns must therefore be text columns.
 */
final readonly class CompletenessMetrics
{
    public function __construct(private string $schema)
    {
    }

    /**
     * @param array<string, array<string, string>> $tables     table => metric key => text column whose presence is measured
     * @param list<string>                         $byCategory tables that additionally get the same metrics grouped by `category`
     */
    public function expression(array $tables, array $byCategory = []): string
    {
        $parts = [];
        foreach ($tables as $table => $metrics) {
            $parts[] = \sprintf("'%s', %s", $table, $this->tableExpression($table, $metrics, \in_array($table, $byCategory, true)));
        }

        return \sprintf('jsonb_build_object(%s)', implode(', ', $parts));
    }

    /**
     * @param array<string, string> $metrics
     */
    private function tableExpression(string $table, array $metrics, bool $byCategory): string
    {
        $expression = \sprintf(
            '(SELECT %s FROM %s.%s)',
            $this->metricsObject($metrics),
            $this->schema,
            $table,
        );

        if (!$byCategory) {
            return $expression;
        }

        // A separate scalar subquery merged with `||` rather than a subquery
        // nested in the aggregate select list: both stay uncorrelated and
        // readable, and an empty table yields `{}` instead of NULL.
        return \sprintf(
            "%s || jsonb_build_object('by_category', coalesce((SELECT jsonb_object_agg(category, metrics) FROM (SELECT category, %s AS metrics FROM %s.%s GROUP BY category) grouped), '{}'::jsonb))",
            $expression,
            $this->metricsObject($metrics),
            $this->schema,
            $table,
        );
    }

    /**
     * @param array<string, string> $metrics
     */
    private function metricsObject(array $metrics): string
    {
        $parts = ["'rows', count(*)"];
        foreach ($metrics as $key => $column) {
            $present = \sprintf("count(nullif(btrim(%s), ''))", $column);
            $parts[] = \sprintf("'%s', %s", $key, $present);
            $parts[] = \sprintf("'%s_ratio', round(%s::numeric / nullif(count(*), 0), 4)", $key, $present);
        }

        return \sprintf('jsonb_build_object(%s)', implode(', ', $parts));
    }
}
