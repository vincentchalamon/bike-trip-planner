<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\TripRequest;
use App\Message\GenerateStages;
use App\Service\TripAnalysisDispatcher;
use App\Service\TripBootstrapper;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerateStagesHandler extends AbstractTripMessageHandler
{
    public function __construct(
        TripHandlerContext $context,
        private TripBootstrapper $bootstrapper,
        private TripAnalysisDispatcher $analysisDispatcher,
    ) {
        parent::__construct($context);
    }

    public function __invoke(GenerateStages $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $request = $this->tripRequestRepository->getRequest($tripId);

        if (!$request instanceof TripRequest) {
            return;
        }

        $this->executeWithTracking($message, function () use ($tripId, $request, $generation): void {
            // The stage write bumped the version, so the enrichments carry the generation it
            // produced. The message's own is one below it now, and the staleness guard would
            // drop every enrichment stamped with it (ADR-073).
            $written = $this->bootstrapper->storeStages($tripId, $request)['generation'];

            $this->analysisDispatcher->dispatch($tripId, $request, $written ?? $generation);
        });
    }
}
