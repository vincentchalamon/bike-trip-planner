<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Logger\CorrelationIdProcessor;
use App\Mapper\EventArrayMapper;
use App\Mapper\StageArrayMapper;
use App\Mercure\CurrentCorrelationIdProvider;
use App\Mercure\MercureEventType;
use App\Mercure\StagePayloadMapper;
use App\Mercure\TripUpdatePublisher;
use App\MessageHandler\AbstractTripMessageHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Tests\Unit\AlertMessageTestTrait;
use App\Weather\WeatherForecastSerializer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class AbstractTripMessageHandlerTest extends TestCase
{
    use AlertMessageTestTrait;

    private const string TRIP_ID = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';

    private const string SENSITIVE = 'SQLSTATE[42P01]: relation "trip_secret" does not exist at /app/src/Repository/Foo.php:42';

    #[Test]
    public function aFailingComputationPublishesNoInternalMessageButLogsIt(): void
    {
        $published = [];
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(static function (Update $update) use (&$published): string {
            $published[] = $update->getData();

            return 'id';
        });

        $failure = new \RuntimeException(self::SENSITIVE);
        $warnings = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string|\Stringable $message, array $context) use (&$warnings): void {
            $warnings[] = $context;
        });

        $handler = new readonly class ($this->createStub(ComputationTrackerInterface::class), $this->createPublisher($hub), $this->createStub(TripGenerationTrackerInterface::class), $logger, $this->createStub(TripRequestRepositoryInterface::class), $this->createStub(MessageBusInterface::class), $this->createAlertRenderer()) extends AbstractTripMessageHandler {
            public function run(string $tripId, ComputationName $computation, callable $callback): void
            {
                $this->executeWithTracking($tripId, $computation, $callback);
            }
        };

        try {
            $handler->run(self::TRIP_ID, ComputationName::WEATHER, static function () use ($failure): never {
                throw $failure;
            });
            self::fail('The failure must be rethrown for Messenger to retry.');
        } catch (\RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        self::assertCount(1, $published);
        self::assertStringNotContainsString('SQLSTATE', $published[0]);
        self::assertStringNotContainsString('trip_secret', $published[0]);
        self::assertStringNotContainsString('/app/src', $published[0]);

        /** @var array{type: string, data: array<string, mixed>} $decoded */
        $decoded = json_decode($published[0], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(MercureEventType::COMPUTATION_ERROR->value, $decoded['type']);
        self::assertSame(['computation' => 'weather', 'retryable' => true], $decoded['data']);

        self::assertCount(1, $warnings);
        self::assertSame($failure, $warnings[0]['exception'] ?? null, 'The full message must stay in the server log.');
        self::assertSame(self::TRIP_ID, $warnings[0]['tripId'] ?? null);
    }

    private function createPublisher(HubInterface $hub): TripUpdatePublisher
    {
        $versionSource = $this->createStub(TripRequestRepositoryInterface::class);
        $versionSource->method('getVersion')->willReturn(1);

        $stack = new RequestStack();
        $correlationIds = new CurrentCorrelationIdProvider($stack, new CorrelationIdProcessor($stack, $this->createStub(Security::class)));

        return new TripUpdatePublisher(
            $hub,
            new StagePayloadMapper(new StageArrayMapper(new WeatherForecastSerializer(), new EventArrayMapper()), $this->createAlertRenderer()),
            $correlationIds,
            $versionSource,
            new NullLogger(),
        );
    }
}
