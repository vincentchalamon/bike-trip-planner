<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Why a stage has no forecast (ADR-072).
 *
 * The weather computation succeeding and a stage having a forecast are different things: a
 * stage twenty days out has no forecast, and the computation that established this succeeded.
 * That is why this is a property of the stage and not a computation status — ADR-068 framed
 * it the other way round.
 *
 * Five distinct causes used to collapse into a bare `null`, so a client could not tell a trip
 * scheduled too far ahead from one whose provider had failed. Absent means the forecast is
 * there — or, for {@see self::UNAVAILABLE} alone, that the weather block has not settled yet
 * and the stage has no forecast *yet*; `weatherStatus` carries that distinction. The two
 * calendar answers need no computation to be true, so they do not wait for one.
 */
enum WeatherAvailability: string
{
    /**
     * How far ahead the provider forecasts, in days (Open-Meteo).
     *
     * Lives here rather than in the fetcher because the read path decides the same question:
     * whether a stage is close enough to have a forecast at all.
     */
    public const int HORIZON_DAYS = 16;

    /** The stage is behind us. Nothing will change that but new dates. */
    case PAST = 'past';

    /** Further out than the provider forecasts (16 days). Worth asking again later. */
    case BEYOND_HORIZON = 'beyond_horizon';

    /**
     * The provider returned nothing, the batch fetch failed, or the riding window fell
     * outside the hours covered. Unlike the two above, a recomputation may well fix it.
     */
    case UNAVAILABLE = 'unavailable';

    /**
     * Why this stage has no forecast, or null when the question does not arise.
     *
     * Derived at read time and never stored: "too far ahead" is a statement about today, and
     * a stage twenty days out is ten days out next week. A stored answer would be wrong by
     * the time anyone read it.
     */
    public static function forStage(?\DateTimeImmutable $stageDate, \DateTimeImmutable $today): ?self
    {
        if (!$stageDate instanceof \DateTimeImmutable) {
            return null;
        }

        if ($stageDate < $today) {
            return self::PAST;
        }

        return $stageDate > $today->modify(\sprintf('+%d days', self::HORIZON_DAYS))
            ? self::BEYOND_HORIZON
            : self::UNAVAILABLE;
    }

    /** @var list<string> */
    public const array VALUES = [
        self::PAST->value,
        self::BEYOND_HORIZON->value,
        self::UNAVAILABLE->value,
    ];
}
