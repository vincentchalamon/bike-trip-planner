<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Stage;
use App\Enum\AlertCode;
use App\Enum\AlertGroup;
use App\Enum\AlertType;
use App\Mercure\MercureEventType;
use App\Message\CheckFerries;
use App\Osm\FerryRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Flags stages whose route takes a ferry crossing.
 *
 * For each stage, looks up the local osm.ferries lines (route=ferry) running
 * within a tolerance of the stage geometry (ADR-040). A ferry on a stage emits a
 * warning — ferries run on schedules, may require booking and can block progress.
 * Deduplicates per stage by ferry name.
 */
#[AsMessageHandler]
final readonly class CheckFerriesHandler extends AbstractRouteCrossingHandler
{
    /** Max distance (m) between the stage line and a ferry line to count the stage as taking it. */
    private const int FERRY_TOLERANCE_METERS = 100;

    public function __construct(
        TripHandlerContext $context,
        private FerryRepositoryInterface $ferryRepository,
    ) {
        parent::__construct($context);
    }

    public function __invoke(CheckFerries $message): void
    {
        $this->checkCrossings(
            $message,
            AlertGroup::FERRY,
            MercureEventType::FERRY_ALERTS,
            fn (array $stagePoints): array => $this->ferryRepository->findNearStage($stagePoints, self::FERRY_TOLERANCE_METERS),
            static fn (Stage $stage, array $ferry): Alert => new Alert(
                code: AlertCode::FERRY_CROSSING,
                type: AlertType::WARNING,
                messageKey: 'alert.ferry.warning',
                lat: $ferry['lat'],
                lon: $ferry['lon'],
                action: new AlertAction(AlertActionKind::NAVIGATE, 'alert.ferry.action', ['lat' => $ferry['lat'], 'lon' => $ferry['lon']]),
            ),
        );
    }
}
