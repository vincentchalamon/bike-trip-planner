<?php

declare(strict_types=1);

namespace App\Alert;

use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Stage;

/**
 * The one place a typed {@see Alert} becomes the array that is persisted and published.
 *
 * Every producer goes through it, so the database and the wire read the same shape (ADR-068)
 * whichever rule raised the alert, and an action the frontend does not wire is dropped for
 * all of them alike (issue #397, {@see AlertAction::toDeliverablePayload()}).
 */
final class AlertPayload
{
    /**
     * @param array<string, mixed> $extra producer-specific fields carried verbatim after the
     *                                    common ones (a cultural POI's name, opening hours...);
     *                                    they never override a common field
     *
     * @return array<string, mixed>
     */
    public static function of(Alert $alert, array $extra = []): array
    {
        $payload = [
            'code' => $alert->code?->value,
            'type' => $alert->type->value,
            'messageKey' => $alert->messageKey,
            'parameters' => $alert->parameters,
            'parameterFormats' => $alert->parameterFormats,
            'lat' => $alert->lat,
            'lon' => $alert->lon,
        ];

        $action = $alert->action?->toDeliverablePayload();
        if (null !== $action) {
            $payload['action'] = $action;
        }

        return $payload + $extra;
    }

    /**
     * The same payload for a flat, trip-wide list, where each entry names the stage it sits
     * on. Both fields are dropped again before persisting ({@see \App\MessageHandler\AbstractTripMessageHandler::groupByStage()}).
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function forStage(Stage $stage, Alert $alert, array $extra = []): array
    {
        return ['stageId' => $stage->id, 'dayNumber' => $stage->dayNumber] + self::of($alert, $extra);
    }
}
