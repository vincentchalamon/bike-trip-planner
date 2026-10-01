<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Alert\AlertPayload;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\Enum\AlertGroup;
use App\Enum\AlertParameterFormat;
use App\Enum\AlertType;
use App\Mercure\MercureEventType;
use App\Message\AnalyzeWind;
use App\Weather\WeatherStageRule;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Raises the weather alerts of each stage, one per {@see WeatherStageRule} it trips.
 */
#[AsMessageHandler]
final readonly class AnalyzeWindHandler extends AbstractTripMessageHandler
{
    public function __invoke(AnalyzeWind $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->stageStore->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $this->executeWithTracking($message, function () use ($tripId, $stages): void {
            $alerts = [];

            foreach (WeatherStageRule::cases() as $rule) {
                $parameters = $rule->parameters();

                foreach ($rule->stagesRaising($stages) as $stage) {
                    $alerts[] = AlertPayload::forStage($stage, new Alert(
                        code: $rule->code(),
                        type: AlertType::WARNING,
                        messageKey: $rule->messageKey(),
                        parameters: $parameters,
                        parameterFormats: array_map(
                            static fn (): string => AlertParameterFormat::DECIMAL->value,
                            $parameters,
                        ),
                        action: new AlertAction(AlertActionKind::DISMISS, WeatherStageRule::ACTION_LABEL_KEY),
                    ));
                }
            }

            // Same array to the database and to the wire (ADR-068): grouped by the stage
            // it addresses, and without `stageId`/`dayNumber` — the first is the key, the
            // second is renumbered by every structural edit and is derived on read.
            $this->stageStore->updateTripAlertsForGroup($tripId, AlertGroup::WIND, $this->groupByStage($alerts));

            $this->publisher->publish($tripId, MercureEventType::WIND_ALERTS, [
                'alerts' => $this->renderForWire($tripId, $alerts),
            ]);
        });
    }
}
