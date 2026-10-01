<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\ComputationName;
use App\Message\ScanAccommodations;
use App\Service\EnrichmentMessageFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnrichmentMessageFactoryTest extends TestCase
{
    /**
     * Every computation an edit can invalidate has a message to carry it, and that message
     * tracks the very computation it was built for.
     */
    #[Test]
    public function buildsTheMessageEachDispatchableComputationDeclares(): void
    {
        $factory = new EnrichmentMessageFactory();

        foreach (ComputationName::cases() as $computation) {
            if ([] === $computation->triggers()) {
                continue;
            }

            $message = $factory->create($computation, 'trip-1', 7);

            self::assertSame($computation, $message::computation(), $computation->value);
            self::assertSame('trip-1', $message->tripId);
            self::assertSame(7, $message->generation);
        }
    }

    #[Test]
    public function refusesAComputationThatIsNotDispatchedOnItsOwn(): void
    {
        $factory = new EnrichmentMessageFactory();

        $refused = [];
        foreach (ComputationName::cases() as $computation) {
            if ([] !== $computation->triggers()) {
                continue;
            }

            try {
                $factory->create($computation, 'trip-1');
            } catch (\LogicException) {
                $refused[] = $computation;
            }
        }

        self::assertSame(
            [ComputationName::ROUTE, ComputationName::STAGES, ComputationName::WIND, ComputationName::ROUTE_SEGMENT, ComputationName::FORDS],
            $refused,
        );
    }

    #[Test]
    public function carriesTheEnabledAccommodationTypes(): void
    {
        $message = new EnrichmentMessageFactory()->create(ComputationName::ACCOMMODATIONS, 'trip-1', 3, ['hotel']);

        self::assertInstanceOf(ScanAccommodations::class, $message);
        self::assertSame(['hotel'], $message->enabledAccommodationTypes);
        self::assertSame(3, $message->generation);
        self::assertNull($message->stageId);
    }
}
