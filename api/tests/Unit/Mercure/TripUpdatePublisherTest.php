<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mercure;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Enum\ComputationName;
use App\EventListener\RequestIdListener;
use App\Logger\CorrelationIdProcessor;
use App\Mercure\CurrentCorrelationIdProvider;
use App\Mercure\MercureEventType;
use App\Mercure\StagePayloadMapper;
use App\Mercure\TripUpdatePublisher;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[AllowMockObjectsWithoutExpectations]
final class TripUpdatePublisherTest extends TestCase
{
    private const string TRIP_ID = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    #[Test]
    public function publishesComputationStepCompletedWithStepCategoryAndProgressCounters(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                /** @var array{type: string, data: array<string, mixed>} $decoded */
                $decoded = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame(MercureEventType::COMPUTATION_STEP_COMPLETED->value, $decoded['type']);
                self::assertSame('terrain', $decoded['data']['step']);
                self::assertSame('terrain_security', $decoded['data']['category']);
                self::assertSame(5, $decoded['data']['completed']);
                self::assertSame(2, $decoded['data']['failed']);
                self::assertSame(9, $decoded['data']['total']);
                self::assertContains(\sprintf('/trips/%s', self::TRIP_ID), $update->getTopics());
                self::assertTrue($update->isPrivate());

                return 'id';
            });

        $publisher = new TripUpdatePublisher($hub, new StagePayloadMapper(), $this->createCorrelationIdProvider());
        $publisher->publishComputationStepCompleted(self::TRIP_ID, ComputationName::TERRAIN, 5, 9, 2);
    }

    #[Test]
    public function publishesTripReadyWithAggregatedStagesAndStatus(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                /** @var array{type: string, data: array{stages: list<array<string, mixed>>, computationStatus: array<string, string>, aiOverview?: ?string}} $decoded */
                $decoded = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame(MercureEventType::TRIP_READY->value, $decoded['type']);
                self::assertCount(2, $decoded['data']['stages']);
                self::assertSame(1, $decoded['data']['stages'][0]['dayNumber']);
                self::assertSame(80.0, $decoded['data']['stages'][0]['distance']);
                self::assertSame(['terrain' => 'done', 'weather' => 'failed'], $decoded['data']['computationStatus']);
                self::assertArrayNotHasKey('aiOverview', $decoded['data']);

                return 'id';
            });

        $publisher = new TripUpdatePublisher($hub, new StagePayloadMapper(), $this->createCorrelationIdProvider());
        $publisher->publishTripReady(self::TRIP_ID, [
            $this->createStage(1),
            $this->createStage(2),
        ], [
            'status' => ['terrain' => 'done', 'weather' => 'failed'],
        ]);
    }

    #[Test]
    public function publishesTripReadyWithOptionalAiOverview(): void
    {
        $aiOverview = [
            'narrative' => 'Sunny ride with moderate climbs.',
            'patterns' => [],
            'recommendations' => [],
            'crossStageAlerts' => [],
            'model' => 'llama3.1:8b',
            'promptVersion' => 1,
            'generatedAt' => '2026-05-07T18:00:00+00:00',
        ];

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update) use ($aiOverview): string {
                /** @var array{type: string, data: array{aiOverview?: array<string, mixed>|null}} $decoded */
                $decoded = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
                self::assertArrayHasKey('aiOverview', $decoded['data']);
                self::assertSame($aiOverview, $decoded['data']['aiOverview']);

                return 'id';
            });

        $publisher = new TripUpdatePublisher($hub, new StagePayloadMapper(), $this->createCorrelationIdProvider());
        $publisher->publishTripReady(self::TRIP_ID, [], [
            'status' => [],
            'aiOverview' => $aiOverview,
        ]);
    }

    #[Test]
    public function publishesStageUpdatedWithSingleStageAndStageIndex(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                /** @var array{type: string, data: array{stageIndex: int, stage: array<string, mixed>}} $decoded */
                $decoded = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame(MercureEventType::STAGE_UPDATED->value, $decoded['type']);
                self::assertSame(2, $decoded['data']['stageIndex']);
                self::assertSame(3, $decoded['data']['stage']['dayNumber']);
                self::assertIsArray($decoded['data']['stage']['geometry']);

                return 'id';
            });

        $publisher = new TripUpdatePublisher($hub, new StagePayloadMapper(), $this->createCorrelationIdProvider());
        $publisher->publishStageUpdated(self::TRIP_ID, $this->createStage(3));
    }

    #[Test]
    public function preservesLegacyEventPublicationContract(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::exactly(3))->method('publish');

        $publisher = new TripUpdatePublisher($hub, new StagePayloadMapper(), $this->createCorrelationIdProvider());
        $publisher->publishValidationError(self::TRIP_ID, 'MIN_STAGES', 'Too few stages.');
        $publisher->publishComputationError(self::TRIP_ID, 'weather', 'API down', retryable: true);
        $publisher->publishTripComplete(self::TRIP_ID, ['terrain' => 'done']);
    }

    private function createCorrelationIdProvider(?string $correlationId = null): CurrentCorrelationIdProvider
    {
        $stack = new RequestStack();
        if (null !== $correlationId) {
            $request = new Request();
            $request->attributes->set(RequestIdListener::ATTRIBUTE, $correlationId);
            $stack->push($request);
        }

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);
        $processor = new CorrelationIdProcessor($stack, $security);

        return new CurrentCorrelationIdProvider($stack, $processor);
    }

    #[Test]
    public function publishesCorrelationIdFromActiveRequest(): void
    {
        $expected = '0193e7c1-1234-7000-9000-abcdef000003';

        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update) use ($expected): string {
                /** @var array{type: string, data: array<string, mixed>, correlationId?: string} $decoded */
                $decoded = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
                self::assertArrayHasKey('correlationId', $decoded);
                self::assertSame($expected, $decoded['correlationId']);

                return 'id';
            });

        $publisher = new TripUpdatePublisher($hub, new StagePayloadMapper(), $this->createCorrelationIdProvider($expected));
        $publisher->publishComputationStepCompleted(self::TRIP_ID, ComputationName::TERRAIN, 1, 2, 0);
    }

    #[Test]
    public function omitsCorrelationIdWhenNoContextAvailable(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (Update $update): string {
                $decoded = json_decode($update->getData(), true, flags: \JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                self::assertArrayNotHasKey('correlationId', $decoded);

                return 'id';
            });

        $publisher = new TripUpdatePublisher($hub, new StagePayloadMapper(), $this->createCorrelationIdProvider());
        $publisher->publishComputationStepCompleted(self::TRIP_ID, ComputationName::TERRAIN, 1, 2, 0);
    }

    private function createStage(int $dayNumber): Stage
    {
        return new Stage(
            tripId: self::TRIP_ID,
            dayNumber: $dayNumber,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0, 100.0),
            endPoint: new Coordinate(48.5, 2.5, 120.0),
            geometry: [new Coordinate(48.0, 2.0, 100.0), new Coordinate(48.5, 2.5, 120.0)],
            label: 'Stage '.$dayNumber,
            elevationLoss: 400.0,
        );
    }
}
