<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where one computation of a trip stands (ADR-074).
 *
 * The vocabulary existed before this enum did — as bare strings in fourteen files. Adding
 * `superseded` in ADR-073 meant making five of them agree by hand, which is the argument for
 * declaring it once.
 *
 * **The map itself is still `array<string, string>`** at the cache, at the mirrored column, on
 * the wire and in the two DTOs. Carrying the enum end to end would move three Mercure payloads,
 * `TripDetail::$categoryStatus`, the JSONB column of ADR-072 and both clients; that is a change
 * of its own. What this buys today is one place to add a sixth value, and a name at every
 * comparison instead of a literal.
 */
enum ComputationStatus: string
{
    /** Initialized, no worker has picked it up. */
    case PENDING = 'pending';

    /** A worker is on it. */
    case RUNNING = 'running';

    case DONE = 'done';

    /** Terminal: its retries were exhausted ({@see \App\EventListener\ComputationFailureSubscriber}). */
    case FAILED = 'failed';

    /**
     * Terminal, and not a failure: the trip moved past it before it settled, and nothing will
     * re-run it (ADR-073).
     */
    case SUPERSEDED = 'superseded';

    /**
     * True once nothing more will happen to this computation.
     *
     * The completion gate counts these against the total; `pending` and `running` are what
     * hold it open.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::PENDING, self::RUNNING => false,
            self::DONE, self::FAILED, self::SUPERSEDED => true,
        };
    }

    /**
     * A constant, not a method: the two DTOs enumerate these in an `#[ApiProperty]`, and an
     * attribute argument has to be a constant expression. Same shape as
     * {@see WeatherAvailability::VALUES}.
     *
     * @var list<string>
     */
    public const array VALUES = [
        self::PENDING->value,
        self::RUNNING->value,
        self::DONE->value,
        self::FAILED->value,
        self::SUPERSEDED->value,
    ];
}
