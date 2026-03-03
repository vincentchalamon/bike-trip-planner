<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Alert;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeWind;
use App\Repository\TripRequestRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class AnalyzeWindHandler extends AbstractTripMessageHandler
{
    private const float WIND_SPEED_THRESHOLD_KMH = 25.0;

    private const float HEADWIND_RATIO_THRESHOLD = 0.6; // 60%

    /** @var list<string> */
    private const array HEADWIND_DIRECTIONS = ['N', 'NE', 'NO', 'O']; // Against typical cycling direction

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(AnalyzeWind $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::WIND, function () use ($tripId, $stages, $locale): void {
            $alerts = [];
            $headwindCount = 0;

            foreach ($stages as $stage) {
                if (null === $stage->weather) {
                    continue;
                }

                $weather = $stage->weather;

                if (
                    $weather->windSpeed >= self::WIND_SPEED_THRESHOLD_KMH
                    && \in_array($weather->windDirection, self::HEADWIND_DIRECTIONS, true)
                ) {
                    ++$headwindCount;
                }
            }

            $stagesWithWeather = \count(array_filter($stages, static fn (Stage $s): bool => $s->weather instanceof WeatherForecast));

            if (
                $stagesWithWeather > 0
                && ($headwindCount / $stagesWithWeather) >= self::HEADWIND_RATIO_THRESHOLD
            ) {
                $message = $this->translator->trans(
                    'alert.wind.warning',
                    ['%count%' => $headwindCount, '%total%' => $stagesWithWeather],
                    'alerts',
                    $locale,
                );
                $alert = new Alert(type: AlertType::WARNING, message: $message);
                $alerts[] = ['type' => $alert->type->value, 'message' => $alert->message];
            }

            $this->publisher->publish($tripId, MercureEventType::WIND_ALERTS, [
                'alerts' => $alerts,
            ]);
        });
    }
}
