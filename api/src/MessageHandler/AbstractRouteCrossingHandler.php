<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertPayload;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\AlertGroup;
use App\Mercure\MercureEventType;
use App\Message\TracksComputation;

/**
 * Flags the stages whose line crosses a mapped feature: a ferry, a ford.
 *
 * For each ridden stage the feature index is read along the stage geometry (ADR-040), and
 * each feature raises one alert, deduplicated per stage by name (by position when unnamed).
 * Only the lookup and the alert differ between the features, so only those are left to the
 * concrete handler.
 */
abstract readonly class AbstractRouteCrossingHandler extends AbstractTripMessageHandler
{
    /**
     * @param \Closure(list<array{lat: float, lon: float}>): list<array{name: ?string, lat: float, lon: float}> $findNearStage
     * @param \Closure(Stage, array{name: ?string, lat: float, lon: float}): Alert                              $alertFor
     */
    protected function checkCrossings(
        TracksComputation $message,
        AlertGroup $group,
        MercureEventType $event,
        \Closure $findNearStage,
        \Closure $alertFor,
    ): void {
        $tripId = $message->tripId;
        $stages = $this->stageStore->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $this->executeWithTracking($message, function () use ($tripId, $stages, $group, $event, $findNearStage, $alertFor): void {
            $alerts = [];

            foreach ($stages as $stage) {
                if ($stage->isRestDay) {
                    continue;
                }

                $stagePoints = array_map(
                    static fn (Coordinate $c): array => $c->toLatLon(),
                    $stage->geometry,
                );

                /** @var list<string> $seenNames */
                $seenNames = [];
                foreach ($findNearStage($stagePoints) as $crossing) {
                    $key = $crossing['name'] ?? \sprintf('%.5F,%.5F', $crossing['lat'], $crossing['lon']);
                    if (\in_array($key, $seenNames, true)) {
                        continue;
                    }

                    $seenNames[] = $key;
                    $alerts[] = AlertPayload::forStage($stage, $alertFor($stage, $crossing));
                }
            }

            // Same array to the database and to the wire (ADR-068): grouped by the stage
            // it addresses, and without `stageId`/`dayNumber` — the first is the key, the
            // second is renumbered by every structural edit and is derived on read.
            $this->stageStore->updateTripAlertsForGroup($tripId, $group, $this->groupByStage($alerts));

            $this->publisher->publish($tripId, $event, [
                'alerts' => $this->renderForWire($tripId, $alerts),
            ]);
        });
    }
}
